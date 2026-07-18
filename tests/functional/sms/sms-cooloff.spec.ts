import { to } from "../timeouts.js";
import { test, expect } from "@playwright/test";
import { mockMeta } from "./smsmock-url.js"
import { login } from "../auth.js";

test.describe("SMS cooloff delay", () => {
  test.beforeEach(async ({ page, request }) => {
    await request.delete(mockMeta("tests/functional/sms/sms-cooloff", "lastPost"));
    await login(page);
  });

  test("immediate send is scheduled with a future scheduleAt, Cancel link, and Datastar polling", async ({
    page,
    request,
  }) => {
    const timestamp = new Date().toISOString().replace(/[:.]/g, "-");
    const message = `Cooloff test ${timestamp}`;

    // 1. Visit Idelette de Bure's person page (personid=3)
    await page.goto("./?view=persons&personid=3");
    await page.waitForLoadState("load");

    // 2. Open the SMS modal via the mobile number dropdown
    const mobileLink = page.locator("#mobile-3");
    await expect(mobileLink).toBeVisible();
    await mobileLink.hover();
    await page.waitForTimeout(50);
    await mobileLink.click();

    const smsLink = page.locator('.dropdown-menu a[href="#send-sms-modal"]');
    await expect(smsLink).toBeVisible({ timeout: to(5000) });
    await smsLink.click();

    const modal = page.locator("#send-sms-modal");
    await expect(modal).toBeVisible({ timeout: to(5000) });

    // 3. Fill and send — SMS_SEND_COOLOFF=3 adds ~3s to scheduleAt
    const messageTextarea = modal.locator(".sms-message");
    await messageTextarea.fill(message);
    const sendTime = Date.now();

    // 4. Click send and wait for the page to reload.
    //    jethro-sms.js finishSend() hides the modal then calls
    //    location.reload() (line 310).  We must let that complete
    //    before navigating away, otherwise page.goto() collides.
    const reloadPromise = page.waitForEvent('load', { timeout: to(10000) });
    await modal.locator(".sms-submit").click();
    await reloadPromise;

    // 5. Verify the mock proxy captured the POST
    const lastPost = await request.get(
      mockMeta("tests/functional/sms/sms-cooloff", "lastPost")
    );
    const captured = await lastPost.json();

    expect(captured, "mock proxy did not capture an SMS POST").not.toBeNull();

    expect(
      captured.json.scheduleAt,
      "cooloff should produce a scheduleAt field for immediate sends"
    ).toBeDefined();

    const sentAt = new Date(captured.json.scheduleAt + "Z").getTime();
    // SMS_SEND_COOLOFF=3s → scheduleAt should be 1-7s in the future
    expect(sentAt - sendTime).toBeGreaterThan(1_000);
    expect(sentAt - sendTime).toBeLessThan(7_000);

    expect(captured.json.message).toBe(message);
    expect(captured.json.contacts).toEqual(["61491570159"]);
    expect(captured.json.countryCode).toBe(61);

    // 6. Navigate to Messages page — renders from the local DB.
    //    The message is non-final (scheduled_send_at is in the future),
    //    so it shows a 🕐 icon, a Cancel link, and Datastar polling.
    await page.goto("./?view=persons__messages");
    await page.waitForLoadState("load");

    const messageRow = page.locator(`tr[data-body*="${timestamp}"]`);
    await expect(messageRow).toBeVisible({ timeout: to(10000) });

    // 7. SCHEDULED state: 🕐 icon is visible
    const statusCell = messageRow.locator("span.message-status");
    await expect(statusCell).toContainText("🕐", { timeout: to(5000) });

    // 8. Cancel link is present while the message has a future ScheduledSendAt
    const cancelLink = messageRow.locator("a", { hasText: /cancel/i });
    await expect(cancelLink).toHaveCount(1);

    // 9. Non-final deliveries get Datastar polling spans for live updates
    const pollingSpans = messageRow.locator("span.message-attribution");
    await expect(pollingSpans).toHaveCount(1, { timeout: to(5000) });

    // TODO: verify the SCHEDULED → DELIVERED transition once the
    // call_sms_info batch path (listRecentDeliveries) correctly updates
    // the local DB.  The mock returns "delivered" for the individual and
    // batch report endpoints, and cooloff profile has DeliveryDelay=1s,
    // so after ~4s the message should transition to delivered, the 🕐
    // should disappear, Cancel should vanish, and polling spans should
    // be removed.
  });
});
