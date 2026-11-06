import { expect, type APIRequestContext, type Page } from "@playwright/test";
import { mockMeta } from "./sms/smsmock-url.js";
import { to } from "./timeouts.js";

/**
 * The members-area Edit step-up gate.
 *
 * Member login stays un-2FA'd (email recovery must keep working); the Edit
 * view instead requires a fresh SMS code sent to the member's stored mobile
 * before the form loads or a save is accepted. Projects that drive the members
 * area behind the gate each serve it under their own scenario prefix, so their
 * `.conf` (SMS provider + mock URL) and mock profile are their own: captures
 * never overlap another project's sends when the suite runs them in parallel.
 */
export interface EditGate {
  /** Mock profile of the project's scenario, for mockMeta(). */
  profile: string;
  /** The gated Edit view, relative to the project's baseURL. */
  editUrl: string;
}

/** Most recent send the mock captured for a profile (null when it holds none). */
export async function lastSms(
  request: APIRequestContext,
  profile: string,
): Promise<{ json?: { message?: string } } | null> {
  return (await request.get(mockMeta(profile, "lastPost"))).json();
}

/** The six-digit code from a captured verification SMS. */
export function gateCode(profile: string, message: string | undefined): string {
  expect(message, `no SMS captured for the ${profile} mock profile`).toBeTruthy();
  const code = message!.match(/\b(\d{6})\b/)?.[1];
  expect(code, `no 6-digit code in SMS: "${message}"`).toBeTruthy();
  return code!;
}

/** Complete the Edit step-up gate: explicit send, then verify the captured code. */
export async function completeEditGate(
  page: Page,
  request: APIRequestContext,
  gate: EditGate,
): Promise<void> {
  await page.goto(gate.editUrl);
  await request.delete(mockMeta(gate.profile, "lastPost"));
  await page.locator('button:has-text("Send code")').click();
  const captured = await lastSms(request, gate.profile);
  const code = gateCode(gate.profile, captured?.json?.message);
  await page.locator("#member-edit-sms-code").fill(code);
  await page.locator('button:has-text("Verify code")').click();
  await expect(page.locator('input[name^="person_"]').first()).toBeVisible({ timeout: to(15000) });
}
