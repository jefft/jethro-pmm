import { execFileSync } from "node:child_process";
import { test, expect } from "../fixtures.js";
import { login, PERSONA_PASSWORD } from "../auth.js";
import { to } from "../timeouts.js";
import { latestMessage, waitForMessage } from "./mailpit-api.js";
import type { Page } from "@playwright/test";

/**
 * Staff self-service credential recovery
 * (docs/docs/developer/reference/password-reset.mdx):
 *   - forgot-password: armed request → emailed single-use link → new password → login;
 *   - forgot-username: reminder email listing the username(s) on an address.
 *
 * Email capture is the `mailpit` process-compose service; queries are scoped by
 * recipient + subject so parallel workers coexist (DEVELOPMENT_FUNCTESTS.md,
 * "Email capture (MailPit)").
 *
 * Every demo staff account (including `noperms`) is logged in by `lookaround`,
 * and the restricted personas by the sms-* projects, all in parallel against
 * this database. The reset test therefore creates its OWN transient staff login
 * for an otherwise-ordinary demo person (person 184, whose @example.org address
 * no demo-DB spec uses), and deletes the staff_member row afterwards: no shared
 * credential or person record is changed.
 */

const EMAIL = "maira.daniel@example.org"; // demo person 184: ordinary person, unreferenced by demo-DB specs
const USERNAME = "pwreset-fixture"; // the transient staff login created and removed by the reset test
const NEW_PASSWORD = "Functest458";
/** Equal to demo@example.org under the column's accent-folding collation. */
const LOOKALIKE_EMAIL = "démo@example.org";

/** The functest database (devbox.d/functest_jethro_server/conf.php). */
const DB = "jethro_functest";

/**
 * Run one statement against the functest database. The devbox mariadb client
 * defaults to the 'jethro' user and the socket in MYSQL_UNIX_PORT, so the
 * database name alone is enough (same access as walkthrough.spec.ts).
 */
function sql(statement: string): string {
    return execFileSync("mariadb", [DB, "-sNBe", statement], { encoding: "utf8" }).trim();
}

/** Quote a SQL string literal (execFileSync passes the statement as one argv, no shell). */
function quote(value: string): string {
    return `'${value.replaceAll("'", "''")}'`;
}

/**
 * Give the demo person with EMAIL a temporary staff login whose initial password
 * is the shared persona password (borrowed from demo's hash, so no hashing is
 * needed here). Idempotent, in case a previous run died before cleanup.
 */
function installFixtureLogin(): void {
    const ids = sql(`SELECT id FROM _person WHERE email = ${quote(EMAIL)}`).split("\n").filter(Boolean);
    if (ids.length !== 1) {
        throw new Error(`Expected exactly one demo person with the address ${EMAIL}, found ${ids.length}; update the password-reset fixture`);
    }
    sql(`DELETE FROM staff_member WHERE username = ${quote(USERNAME)}`);
    sql(`INSERT INTO staff_member (id, username, password, active, permissions)
            SELECT ${ids[0]}, ${quote(USERNAME)}, password, '1', '0'
            FROM staff_member WHERE username = 'demo'`);
}

/**
 * Remove the transient login. Deleting the row also discards the password the
 * reset set and any armed reset request, so a failed run leaves nothing behind.
 */
function removeFixtureLogin(): void {
    sql(`DELETE FROM staff_member WHERE username = ${quote(USERNAME)}`);
}

/** Submit the forgot-password form; the response is deliberately generic. */
async function submitForgotPassword(page: Page, username: string): Promise<void> {
    await page.goto("./?forgot_password=1");
    await page.locator("#forgot_password_username").fill(username);
    await page.locator('#login-box input[type="submit"]').click();
    await expect(page.getByText("If that username matches a user account")).toBeVisible({ timeout: to(15000) });
}

/** Grab the newest reset email for a recipient, follow its link to set $newPassword, and return the link. */
async function completeReset(
    page: Page,
    recipient: string,
    newPassword: string,
    notBefore: number,
): Promise<string> {
    const msg = await waitForMessage({ to: recipient, subject: "Password reset" }, notBefore, to(15000));
    const link = msg.Text.split("\n").find((line) => line.includes("reset_password="))?.trim();
    expect(link, "emailed reset link").toBeTruthy();

    await page.goto(link!); // validates the token, 303s to the reset form
    // Regression: a VALID link must land on the form without the invalid-link
    // banner (an early version redirected via ?reset_password=continue, which
    // re-entered the token validator).
    await expect(page.getByText("That password reset link is not valid")).toHaveCount(0);
    await page.locator("#new_user_pw1").fill(newPassword);
    await page.locator('#login-box input[type="submit"]').click();
    await expect(page.getByText("Your password has been updated")).toBeVisible({ timeout: to(15000) });
    return link!;
}

test("forgot password: emailed link allows choosing a new password, then login", async ({ page }) => {
    try {
        installFixtureLogin();
        const notBefore = Date.now(); // ignore leftover matches from previous suite runs
        await submitForgotPassword(page, USERNAME);
        const link = await completeReset(page, EMAIL, NEW_PASSWORD, notBefore);

        // Single-use: completing the reset consumed the armed request.
        await page.goto(link);
        await expect(page.getByText("That password reset link is not valid")).toBeVisible({ timeout: to(15000) });

        // The chosen password replaced the borrowed initial one.
        await login(page, USERNAME, NEW_PASSWORD);
        await expect(page.locator("#login-box")).toHaveCount(0); // logged in
        await page.goto("./?logout=1");
        await login(page, USERNAME, PERSONA_PASSWORD);
        await expect(page.locator("#login-box")).toHaveCount(1); // old password rejected
    } finally {
        removeFixtureLogin();
    }
});

/** Submit the forgot-username form; the response is deliberately generic. */
async function submitForgotUsername(page: Page, email: string): Promise<void> {
    await page.goto("./?forgot_username=1");
    await page.locator("#forgot_username_email").fill(email);
    await page.locator('#login-box input[type="submit"]').click();
    await expect(page.getByText("If that email address matches a user account")).toBeVisible({ timeout: to(15000) });
}

test("forgot username: reminder email lists the account username", async ({ page }) => {
    const notBefore = Date.now();
    // Regression: _person.email's collation folds accents, so this look-alike
    // address matched demo@example.org and was sent demo's username. Mail goes
    // to the STORED address, so the look-alike must produce no reminder at all
    // - and must not use up demo's hourly slot before the exact match below.
    await submitForgotUsername(page, LOOKALIKE_EMAIL);
    // Mail is sent synchronously before the generic response renders.
    expect(
        await latestMessage({ to: "demo@example.org", subject: "Username reminder" }, notBefore),
        "a look-alike address must not trigger a reminder",
    ).toBeNull();

    // ASCII case differences still match; the reminder goes to the stored address.
    await submitForgotUsername(page, "Demo@Example.org");
    const msg = await waitForMessage({ to: "demo@example.org", subject: "Username reminder" }, notBefore, to(15000));
    await page.setContent(msg.HTML);
    await expect(page.locator("b")).toHaveText("demo");
});
