import { test, expect } from "@playwright/test";
import { spawnSync } from "node:child_process";
import { existsSync, rmSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { SMS_MOCK_BASE } from "../sms/smsmock-url.js";

/**
 * The mock must answer as the live API did. smsmockserver/bin/record replays
 * each provider's cases against the mock's recording-<provider> profile into
 * recordings/<provider>/mockresults/, which must equal the committed live
 * recording in recordings/<provider>/actual/ (normalised files only).
 *
 * After a provider changes its API: re-record actual/ against the live API
 * (see smsmockserver/README.md), review the diff, then fix the mock.
 */

// <root>/tests/functional/smsmock/recordings.spec.ts -> <root>
const PROJECT_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../..");

// Credentials the mock accepts, and a recipient in the same format the live
// recording used.
const MOCK_ENV: Record<string, Record<string, string>> = {
  "5centsmsv5": {
    FIVECENTSMS_APIKEY_ID: "mock",
    FIVECENTSMS_APIKEY: "mock",
    FIVECENTSMS_TEST_NUMBER: "0491570158",
    FIVECENTSMS_TEST_SENDER: "61491570158",
  },
  mobilemessage: {
    MOBILEMESSAGE_USERNAME: "mock",
    MOBILEMESSAGE_PASSWORD: "mock",
    MOBILEMESSAGE_TEST_NUMBER: "0491570158",
  },
};

for (const [provider, env] of Object.entries(MOCK_ENV)) {
  test(`${provider}: the mock answers as the live API did`, () => {
    const dir = path.join(PROJECT_ROOT, "smsmockserver/recordings", provider);
    const actual = path.join(dir, "actual");
    const mockresults = path.join(dir, "mockresults");
    rmSync(mockresults, { recursive: true, force: true });

    const args = [
      "smsmockserver/bin/record",
      provider,
      `${SMS_MOCK_BASE}/tests/functional/smsmock/recording-${provider}`,
      mockresults,
    ];
    // Same cases as the live recording: the real-send ones only if it has them.
    if (existsSync(path.join(actual, "send"))) args.push("--send");
    const record = spawnSync("php", args, { cwd: PROJECT_ROOT, env: { ...process.env, ...env }, encoding: "utf8" });
    expect(record.status, `bin/record failed:\n${record.stderr}${record.stdout}`).toBe(0);

    const diff = spawnSync("diff", ["-ru", "--exclude=*.raw.json", actual, mockresults], { encoding: "utf8" });
    expect(diff.stdout, "mock replies differ from the live recording (actual/ vs mockresults/)").toBe("");
    expect(diff.status).toBe(0);
  });
}
