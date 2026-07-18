import { test, expect } from "@playwright/test";
import { SMS_MOCK_BASE } from "./smsmock-url.js"
import { login } from "../auth.js";

test.describe("5CentSMS sender-id caching", () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test("getSenderIds() is cached in session — second page load does not hit /senderid API", async ({
    page,
    request,
  }) => {
    const mockBase = `${SMS_MOCK_BASE}/sms/5centsms-senderid-cache-test`;
    const lastRequestUrl = `${mockBase}/meta/lastRequest`;

    // ── First page load: should trigger GET /senderid ──────────────────

    await page.goto("./?view=persons&personid=5");
    await page.waitForLoadState("load");

    // The page renders the SMS sender dropdown, which calls getSenderIds() →
    // FiveCentSmsV5Provider hits GET /senderid (matched by the fixture).
    // The proxy records every fixture-matched request.
    const first = await request.get(lastRequestUrl);
    expect(first.status()).toBe(200);
    const firstRequests: any[] = (await first.json()) ?? [];
    const firstSenderIds = firstRequests.filter(
      (r: any) => r.uri?.includes("/senderid"),
    );
    expect(
      firstSenderIds.length,
      "first load: /senderid should have been hit at least once",
    ).toBeGreaterThan(0);

    // ── Reset tracking and load again ──────────────────────────────────

    await request.delete(lastRequestUrl);

    await page.goto("./?view=persons&personid=5");
    await page.waitForLoadState("load");

    // ── Second page load: /senderid should NOT have been hit again ─────
    // A correct session-cache hit means the provider returns cached sender IDs
    // without calling the API.
    const second = await request.get(lastRequestUrl);
    expect(second.status()).toBe(200);
    const secondRequests: any[] = (await second.json()) ?? [];
    const secondSenderIds = secondRequests.filter(
      (r: any) => r.uri?.includes("/senderid"),
    );

    // The cache is currently broken — every request re-fetches /senderid.
    // Once fixed, secondSenderIds should be empty.
    expect(
      secondSenderIds.length,
      "second load: /senderid should NOT have been hit (session-cached)",
    ).toBe(0);
  });
});
