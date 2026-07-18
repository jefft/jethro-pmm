import { defineConfig } from "@playwright/test";

/**
 * Playwright functional tests for Jethro.
 *
 * The functest Jethro server runs on http://127.0.0.1:${FUNCTEST_WEB_PORT:-8089}
 * (see process-compose.yml); port is read from FUNCTEST_WEB_PORT or defaults to 8089.
 * Each SMS scenario gets its own Jethro instance at /tests/functional/sms/{name}/.
 * Tests assume the database has been loaded with demo data by functest_database_setup.
 */

const FUNCTEST_HOST = process.env.FUNCTEST_WEB_HOST || "127.0.0.1";
const PORT = process.env.FUNCTEST_WEB_PORT || "8089";
const HOST = `http://${FUNCTEST_HOST}:${PORT}`;

const SMS_SCENARIOS = [
  "sms-bulk",
  "sms-sender-options",
  "sms-sender-default",
  "sms-unicode-when-free",
  "sms-unicode-always",
  "sms-unicode-never",
  "sms-admin-history-filters",
  "sms-send-single",
  "sms-schedule-and-cancel",
  "sms-per-recipient-override",
  "sms-opted-out-recipient",
  "sms-url-shortening-preview",
  "sms-failed-send",
  "sms-sender-number-registration",
  "sms-2fa",
  "sms-cooloff",
  "sms-messages-page",
  "5centsms-senderid-cache-test",
  "sms-wrong-profile",
];
 
export default defineConfig({
  testDir: ".",
  // Under the Inspector (--debug / PWDEBUG=1) disable all timeouts so paused
  // PHP breakpoints (Xdebug step-debugging) can't fail the test mid-session.
  timeout: process.env.PWDEBUG ? 0 : 30000,
  fullyParallel: true,
  workers: 4,
  expect: { timeout: process.env.PWDEBUG ? 0 : 10000 },
  use: { browserName: "chromium", baseURL: `${HOST}/` },
  projects: [
    ...SMS_SCENARIOS.map((name) => ({
      name,
      use: { baseURL: `${HOST}/tests/functional/sms/${name}/` },
      testMatch: [`sms/${name}.spec.ts`],
    })),
    { name: "login", testMatch: ["tests/login.spec.ts"] },
  ],
});
