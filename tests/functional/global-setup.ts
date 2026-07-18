import { execFileSync } from "node:child_process";
import { execSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

// <root>/tests/functional/global-setup.ts -> <root>
const PROJECT_ROOT = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  "../..",
);
/**
 * Suite-wide setup, run once before any test.
 *
 * Compiles the css/js bundles for the current JETHRO_VERSION.
 *
 * jethro_compile is resolved from PATH (devbox puts devbox.d/bin on PATH) and,
 * given no arguments, defaults to $JETHRO_VERSION. We run it from the project
 * root so it can find resources/{css,js}. A non-zero exit throws, aborting the
 * run before any test.
 *
 * Cancels SMS deliveries left in 'scheduled' by earlier runs of this suite.
 *
 * A scheduled delivery renders a Datastar polling span on every page that
 * lists it — `?call=sms_info`, as often as every 2 seconds per batch for the
 * first hour after its send time (see smsScheduledPollIntervalSecs()) — and
 * each first-in-batch poll makes an HTTP call from PHP back into the same
 * php-fpm pool that is serving the pages. The specs that schedule sends
 * (sms-cooloff, sms-schedule-and-cancel, sms-messages-page) leave those rows
 * behind, so without this the polling load grows with every run until the pool
 * saturates and unrelated tests start timing out.
 *
 * functest_databases_setup loads demo data that contains no scheduled
 * deliveries, so anything still scheduled at suite start is a leftover.
 * (`mariadb` needs no credentials: devbox.d/mariadb/my.cnf defaults to the
 * jethro user, and devbox.d/bin is on PATH in the devbox shell.)
 */
export default function globalSetup(): void {
  const sql = "UPDATE smsdelivery SET status='cancelled' WHERE status='scheduled'";
  execSync(`mariadb jethro_functest -e "${sql}"`);
  execFileSync("jethro_compile", [], { cwd: PROJECT_ROOT, stdio: "inherit" });
}
