import { execFileSync } from "node:child_process";
import type { BrowserContext, Page } from "@playwright/test";
import { test, expect } from "../fixtures.js";
import { login } from "../auth.js";
import { to } from "../timeouts.js";
import { mockMeta } from "./smsmock-url.js";
import { waitForMessage } from "../email/mailpit-api.js";

const PROFILE = "tests/functional/sms/sms-2fa-password-reset";
const USERNAME = "sms-pwreset-fixture";
const NEW_PASSWORD = "Functest458";
const MOBILE = "0491570198"; // Fictional test mobile in the project's pseudo-ACMA block.

function sql(statement: string): string {
    return execFileSync("mariadb", ["jethro_functest", "-sNBe", statement], { encoding: "utf8" }).trim();
}

function quote(value: string): string {
    return `'${value.replaceAll("'", "''")}'`;
}

/** Own person and staff login, never a persona used concurrently by another project. */
function installFixture(): { id: number; email: string } {
    const email = "sms-pwreset-fixture@example.org";
    sql(`DELETE FROM staff_member WHERE username = ${quote(USERNAME)}`);
    sql(`DELETE FROM _person WHERE email = ${quote(email)}`);
    const id = Number(sql(`INSERT INTO _person
        (familyid, first_name, last_name, gender, email, mobile_tel, work_tel, remarks, status, history)
        SELECT familyid, 'Reset', 'Fixture', gender, ${quote(email)}, ${quote(MOBILE)}, '', '', status, ''
        FROM _person WHERE id = 184;
        SELECT LAST_INSERT_ID()`));
    sql(`INSERT INTO staff_member (id, username, password, active, permissions)
        SELECT ${id}, ${quote(USERNAME)}, password, '1', '1' FROM staff_member WHERE username = 'demo'`);
    return { id, email };
}

test("staged reset: shared SMS limits, session-bound verification and one-use login credit", async ({
    page, browser, request, baseURL,
}) => {
    // End-to-end across several browser contexts: two emailed reset requests,
    // SMS cooldown/expiry manipulation, password completion and 2FA logins.
    // That legitimately approaches the default 30s budget under the shared
    // 8-worker php-fpm load, so give it 3x rather than racing the clock.
    test.slow();
    const fixture = installFixture();
    const contexts: BrowserContext[] = [];
    try {
        async function freshPage(): Promise<Page> {
            const context = await browser.newContext({ baseURL });
            contexts.push(context);
            return context.newPage();
        }
        async function clearSms(): Promise<void> {
            await request.delete(mockMeta(PROFILE, "lastPost"));
        }
        async function capturedSms() {
            return (await request.get(mockMeta(PROFILE, "lastPost"))).json();
        }
        async function noSms(): Promise<void> {
            expect(await capturedSms(), "this action must not send an SMS").toBeNull();
        }
        async function sentCode(): Promise<string> {
            const captured = await capturedSms();
            expect(captured, "SMS send captured by this scenario's private mock").not.toBeNull();
            const code = String(captured?.json?.message ?? "").match(/\b(\d{6})\b/)?.[1];
            expect(code, "six-digit SMS code").toBeTruthy();
            return code!;
        }
        async function requestReset(): Promise<string> {
            await clearSms();
            const notBefore = Date.now();
            await page.goto("./?forgot_password=1");
            await page.locator("#forgot_password_username").fill(USERNAME);
            await page.locator('#login-box input[type="submit"]').click();
            await expect(page.getByText("If that username matches a user account")).toBeVisible({ timeout: to(15000) });
            const msg = await waitForMessage({ to: fixture.email, subject: "Password reset" }, notBefore, to(15000));
            const link = msg.Text.split("\n").find((line) => line.includes("reset_password="))?.trim();
            expect(link, "emailed reset link").toBeTruthy();
            await noSms();
            return link!;
        }
        async function openLink(p: Page, link: string): Promise<void> {
            await p.goto(link);
            await expect(p.locator('[name="reset_sms_send"]')).toBeVisible();
            expect(new URL(p.url()).searchParams.has("reset_password")).toBe(false);
            await expect(p.locator("#new_user_pw1")).toHaveCount(0);
        }
        async function verify(p: Page, code: string): Promise<void> {
            await p.locator("#reset_sms_code").fill(code);
            await p.getByRole("button", { name: "Verify code", exact: true }).click();
        }
        async function forgeCompletion(p: Page, code: string): Promise<void> {
            const navigation = p.waitForNavigation();
            await p.evaluate(({ password, code }) => {
                const form = document.createElement("form");
                form.method = "POST";
                for (const [name, value] of Object.entries({ reset_password_submitted: "1", new_user_pw1: password,
                    new_user_pw2: password, reset_sms_code: code })) {
                    const input = document.createElement("input");
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                }
                document.body.appendChild(form);
                form.submit();
            }, { password: NEW_PASSWORD, code });
            await navigation;
            await expect(p.locator("#new_user_pw1")).toHaveCount(0);
            expect(sql(`SELECT resetsmsattempts FROM staff_member WHERE username = ${quote(USERNAME)}`)).toBe("0");
            expect(sql(`SELECT resethash IS NOT NULL FROM staff_member WHERE username = ${quote(USERNAME)}`)).toBe("1");
        }

        const first = await requestReset();
        const a = await freshPage();
        const b = await freshPage();
        await openLink(a, first);
        await a.reload();
        await noSms();
        await forgeCompletion(a, "000000");
        await a.locator('[name="reset_sms_send"]').click();
        const firstCode = await sentCode();
        await clearSms();
        await openLink(b, first);
        await b.locator('[name="reset_sms_send"]').click();
        await noSms(); // Another session cannot evade the account's 60-second cooldown.
        await expect(b.locator("#reset_sms_code")).toBeVisible();
        await forgeCompletion(b, firstCode); // Even the correct code cannot verify through a password POST.
        const wrong = (firstCode[0] === "0" ? "1" : "0") + firstCode.slice(1);
        await verify(a, wrong);
        await expect(a.getByText("Incorrect code", { exact: false })).toBeVisible();
        expect(sql(`SELECT resetsmsattempts FROM staff_member WHERE username = ${quote(USERNAME)}`)).toBe("1");

        // Age only this fixture's reserved send, avoiding a minute-long sleep.
        sql(`UPDATE staff_member SET resetsmssent = NOW() - INTERVAL 61 SECOND WHERE username = ${quote(USERNAME)}`);
        await b.locator('[name="reset_sms_send"]').click();
        const resentCode = await sentCode();
        expect(sql(`SELECT resetsmsattempts FROM staff_member WHERE username = ${quote(USERNAME)}`)).toBe("1");
        const wrongResend = (resentCode[0] === "0" ? "1" : "0") + resentCode.slice(1);
        await verify(b, wrongResend);
        await expect(b.getByText("Too many incorrect codes", { exact: false })).toBeVisible();
        await page.goto(first);
        await expect(page.getByText("That password reset link is not valid", { exact: false })).toBeVisible();

        const second = await requestReset();
        expect(second).not.toBe(first);
        const completing = await freshPage();
        const other = await freshPage();
        await openLink(completing, second);
        await openLink(other, second);
        await noSms();
        await completing.locator('[name="reset_sms_send"]').click();
        const code = await sentCode();
        await clearSms();
        await verify(completing, code);
        await expect(completing.locator("#new_user_pw1")).toBeVisible();
        await completing.reload();
        await expect(completing.locator("#new_user_pw1")).toBeVisible();
        await other.reload();
        await expect(other.locator("#new_user_pw1")).toHaveCount(0);
        await noSms();

        // An expired challenge invalidates even the verifying session's credit.
        sql(`UPDATE staff_member SET resetsmssent = NOW() - INTERVAL 11 MINUTE WHERE username = ${quote(USERNAME)}`);
        await completing.reload();
        await expect(completing.locator("#new_user_pw1")).toHaveCount(0);
        await completing.locator('[name="reset_sms_send"]').click();
        const replacement = await sentCode();
        await clearSms();
        await verify(completing, replacement);
        await expect(completing.locator("#new_user_pw1")).toBeVisible();
        await other.reload();
        await expect(other.locator("#new_user_pw1")).toHaveCount(0);
        await completing.locator("#new_user_pw1").fill(NEW_PASSWORD);
        await completing.locator('#login-box input[type="submit"]').click();
        await expect(completing.getByText("Your password has been updated", { exact: false })).toBeVisible();
        await expect(completing.locator('input[name="username"]')).toBeVisible(); // not automatically logged in
        await noSms();

        // Credit is scoped to the completion browser and consumed by its next password login.
        await login(completing, USERNAME, NEW_PASSWORD);
        await expect(completing.locator("#login-box")).toHaveCount(0);
        await noSms();
        await completing.goto("./?logout=1");
        await login(completing, USERNAME, NEW_PASSWORD);
        await expect(completing.locator('input[name="2fa_code"]')).toBeVisible();
        await sentCode();
        await clearSms();
        await login(other, USERNAME, NEW_PASSWORD);
        await expect(other.locator('input[name="2fa_code"]')).toBeVisible();
        await sentCode();

        const replay = await freshPage();
        await replay.goto(second);
        await expect(replay.getByText("That password reset link is not valid", { exact: false })).toBeVisible();
    } finally {
        for (const context of contexts) await context.close();
        sql(`DELETE FROM staff_member WHERE username = ${quote(USERNAME)}`);
        sql(`DELETE FROM _person WHERE id = ${fixture.id} AND email = ${quote(fixture.email)}`);
    }
});
