import { test, expect } from "../fixtures.js";
import { login } from "../auth.js";

test("report cannot show raw history", async ({ page, baseURL }) => {
  await login(page, "bandleader");
  const body = new URLSearchParams();
  body.set("query_submitted", "1");
  body.set("save_option", "temp");
  body.append("show_fields[]", "p.first_name");
  body.append("show_fields[]", "p.history");
  body.append("show_fields[]", "f.history");
  body.set("sort_by", "p.last_name");
  body.set("group_by", "");
  const response = await page.request.post(`${baseURL}?view=persons__reports&queryid=TEMP`, { data: body });
  expect(response.ok()).toBe(true);
  const report = await response.text();
  expect(report).not.toContain("changed from");
  expect(report).not.toContain("[hidden:");
});

test("report form hides contact fields", async ({ page }) => {
  await login(page, "bandleader");
  await page.goto("./?view=persons__reports&queryid=0&configure=1");

  const hiddenFields = [
    "p.email",
    "p.mobile_tel",
    "p.work_tel",
    "f.address_street",
    "f.address_suburb",
    "f.address_state",
    "f.address_postcode",
    "f.home_tel",
  ];
  for (const field of hiddenFields) {
    await expect(page.locator(`select[name="show_fields[]"] option[value="${field}"]`)).toHaveCount(0);
    await expect(page.locator(`select[name="sort_by"] option[value="${field}"]`)).toHaveCount(0);
    await expect(page.locator(`input[name="enable_rule[]"][value="${field}"]`)).toHaveCount(0);
  }
  await expect(page.locator('select[name="group_by"] option[value="f.address_state"]')).toHaveCount(0);
});
