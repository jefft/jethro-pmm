import { execFileSync, spawnSync } from "node:child_process";
import { mkdirSync, readFileSync, realpathSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import { setTimeout as sleep } from "node:timers/promises";
import { fileURLToPath } from "node:url";

// <root>/tests/functional/global-setup.ts -> <root>
const PROJECT_ROOT = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  "../..",
);
// Mirrors HOST in playwright.config.ts, which reads the same env vars (and
// already refuses to load without FUNCTEST_WEB_PORT).
const FUNCTEST_HOST = process.env.FUNCTEST_WEB_HOST || "127.0.0.1";
const FUNCTEST_PORT = process.env.FUNCTEST_WEB_PORT;
if (!FUNCTEST_PORT) throw new Error("FUNCTEST_WEB_PORT is not set: run inside devbox");
const FUNCTEST_BASE_URL = `http://${FUNCTEST_HOST}:${FUNCTEST_PORT}`;

/**
 * FUNCTEST_WEB_PORT is derived from the checkout's path (`jethro_utils
 * freeport functests`), which does not guarantee uniqueness, so a
 * functest_jethro_server from another checkout can silently own the port.
 * This checkout's own functest nginx then fails to bind and gives up
 * (process-compose restart cap), and the suite would run against the wrong
 * code and database — with confusing symptoms like "table sms_note doesn't
 * exist" fatals from a schema this tree doesn't have.
 *
 * The functest webroot serves functestinfo.php, which prints the serving
 * checkout's path as JSON. Refuse to run unless it matches this checkout.
 */
async function assertFunctestServerIsThisCheckout() {
  let res: Response;
  try {
    res = await fetch(`${FUNCTEST_BASE_URL}/functestinfo.php`, { signal: AbortSignal.timeout(10_000) });
  } catch {
    throw new Error(
      `Functest server not reachable at ${FUNCTEST_BASE_URL}. ` +
        "Start it with: devbox services start functest_jethro_server",
    );
  }
  let servedPath: string | undefined;
  if (res.ok) {
    const info = (await res.json().catch(() => null)) as { instance_path?: unknown } | null;
    servedPath = typeof info?.instance_path === "string" ? info.instance_path : undefined;
  }
  if (!servedPath) {
    throw new Error(
      `The server at ${FUNCTEST_BASE_URL} did not report its Jethro instance path ` +
        `(HTTP ${res.status}, or unparseable JSON, from /functestinfo.php). It is probably a ` +
        "checkout older than functestinfo.php, or not a functest Jethro at all. Stop whatever " +
        `owns port ${FUNCTEST_PORT}, then: devbox services start functest_jethro_server`,
    );
  }
  const expectedPath = realpathSync(PROJECT_ROOT);
  if (servedPath !== expectedPath) {
    throw new Error(
      `Port hijack: the functest server at ${FUNCTEST_BASE_URL} is serving Jethro from ` +
        `${servedPath}, but these tests are for ${expectedPath}. Tests would run against ` +
        "the wrong code and database. To fix:\n" +
        `  devbox -c ${servedPath} services stop functest_jethro_server\n` +
        "  devbox services start functest_jethro_server",
    );
  }
}

const DB_SETUP_SERVICE = "functest_databases_setup";
const DB_SETUP_TIMEOUT_MS = 120_000;

/**
 * Run this checkout's devbox launcher, so the services of this tree are the
 * ones used. Returns stdout + stderr: `devbox services ls` prints its table to
 * stderr. Throws on a non-zero exit.
 */
function devbox(...args: string[]): string {
  const result = spawnSync(path.join(PROJECT_ROOT, "devbox"), args, { cwd: PROJECT_ROOT, encoding: "utf8" });
  const output = `${result.stdout ?? ""}${result.stderr ?? ""}`;
  if (result.error || result.status !== 0) {
    throw new Error(`devbox ${args.join(" ")} failed (exit ${result.status}): ${result.error?.message ?? output}`);
  }
  return output;
}

/**
 * The DB_SETUP_SERVICE row of `devbox services ls`:
 * PID NAME NAMESPACE STATUS AGE HEALTH RESTARTS EXIT-CODE.
 */
function dbSetupStatus(): { pid: string; status: string; exitCode: string } {
  const row = devbox("services", "ls")
    .split("\n")
    .map((line) => line.trim().split(/\s+/))
    .find((cols) => cols[1] === DB_SETUP_SERVICE);
  if (!row) {
    throw new Error(`${DB_SETUP_SERVICE} not listed by 'devbox services ls'. Start services with: devbox services up -b`);
  }
  return { pid: row[0], status: row[3], exitCode: row[row.length - 1] };
}

/**
 * Reset the functest databases by re-running the functest_databases_setup
 * process-compose job — the single definition of "fresh test data"
 * (process-compose.yml). `devbox services restart` returns as soon as the job
 * is relaunched, and HEALTH stays Ready from the previous run, so wait until a
 * new PID reports Completed, and fail on a non-zero exit code. Returns that
 * PID, which identifies this reset (see releaseRunLock()).
 */
async function resetFunctestDatabases(): Promise<string> {
  const before = dbSetupStatus().pid;
  devbox("services", "restart", DB_SETUP_SERVICE);
  const deadline = Date.now() + DB_SETUP_TIMEOUT_MS;
  for (;;) {
    const { pid, status, exitCode } = dbSetupStatus();
    if (pid !== before && status === "Completed") {
      if (exitCode !== "0") {
        throw new Error(`${DB_SETUP_SERVICE} failed (exit code ${exitCode}); see: devbox services attach`);
      }
      return pid;
    }
    if (Date.now() > deadline) {
      throw new Error(`${DB_SETUP_SERVICE} did not complete within ${DB_SETUP_TIMEOUT_MS / 1000}s (status ${status})`);
    }
    await sleep(250);
  }
}

/** Exclusive per-checkout run lock; see acquireRunLock(). */
const RUN_LOCK = path.join(PROJECT_ROOT, ".devbox", "run", "functests.lock");

/** Contents of RUN_LOCK. dbSetupPid is null until the databases are reset. */
interface RunLock {
  pid: number;
  started: string;
  dbSetupPid: string | null;
}

/** True if a process with this PID exists (EPERM still means it exists). */
function isAlive(pid: number): boolean {
  try {
    process.kill(pid, 0);
    return true;
  } catch (err) {
    return (err as NodeJS.ErrnoException).code === "EPERM";
  }
}

function readRunLock(): RunLock | null {
  try {
    return JSON.parse(readFileSync(RUN_LOCK, "utf8")) as RunLock;
  } catch {
    return null;
  }
}

/**
 * Take the per-checkout run lock, or refuse to start.
 *
 * All runs of one checkout share its functest databases and each run's setup
 * recreates them, so an overlapping run would wipe another run's data mid-test
 * and both would report misleading failures. The lock is created atomically
 * ('wx'); a lock left by a dead runner (crash, SIGKILL) is taken over. This
 * process is the Playwright runner, alive for the whole run. See
 * DEVELOPMENT_FUNCTESTS.md "One suite run at a time".
 */
function acquireRunLock(): void {
  mkdirSync(path.dirname(RUN_LOCK), { recursive: true });
  const mine: RunLock = { pid: process.pid, started: new Date().toISOString(), dbSetupPid: null };
  for (let attempt = 0; attempt < 2; attempt++) {
    try {
      writeFileSync(RUN_LOCK, JSON.stringify(mine), { flag: "wx" });
      return;
    } catch (err) {
      if ((err as NodeJS.ErrnoException).code !== "EEXIST") throw err;
    }
    const held = readRunLock();
    if (held && isAlive(held.pid)) {
      throw new Error(
        `Another functest run (pid ${held.pid}, started ${held.started}) is using this checkout's ` +
          "functest databases; this run's setup would recreate them under it. Wait for it to finish, " +
          `or stop it. If no run is active, delete ${RUN_LOCK}.`,
      );
    }
    rmSync(RUN_LOCK, { force: true });
  }
  throw new Error(`Could not take the functest run lock ${RUN_LOCK}; another run is starting concurrently.`);
}

/** Record which functest_databases_setup PID this run's data came from. */
function recordDbSetupPid(dbSetupPid: string): void {
  const lock = readRunLock();
  if (lock?.pid !== process.pid) throw new Error(`Functest run lock ${RUN_LOCK} was taken over during setup`);
  writeFileSync(RUN_LOCK, JSON.stringify({ ...lock, dbSetupPid }));
}

/**
 * Release the run lock (global-teardown.ts) and report whether the run's data
 * survived: throws if functest_databases_setup has run again since this run's
 * reset — an out-of-band restart or a run that bypassed the lock recreated the
 * databases mid-run, so test results are not trustworthy.
 */
export function releaseRunLock(): void {
  const lock = readRunLock();
  if (lock?.pid !== process.pid) return;
  rmSync(RUN_LOCK, { force: true });
  if (lock.dbSetupPid === null) return;
  const now = dbSetupStatus().pid;
  if (now !== lock.dbSetupPid) {
    throw new Error(
      `RUN INVALID: ${DB_SETUP_SERVICE} was re-run during this suite (pid ${lock.dbSetupPid} -> ${now}), ` +
        "recreating the functest databases mid-run. Failures above may be caused by that, not by the code. " +
        "Re-run the suite with nothing else using this checkout's functest services.",
    );
  }
}

/**
 * Suite-wide setup, run once before any test.
 *
 * Refuses to run against another checkout's functest server or while another
 * run of this checkout holds the run lock (acquireRunLock()), then resets the
 * functest databases (resetFunctestDatabases()) so every run starts from the
 * same data, and compiles the css/js bundles for the current JETHRO_VERSION.
 *
 * jethro_compile is resolved from PATH (devbox puts devbox.d/bin on PATH) and,
 * given no arguments, defaults to $JETHRO_VERSION. We run it from the project
 * root so it can find resources/{css,js}. A non-zero exit throws, aborting the
 * run before any test.
 */
export default async function globalSetup() {
  await assertFunctestServerIsThisCheckout();
  acquireRunLock();
  try {
    recordDbSetupPid(await resetFunctestDatabases());
    execFileSync("jethro_compile", [], { cwd: PROJECT_ROOT, stdio: "inherit" });
  } catch (err) {
    rmSync(RUN_LOCK, { force: true });
    throw err;
  }
}
