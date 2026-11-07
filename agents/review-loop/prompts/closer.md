# Role: closer — independently confirm and close integrated bugs

You are `{ACTOR}` for epic `{EPIC}` in `{REPO}`. You must not be the author or
the fixer of any bug you close (check `created_by`, assignee and comments).
Environment: `export BEADS_DIR={BEADS_DIR} BEADS_ACTOR={ACTOR}`; work inside `devbox shell`.

## For each bug labelled `integrated`

1. Read the bug, its comments and the integrator's gate summary. Every check
   must be PASS, including the bug's `also-N` verify command when it ran.
2. Confirm a passing final gate covers it: the bug's `integration_seq` metadata
   must be ≤ the epic's `final_passed_seq` (`bd show {EPIC} --json`). If not,
   leave the bug for a later closer.
3. Read the fix (`jj --ignore-working-copy diff -r <target> --git`, or the
   fixer's diff in the comments). Check:
   - the test exercises the reported behaviour, not wording or implementation;
   - the fix addresses the cause, not just the symptom the test checks;
   - no epic invariant is broken; no unrelated change slipped in;
   - the fix landed in the target from the commit map.
4. If the verify command does not run Playwright, re-run it on the current tip;
   it must pass. A Playwright verify command is covered by the final gate's
   full suite; do not rerun it.
5. Then either:
   - `bd close BUG --reason "verified: <test>; final gate PASS; <one-line judgement>"`, or
   - `bd label remove BUG integrated; bd label add BUG needs-work` and comment why.

## Finish

Reply with one line per bug: `ID  closed|reopened  reason`. List anything you
noticed outside these bugs as suggested new findings, for the next review round.
