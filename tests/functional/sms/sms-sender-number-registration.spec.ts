import { to } from "../timeouts.js";
import { test, expect } from "../fixtures.js";
import { mockMeta } from "./smsmock-url.js"
import { login } from "../auth.js";

const SENDER = "smallgroupleader";
const SENDER_PERSONID = 170;

test.describe("SMS sender number registration (OTP flow)", () => {
  test.beforeEach(async ({ page, request }) => {
    // Clean up any registration from a previous run — stale registrations
    // with ApprovalDelay=0 default to "approved" in the mock, making
    // verifySenderNumber() return true and hiding the registration UI.
    await request.delete(mockMeta("tests/functional/sms/sms-sender-number-registration", "registrations"));
    // smallgroupleader (person 170): can send, and no other scenario sends from
    // its mobile, so no other scenario has verified it — the shared functest
    // database records a number as verified for the first person it is
    // verified for (DbLoggingSmsProvider::verifySenderNumber()).
    await login(page, SENDER);
  });

  test("Register and verify own mobile number as SMS sender", async ({ page }) => {
    // 1. Navigate to the sender's own person page
    await page.goto(`./?view=persons&personid=${SENDER_PERSONID}`);
    await page.waitForLoadState("load");

    // 2. Find and hover over the mobile number to trigger the SMS modal dropdown
    const mobileLink = page.locator(`#mobile-${SENDER_PERSONID}`);
    await expect(mobileLink).toBeVisible();
    await mobileLink.hover();
    await page.waitForTimeout(50);
    await mobileLink.click();

    // 3. Click "SMS via Jethro" to open the modal
    const smsLink = page.locator('.dropdown-menu a[href="#send-sms-modal"]');
    await expect(smsLink).toBeVisible({ timeout: to(5000) });
    await smsLink.click();

    // 4. Wait for the SMS modal to become visible
    const modal = page.locator("#send-sms-modal");
    await expect(modal).toBeVisible({ timeout: to(5000) });

    // 5. The sender area shows "needs verifying" — the user's mobile is not yet registered
    // (the mock proxy's GET /api/v1/customNumber list doesn't include it)
    const registerStatus = page.locator(`#sms-register-person_${SENDER_PERSONID}`);
    await expect(registerStatus).toBeVisible();
    await expect(registerStatus).toContainText("needs verifying");

    // 6. Click the "verify now" link — triggers Datastar @post to ?call=sms_sendernum&action=register
    const registerLink = page.locator(".sms-register-number");
    const regResponse = page.waitForResponse(
      (r) => r.url().includes("call=sms_sendernum") && r.status() === 200
    );
    await registerLink.click();
    await regResponse;

    // 7. Brief pause for Datastar response to arrive
    await page.waitForTimeout(150);

    // 8. The mock proxy returns {"status": true, "message": "Custom number created"}
    //    (or "Number already exist in system" when already registered — e.g.
    //    parallel runs). Cellcast treats both as success. Datastar patches
    //    #sms-register-person_<id> with the message.
    await expect(registerStatus).not.toContainText("needs verifying", { timeout: to(5000) });
    await expect(registerStatus).toContainText(/created|verified|success|already exist/i, { timeout: to(5000) });
  });
});
