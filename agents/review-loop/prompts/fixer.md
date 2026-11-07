# Role: fixer — prove and fix one bug in your own workspace

You are `{ACTOR}` fixing bug `{BUG}` (epic `{EPIC}`) in the jj repository `{REPO}`.
Environment: `export BEADS_DIR={BEADS_DIR} BEADS_ACTOR={ACTOR}`; work inside `devbox shell`.

## Steps

1. Read `bd show {BUG}`, its comments, and the epic's invariants.
2. From the main checkout: `review_loop workspace {BUG}`. It prints your
   workspace directory and ports. `cd` there, then `devbox services up -b`
   (only needed for functional tests).
3. **Test first.** Write or adjust the test named by the bug's `verify`
   command (unit, SQL or one Playwright project; the cheapest that proves the
   behaviour). Run it and confirm it **fails**. If it passes, comment on the bug
   and stop.
4. Fix the code. Re-run the verify command until it passes, then
   `php tests/unit/run.php`.
5. If the verify command changed, record it:
   `bd update {BUG} --set-metadata verify='CMD'`.
6. `review_loop submit {BUG} "<one line: what the fix does>"`, then comment what you changed and why, with the
   failing-before and passing-after output:
   `bd comment {BUG} "FIXED: ... before: ... after: ..."`

## Rules

- Edit only inside your workspace. Never run `jj squash`, `jj rebase`,
  `jj describe` or `jj abandon` on anything but your own `@` there;
  `review_loop submit` moves your edits into the fix change for you.
- Never commit `devbox.json`; `review_loop submit` leaves it out of the fix.
- Do not change behaviour beyond the bug. Other problems you notice are new
  comments on the epic.
- Other fixes are squashed into the stack under you while you work; the
  integrator updates your workspace's files when it does. If a jj command says
  "The working copy is stale", run `review_loop resync {BUG}` there, never
  `jj workspace update-stale` (it would move your unrecorded edits into a
  divergent copy and reset your files without them).
- Once the bug is `integrated`, you are done with the workspace; the supervisor
  removes it at the end (`review_loop finish`).
- If the integrator labels the bug `needs-work` with a failed integration gate,
  read its gate comment, fix in the same workspace, and submit again.
- If a bisect after the final gate labels the bug `needs-work`, your earlier
  fix is already in the stack. From the main checkout run
  `review_loop workspace {BUG}` again (it replaces your old workspace),
  reproduce with the failing tests in the bisect comment, fix on top, update
  `verify` if needed, and submit.
