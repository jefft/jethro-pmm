import { defineConfig } from "@playwright/test";

/**
 * Playwright functional tests for Jethro.
 *
 * The functest Jethro server runs on http://127.0.0.1:${FUNCTEST_WEB_PORT:-8089}
 * (see process-compose.yml); port is read from FUNCTEST_WEB_PORT or defaults to 8089.
 * Each SMS scenario gets its own Jethro instance at /tests/functional/sms/{name}/.
 * Tests assume the database has been loaded with demo data by functest_databases_setup.
 */

const FUNCTEST_HOST = process.env.FUNCTEST_WEB_HOST || "127.0.0.1";
const PORT = process.env.FUNCTEST_WEB_PORT || "8089";
const HOST = `http://${FUNCTEST_HOST}:${PORT}`;

const SMS_SCENARIOS = [
  "sms-bulk",
  // Sends, like sms-bulk, but on its own mock profile: both specs assert on
  // the mock's per-profile lastPost recording and run in parallel.
  "sms-bulk-interactive",
  "sms-sender-options",
  "sms-sender-default",
  "sms-unicode-when-free",
  // Fails unless MySQL connection charset is changed from utf8 to utf8mb4 in jethrodb.php
  //"sms-unicode-always",
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
  // Restricted staff persona 6pmonly sees only deliveries to persons it may see.
  "sms-restricted-visibility",
  "5centsms-senderid-cache-test",
  "sms-wrong-profile",
  // Brown family members share one mobile: one SMS and one preview row per number.
  "sms-shared-mobile",
  // Default 30s cool-off: 'scheduled' on the Messages page, then 'sent' via polling.
  "sms-scheduled-status",
  // A sent message is polled on to delivered (not left at ✓).
  "sms-delivered-status",
];

// Provider-sensitive scenarios (schedule/cancel, cool-off, status lifecycle,
// send failure, opt-outs, sender registration, history) also run against
// 5CentSMS v5: the same spec, with its own <name>-v5.conf and mock profile.
// sms/scenario.ts reads each project's metadata.smsProvider.
const PROVIDER_MATRIX = [
  "sms-schedule-and-cancel",
  "sms-cooloff",
  "sms-scheduled-status",
  "sms-delivered-status",
  "sms-failed-send",
  "sms-opted-out-recipient",
  "sms-sender-number-registration",
  "sms-admin-history-filters",
];
 
export default defineConfig({
  globalSetup: "./global-setup.ts",
  testDir: ".",
  // Cancels the scheduled-SMS polling backlog left by previous runs.
  globalSetup: "./global-setup.ts",
  // Under the Inspector (--debug / PWDEBUG=1) disable all timeouts so paused
  // PHP breakpoints (Xdebug step-debugging) can't fail the test mid-session.
  timeout: process.env.PWDEBUG ? 0 : 30000,
  fullyParallel: true,
  workers: 8,
  expect: { timeout: process.env.PWDEBUG ? 0 : 10000 },
  use: { browserName: "chromium", baseURL: `${HOST}/`, screenshot: "only-on-failure" },
  projects: [
    ...SMS_SCENARIOS.map((name) => ({
      name,
      use: { baseURL: `${HOST}/tests/functional/sms/${name}/` },
      testMatch: [`sms/${name}.spec.ts`],
      ...(PROVIDER_MATRIX.includes(name) ? { metadata: { smsProvider: "cellcast" } } : {}),
    })),
    ...PROVIDER_MATRIX.map((name) => ({
      name: `${name}-v5`,
      use: { baseURL: `${HOST}/tests/functional/sms/${name}-v5/` },
      testMatch: [`sms/${name}.spec.ts`],
      metadata: { smsProvider: "5centsmsv5" },
    })),
    { name: "lookaround", testMatch: ["lookaround/lookaround.spec.ts"] },
    {
      name: "walkthrough",
      testMatch: ["walkthrough/walkthrough.spec.ts"],
      // Served under the /tests/functional/walkthrough/walkthrough/ prefix; the
      // walkthrough.conf scenario (required by the functest conf.php) points
      // the app at the empty jethro_functest_walkthrough database.
      use: { baseURL: `${HOST}/tests/functional/walkthrough/` },
    },
  ],
});
