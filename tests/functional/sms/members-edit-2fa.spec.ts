import { execFileSync } from "node:child_process";
import { randomUUID } from "node:crypto";
import type { Page } from "@playwright/test";
import { test, expect } from "../fixtures.js";
import { to } from "../timeouts.js";
import { completeEditGate, gateCode, lastSms, type EditGate } from "../member-edit-gate.js";
import { mockMeta } from "./smsmock-url.js";

/**
 * Members-area edit step-up verification.
 *
 * Member login is deliberately un-2FA'd (email recovery must stay usable);
 * instead the Edit view ("Edit My Family") requires a fresh SMS code sent to
 * the member's stored mobile before the form loads or a save is accepted.
 * Contacts (email/mobile) are masked everywhere in the members area until
 * that verification succeeds, and member-editable forms never include the
 * mobile number (administrator-managed, it is the recovery channel).
 *
 * All fixture data is scoped to a transient family created and deleted by this
 * spec: shared personas' contacts and credentials are never modified.
 */

const PROFILE = "tests/functional/sms/members-edit-2fa";
const DEMO_PASSWORD = "qfntt7eYuwHs123"; // staff demo password (public in dev conf)
const GATE: EditGate = { profile: PROFILE, editUrl: "./members/index.php?view=_edit_me" };

// One shared mock-profile capture slot and per-person cooldowns: run strictly
// sequentially.
test.describe.configure({ mode: "serial" });

function sql(statement: string): string {
  return execFileSync("mariadb", ["jethro_functest", "-sNBe", statement], { encoding: "utf8" }).trim();
}

interface Fixture {
  familyId: number;
  memberId: number;
  email: string;
}

/** Isolated family: member login (staff demo password pattern), own contacts. */
function installFixture(): Fixture {
  const suffix = randomUUID();
  const email = `member-edit-${suffix}@example.org`;
  const rows = sql(`
    START TRANSACTION;
    INSERT INTO family
      (family_name, address_street, address_suburb, address_state, address_postcode, home_tel, status, history)
      SELECT 'Edit 2FA Fixture', '123 Example Road', address_suburb, address_state, address_postcode, '55500161', status, ''
      FROM family WHERE id = (SELECT familyid FROM _person WHERE id = 9);
    SET @f = LAST_INSERT_ID();
    INSERT INTO _person
      (familyid, first_name, last_name, gender, age_bracketid, email, mobile_tel, work_tel,
       remarks, status, congregationid, history, member_password)
      SELECT @f, 'Edit', 'Fixture', p.gender, p.age_bracketid, '${email}', '0491570198', '', '', p.status, p.congregationid, '', sm.password
      FROM _person p CROSS JOIN staff_member sm WHERE p.id = 4 AND sm.username = 'demo';
    SET @m = LAST_INSERT_ID();
    SELECT @f, @m;
    COMMIT;
  `).split("\t").map(Number);
  return { familyId: rows[0], memberId: rows[1], email };
}

function cleanup(f: Fixture): void {
  sql(`DELETE FROM db_object_lock
       WHERE (object_type = 'person' AND objectid = ${f.memberId})
          OR (object_type = 'family' AND objectid = ${f.familyId});
     DELETE FROM _person WHERE id = ${f.memberId};
     DELETE FROM family WHERE id = ${f.familyId};`);
}

async function memberLogin(page: Page, email: string): Promise<void> {
  await page.goto("./members/index.php");
  const loginBox = page.locator("#login-box");
  await loginBox.locator('input[name="email"]').fill(email);
  await loginBox.locator('input[name="password"]').fill(DEMO_PASSWORD);
  await Promise.all([
    page.waitForLoadState("load", { timeout: to(1000) }),
    loginBox.locator('input[name="login-request"]').click(),
  ]);
  await expect(page.locator("#login-box")).toHaveCount(0);
}

test.describe.serial("Members edit SMS step-up", () => {
  let fixture: Fixture;

  test.afterEach(() => {
    if (fixture) cleanup(fixture);
  });

  test("shared-group contacts stay visible while own-family contacts remain masked", async ({ page, request }) => {
    fixture = installFixture();
    const other = installFixture();
    const relativeEmail = `relative-${randomUUID()}@example.org`;
    let relativeId: number | undefined;
    let groupId: number | undefined;
    try {
      sql(`UPDATE family SET address_street = '456 Shared Avenue', home_tel = '55500162'
        WHERE id = ${other.familyId};
        UPDATE _person SET mobile_tel = '0491570199' WHERE id = ${other.memberId}`);
      relativeId = Number(sql(`INSERT INTO _person
        (familyid, first_name, last_name, gender, age_bracketid, email, mobile_tel, work_tel,
         remarks, status, congregationid, history)
        SELECT familyid, 'Relative', 'Disclosure Fixture', gender, age_bracketid,
          '${relativeEmail}', '0491570197', '', '', status, congregationid, ''
        FROM _person WHERE id = ${fixture.memberId};
        SELECT LAST_INSERT_ID()`));
      groupId = Number(sql(`INSERT INTO _person_group
        (name, categoryid, is_archived, owner, show_add_family, share_member_details, attendance_recording_days)
        VALUES ('Disclosure Fixture', NULL, '0', NULL, '0', '1', '');
        SELECT LAST_INSERT_ID()`));
      const membershipStatus = Number(sql("SELECT membership_status FROM person_group_membership LIMIT 1"));
      sql(`INSERT INTO person_group_membership (personid, groupid, membership_status) VALUES
        (${fixture.memberId}, ${groupId}, ${membershipStatus}),
        (${relativeId}, ${groupId}, ${membershipStatus}),
        (${other.memberId}, ${groupId}, ${membershipStatus})`);
      await memberLogin(page, fixture.email);
      await page.goto("./members/index.php?view=people");
      const peopleHtml = await page.locator("#body").innerHTML();
      expect(peopleHtml).toContain(other.email);
      expect(peopleHtml).toContain("0491570199");
      expect(peopleHtml).toContain("55500162");
      expect(peopleHtml).toContain("456 Shared Avenue");
      expect(peopleHtml).not.toContain(relativeEmail);
      expect(peopleHtml).not.toContain(fixture.email);
      expect(peopleHtml).not.toContain("123 Example Road");
      expect(peopleHtml).not.toContain("55500161");
      // Sharing the same group must not bypass the relative's family mask.
      await page.goto(`./members/index.php?view=_groups&groupid=${groupId}`);
      const groupHtml = await page.locator("#body").innerHTML();
      expect(groupHtml).toContain(other.email);
      expect(groupHtml).not.toContain(relativeEmail);
      await completeEditGate(page, request, GATE);
      await expect(page.locator(`input[name="person_${relativeId}email"]`)).toBeVisible();
      await expect(page.locator(`input[name="person_${other.memberId}email"]`)).toHaveCount(0);
      // Group visibility does not authorise writes to the other family.
      await page.evaluate(({ id }) => {
        const form = document.querySelector<HTMLInputElement>('[name="family_details_submitted"]')!.form!;
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = `person_${id}email`;
        input.value = "not-authorised@example.org";
        form.appendChild(input);
      }, { id: other.memberId });
      await page.getByRole("button", { name: "Save", exact: true }).click();
      await expect(page.locator("#body")).toContainText("Details saved");
      expect(sql(`SELECT email FROM _person WHERE id = ${other.memberId}`)).toBe(other.email);
      // A live family move changes which rows are protected, despite the
      // login-time session retaining the original family ID.
      sql(`UPDATE _person SET familyid = ${other.familyId} WHERE id = ${fixture.memberId}`);
      await page.goto("./members/index.php?view=people");
      const movedHtml = await page.locator("#body").innerHTML();
      expect(movedHtml).not.toContain(other.email);
      expect(movedHtml).not.toContain("456 Shared Avenue");
      expect(movedHtml).toContain(relativeEmail);
      await page.goto("./members/index.php?view=home");
      await expect(page.locator(".family")).toContainText("456 S…");
      expect(await page.locator(".family").innerHTML()).not.toContain("456 Shared Avenue");
    } finally {
      sql(`UPDATE _person SET familyid = ${fixture.familyId} WHERE id = ${fixture.memberId}`);
      if (groupId !== undefined) {
        sql(`DELETE FROM person_group_membership WHERE groupid = ${groupId};
          DELETE FROM _person_group WHERE id = ${groupId}`);
      }
      if (relativeId !== undefined) sql(`DELETE FROM _person WHERE id = ${relativeId}`);
      cleanup(other);
    }
  });

  test("edit is gated: no form, masked contacts, code required", async ({ page, request }) => {
    fixture = installFixture();
    await request.delete(mockMeta(PROFILE, "lastPost"));

    // Pre-verification: family page masks email and mobile everywhere.
    await memberLogin(page, fixture.email);
    await page.goto("./members/index.php?view=home");
    const body = await page.locator("#body").textContent();
    expect(body).not.toContain(fixture.email);
    expect(body).not.toContain("0491570198");
    expect(body).toContain("•••@example.org");
    expect(body).toContain("•••• ••• 98");
    expect(body).toContain("•••• ••• 61");
    expect(body).toContain("123 E…");
    const homeHtml = await page.locator("#body").innerHTML();
    expect(homeHtml).not.toContain("55500161");
    expect(homeHtml).not.toContain("123 Example Road");
    expect(homeHtml).not.toContain(encodeURIComponent("123 Example Road"));
    await expect(page.locator(".family a.map")).toHaveCount(0);

    // Directory likewise.
    await page.goto("./members/index.php?view=people");
    const peopleBody = await page.locator("#body").textContent();
    expect(peopleBody).not.toContain(fixture.email);
    expect(peopleBody).toContain("•••@example.org");
    expect(peopleBody).toContain("•••• ••• 61");
    expect(peopleBody).toContain("123 E…");
    const directoryHtml = await page.locator("#body").innerHTML();
    expect(directoryHtml).not.toContain("55500161");
    expect(directoryHtml).not.toContain("123 Example Road");
    await expect(page.locator("#body a.map")).toHaveCount(0);
    // A street without the permitted prefix reveals no address characters.
    sql(`UPDATE family SET address_street = 'Unit 2, 123 Example Road' WHERE id = ${fixture.familyId}`);
    await page.goto("./members/index.php?view=home");
    await expect(page.locator(".family .object-summary tr").filter({ hasText: "Street Address" }).locator("td")).toHaveText("…");
    expect(await page.locator("#body").innerHTML()).not.toContain("Unit 2");

    // Direct forged edit POST is refused and saves nothing.
    const forged = await page.request.post("./members/index.php?view=_edit_me", {
      form: {
        family_details_submitted: "1",
        [`person_${fixture.memberId}email`]: "hacker@example.org",
      },
    });
    expect(forged.status()).toBe(200);
    expect(
      sql(`SELECT email FROM _person WHERE id = ${fixture.memberId}`),
    ).toBe(fixture.email);

    // Edit view: gate, no form fields, no automatic SMS on GET.
    await request.delete(mockMeta(PROFILE, "lastPost"));
    await page.goto("./members/index.php?view=_edit_me");
    await expect(page.locator('button:has-text("Send code")')).toBeVisible();
    await expect(page.locator('input[name^="person_"]')).toHaveCount(0);
    expect(await lastSms(request, PROFILE)).toBeNull();
    // No contact values leak onto the gate.
    const gate = await page.locator("#body").textContent();
    expect(gate).not.toContain(fixture.email);
    expect(gate).not.toContain("0491570198");
    expect(gate).toContain("•••• ••• 98");
  });

  test("send + verify round-trip enables edit and save consumes proof", async ({ page, request }) => {
    fixture = installFixture();
    await memberLogin(page, fixture.email);

    // Edit gate: explicit Send.
    await page.goto("./members/index.php?view=_edit_me");
    await request.delete(mockMeta(PROFILE, "lastPost"));
    await page.locator('button:has-text("Send code")').click();
    await expect(page.locator(".alert-success, #body").first()).toContainText("A verification code has been sent", { timeout: to(15000) });
    const captured = await lastSms(request, PROFILE);
    const code = gateCode(PROFILE, captured?.json?.message);

    // Wrong code refuses; correct code opens the form.
    await page.locator("#member-edit-sms-code").fill(code === "000000" ? "000001" : "000000");
    await page.locator('button:has-text("Verify code")').click();
    await expect(page.locator(".alert-error")).toContainText("Incorrect code");
    await expect(page.locator('input[name^="person_"]').first()).toHaveCount(0);

    await page.locator("#member-edit-sms-code").fill(code);
    await page.locator('button:has-text("Verify code")').click();
    await expect(page.locator(`input[name="person_${fixture.memberId}email"]`)).toBeVisible({ timeout: to(15000) });
    // Mobile number is never member-editable.
    await expect(page.locator(`input[name="person_${fixture.memberId}mobile_tel"]`)).toHaveCount(0);
    // Verified: contacts reveal.
    await page.goto("./members/index.php?view=home");
    await expect(page.locator("#body")).toContainText(fixture.email);
    // Verification does not reveal family address/phone on read-only pages.
    await expect(page.locator(".family")).toContainText("123 E…");
    expect(await page.locator(".family").innerHTML()).not.toContain("123 Example Road");
    await expect(page.locator(".family a.map")).toHaveCount(0);

    // Save works and consumes the proof: next edit needs a fresh code.
    await page.goto("./members/index.php?view=_edit_me");
    // The actual edit form retains the complete, editable values.
    await expect(page.locator('[name="address_street"]')).toHaveValue("123 Example Road");
    expect((await page.locator('input[name="home_tel"]').inputValue()).replace(/\D/g, "")).toBe("55500161");
    await page.locator(`input[name="person_${fixture.memberId}work_tel"]`).fill("0412 345 678");
    await page.locator('button:has-text("Save")').click();
    await expect(page.locator("#body")).toContainText("Details saved", { timeout: to(15000) });
    expect(sql(`SELECT REPLACE(work_tel, ' ', '') FROM _person WHERE id = ${fixture.memberId}`)).toBe("0412345678");
    expect(sql(`SELECT membereditsmshash IS NULL FROM _person WHERE id = ${fixture.memberId}`)).toBe("0"); // challenge still armed but proof consumed
    await page.goto("./members/index.php?view=_edit_me");
    await expect(page.locator('button:has-text("Send code")')).toBeVisible();
    await expect(page.locator('input[name^="person_"]')).toHaveCount(0);
  });

  test("success on attempt five does not block the next edit challenge", async ({ page, request }) => {
    fixture = installFixture();
    await memberLogin(page, fixture.email);
    await page.goto("./members/index.php?view=_edit_me");
    await request.delete(mockMeta(PROFILE, "lastPost"));
    await page.getByRole("button", { name: "Send code", exact: true }).click();
    const first = gateCode(PROFILE, (await lastSms(request, PROFILE))?.json?.message);
    const wrong = (first[0] === "0" ? "1" : "0") + first.slice(1);
    for (let attempt = 1; attempt <= 4; attempt++) {
      await page.locator("#member-edit-sms-code").fill(wrong);
      await page.getByRole("button", { name: "Verify code", exact: true }).click();
      await expect(page.locator(".alert-error")).toContainText("Incorrect code");
    }
    await page.locator("#member-edit-sms-code").fill(first);
    await page.getByRole("button", { name: "Verify code", exact: true }).click();
    await expect(page.locator(`input[name="person_${fixture.memberId}email"]`)).toBeVisible();
    await page.locator(`input[name="person_${fixture.memberId}work_tel"]`).fill("0491570160");
    await page.getByRole("button", { name: "Save", exact: true }).click();
    await expect(page.locator("#body")).toContainText("Details saved");
    await page.goto("./members/index.php?view=_edit_me");
    // Advance only this fixture's cooldown rather than sleeping for a minute.
    sql(`UPDATE _person SET membereditsmssent = NOW() - INTERVAL 61 SECOND
      WHERE id = ${fixture.memberId}`);
    await request.delete(mockMeta(PROFILE, "lastPost"));
    await page.getByRole("button", { name: "Send code", exact: true }).click();
    expect(sql(`SELECT membereditsmsattempts FROM _person WHERE id = ${fixture.memberId}`)).toBe("0");
    const next = gateCode(PROFILE, (await lastSms(request, PROFILE))?.json?.message);
    await page.locator("#member-edit-sms-code").fill(next);
    await page.getByRole("button", { name: "Verify code", exact: true }).click();
    await expect(page.locator(`input[name="person_${fixture.memberId}email"]`)).toBeVisible();
  });

  test("resending restores five attempts without bypassing the send cooldown", async ({ page, request }) => {
    fixture = installFixture();
    await memberLogin(page, fixture.email);
    await page.goto("./members/index.php?view=_edit_me");
    await request.delete(mockMeta(PROFILE, "lastPost"));
    await page.getByRole("button", { name: "Send code", exact: true }).click();
    const first = gateCode(PROFILE, (await lastSms(request, PROFILE))?.json?.message);
    const wrong = (first[0] === "0" ? "1" : "0") + first.slice(1);
    for (let attempt = 1; attempt <= 2; attempt++) {
      await page.locator("#member-edit-sms-code").fill(wrong);
      await page.getByRole("button", { name: "Verify code", exact: true }).click();
      await expect(page.locator(".alert-error")).toContainText("Incorrect code");
    }
    await request.delete(mockMeta(PROFILE, "lastPost"));
    await page.getByRole("button", { name: "Send code", exact: true }).click();
    expect(await lastSms(request, PROFILE)).toBeNull();
    expect(sql(`SELECT membereditsmsattempts FROM _person WHERE id = ${fixture.memberId}`)).toBe("2");
    sql(`UPDATE _person SET membereditsmssent = NOW() - INTERVAL 61 SECOND
      WHERE id = ${fixture.memberId}`);
    await page.getByRole("button", { name: "Send code", exact: true }).click();
    expect(sql(`SELECT membereditsmsattempts FROM _person WHERE id = ${fixture.memberId}`)).toBe("0");
    const resent = gateCode(PROFILE, (await lastSms(request, PROFILE))?.json?.message);
    const wrongResent = (resent[0] === "0" ? "1" : "0") + resent.slice(1);
    for (let attempt = 1; attempt <= 4; attempt++) {
      await page.locator("#member-edit-sms-code").fill(wrongResent);
      await page.getByRole("button", { name: "Verify code", exact: true }).click();
      await expect(page.locator(".alert-error")).toContainText("Incorrect code");
    }
    await page.locator("#member-edit-sms-code").fill(resent);
    await page.getByRole("button", { name: "Verify code", exact: true }).click();
    await expect(page.locator(`input[name="person_${fixture.memberId}email"]`)).toBeVisible();
  });

  test("proof does not survive logout", async ({ page, request }) => {
    fixture = installFixture();
    await memberLogin(page, fixture.email);
    await page.goto("./members/index.php?view=_edit_me");
    await request.delete(mockMeta(PROFILE, "lastPost"));
    await page.locator('button:has-text("Send code")').click();
    const captured = await lastSms(request, PROFILE);
    const code = gateCode(PROFILE, captured?.json?.message);
    await page.locator("#member-edit-sms-code").fill(code);
    await page.locator('button:has-text("Verify code")').click();
    await expect(page.locator(`input[name="person_${fixture.memberId}email"]`)).toBeVisible({ timeout: to(15000) });

    await page.goto("./members/index.php?logout=1");
    await memberLogin(page, fixture.email);
    await page.goto("./members/index.php?view=_edit_me");
    await expect(page.locator('button:has-text("Send code")')).toBeVisible();
    await expect(page.locator('input[name^="person_"]')).toHaveCount(0);
  });
});

test.describe("Group contacts and the Email-all-members mailto", () => {
  test("shared-group contacts: mailto carries real addresses only after verification", async ({ page, request }) => {
    const viewer = installFixture();
    const other = installFixture();
    const relativeEmail = `relative-${randomUUID()}@example.org`;
    let relativeId: number | undefined;
    let groupId: number | undefined;
    try {
      relativeId = Number(sql(`INSERT INTO _person
        (familyid, first_name, last_name, gender, age_bracketid, email, mobile_tel, work_tel,
         remarks, status, congregationid, history)
        SELECT familyid, 'Relative', 'Mailto Fixture', gender, age_bracketid,
          '${relativeEmail}', '0491570197', '', '', status, congregationid, ''
        FROM _person WHERE id = ${viewer.memberId};
        SELECT LAST_INSERT_ID()`));
      groupId = Number(sql(`INSERT INTO _person_group
        (name, categoryid, is_archived, owner, show_add_family, share_member_details, attendance_recording_days)
        VALUES ('Mailto Fixture', NULL, '0', NULL, '0', '1', '');
        SELECT LAST_INSERT_ID()`));
      const membershipStatus = Number(sql("SELECT membership_status FROM person_group_membership LIMIT 1"));
      sql(`INSERT INTO person_group_membership (personid, groupid, membership_status) VALUES
        (${viewer.memberId}, ${groupId}, ${membershipStatus}),
        (${relativeId}, ${groupId}, ${membershipStatus}),
        (${other.memberId}, ${groupId}, ${membershipStatus})`);

      await memberLogin(page, viewer.email);
      // Pre-verification the convenience link is hidden altogether, so no
      // state of the page ever exposes a masked (unusable) mailto.
      await page.goto(`./members/index.php?view=_groups&groupid=${groupId}`);
      const preGate = await page.locator("#body").innerHTML();
      expect(preGate).not.toContain("Email all members");

      await completeEditGate(page, request, GATE);
      await page.goto(`./members/index.php?view=_groups&groupid=${groupId}`);
      const groupHtml = await page.locator("#body").innerHTML();
      const mailto = groupHtml.match(/href="mailto:([^"]+)">Email all members</)?.[1];
      expect(mailto, "Email all members mailto present after verification").toBeTruthy();
      const recipients = mailto!.split(",");
      expect(recipients).toContain(other.email);
      expect(recipients).toContain(viewer.email);
      expect(recipients).toContain(relativeEmail);
      // Masks are a display device only; they must never reach the mailto.
      expect(mailto).not.toMatch(/[*•]/);
    } finally {
      if (groupId !== undefined) {
        sql(`DELETE FROM person_group_membership WHERE groupid = ${groupId};
          DELETE FROM _person_group WHERE id = ${groupId}`);
      }
      if (relativeId !== undefined) sql(`DELETE FROM _person WHERE id = ${relativeId}`);
      cleanup(other);
      cleanup(viewer);
    }
  });
});
