import { test, expect } from "@playwright/test";
import { login } from "../auth.js";

test.describe("SMS status panel — unreachable gateway", () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test("reports 'not reachable' when balance endpoint returns 500", async ({
    page,
  }) => {
    // The status panel is returned by ?call=admin_statuspanel_sms&datastar={}
    await page.goto("./?call=admin_statuspanel_sms&datastar=%7B%7D");
    await page.waitForSelector("#status-panel-sms", { timeout: 5000 });

    const panel = page.locator("#status-panel-sms");
    const text = (await panel.textContent()) ?? "";

    // Assert the status message
    expect(text).toContain("not reachable");
    expect(text).not.toContain("Operational");
    // The ✗ icon (red cross) indicates failure.
    expect(text).toContain("✗");
  });
});
