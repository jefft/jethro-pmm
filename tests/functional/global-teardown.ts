import { releaseRunLock } from "./global-setup";

/**
 * Suite-wide teardown: releases the run lock taken by global-setup.ts and fails
 * the run as INVALID if the functest databases were recreated mid-run (see
 * releaseRunLock()).
 */
export default async function globalTeardown() {
  releaseRunLock();
}
