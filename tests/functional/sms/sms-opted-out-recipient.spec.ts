import { test, expect } from "@playwright/test";
import { mockMeta } from "./smsmock-url.js"
import { login } from "../auth.js";
import { to } from "../timeouts.js";

test.describe("SMS to family with opted-out member", () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test("Opted-out family member is excluded from send and reported", async ({
    page,
    request,
  }) => {
    const timestamp = new Date().toISOString().replace(/[:.]/g, "-");

    // 1. Navigate to the Williamson family (familyid=14)
    // This family has 2 mobile recipients: Jamison (opted out) and Bridget (gets SMS)
    await page.goto("./?view=families&familyid=14");
    await page.waitForLoadState("load");

    // 2. Open the bulk-action chooser and select "Send SMS"
    const chooser = page.locator("#bulk-action-chooser");
    await expect(chooser).toBeVisible();
    await chooser.selectOption("smshttp");

    // The SMS form (#smshttp) should now be visible
    const smsForm = page.locator("#smshttp");
    await expect(smsForm).toBeVisible();

    // 3. Fill the message
    const messageBox = smsForm.locator(".sms-message");
    const testMessage = `Test opted-out ${timestamp}`;
    await messageBox.fill(testMessage);

    // 4. Wait for statusline to populate (debounced AJAX call)
    // After opt-out filtering, should show 1 recipient (Bridget only)
    const statuslinePostPromise = page.waitForResponse(
      (resp) =>
        resp.url().includes("call=sms_statusline") && resp.status() === 200
    );
    await statuslinePostPromise;
    await page.waitForTimeout(150); // Datastar SSE DOM morphing

    const statusLine = page.locator("#sms-statusline-bulk");
    await expect(statusLine).toBeVisible({ timeout: to(5000) });
    await expect(statusLine).toContainText("1 recipient");

    // 5. Click Send
    const sendButton = smsForm.locator(".bulk-sms-submit");
    await sendButton.click();

    // 6. Wait for the send result to appear
    const results = page.locator("#bulk-sms-results");
    await expect(results).toContainText("Message successfully sent to Bridget Williamson", {
      timeout: to(15000),
    });

    // 7. Verify that Bridget received the SMS
    await expect(results).toContainText("Bridget Williamson");

    // 8. Verify that Jamison is reported as opted-out
    // Expected text: "Jamison Williamson has opted out of receiving SMS"
    await expect(results).toContainText("Jamison Williamson", {
      timeout: to(5000),
    });
    await expect(results).toContainText("has opted out of receiving SMS");

    // 9. Verify via /meta/lastPost that only Bridget's number was POSTed
    const lastPost = await request.get(
      mockMeta("tests/functional/sms/sms-opted-out-recipient", "lastPost")
    );
    const captured = await lastPost.json();
    expect(captured, "mock proxy did not capture an SMS POST").not.toBeNull();
    expect(captured.json.contacts, "POST body should contain only Bridget's number").toEqual([
      "61491570159",
    ]);
    expect(captured.json.message).toContain("Test opted-out");
    expect(captured.json.countryCode).toBe(61);
  });
});
