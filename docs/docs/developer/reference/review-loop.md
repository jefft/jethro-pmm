---
title: Review loop — ledger, final gate, bisect, cleanup
sidebar_position: 20
---

# Review loop: ledger, final gate, bisect and cleanup

Design notes for `bin/review_loop`. The workflow, roles and rules are in
`agents/review-loop/README.md`; the `/deepreview` skill drives it.

## Functional tests run once per round

Each `review_loop integrate` gates tip + fix with `jethro_gate --no-functests`
(conflicts, markers, `php -l`, unit, upgrades) plus the bug's `verify` command
unless it runs Playwright (`*playwright*` or `*functests*`). The Playwright
suite, about 2.3 minutes, runs only in `review_loop final EPIC`, on the tip after
the round's last integration. Before this change every integration ran the full
suite (30 runs, about 69 minutes, in one review).

## Integration ledger

`integrate` appends one line per integration to
`<beads home>/runs/EPIC/integrations.tsv`: seq, bug, fix change, target, the
integrator working copy's commit ID before the trial and after the squash, time,
actor. Those working-copy commits hold the whole tip (plus the checkout's
`devbox.json`), so they work for a merge-shaped tip too. They are rewritten by
later squashes but stay readable by commit ID. The bug gets
`integration_seq=N`; a passing `final` sets the epic's `final_passed_seq`, and
closers only close bugs at or below it.

## Bisect

On a failing final gate, `final` saves `tests/functional/test-results/.last-run.json`
and bisects the ledger entries after `final_passed_seq`:

- States: before the first entry, after each entry, plus a pseudo-state wherever
  the stack changed outside `review_loop` between entries (or after the last).
  A culprit pseudo-state is reported on the epic, not sent to a fixer.
- Probe: rerun only the failed Playwright tests
  (`--last-failed --last-failed-file`, `--pass-with-no-tests` so a test that does
  not exist yet counts as passing) and the failed non-Playwright verify commands
  of bugs already integrated in that state (a verify command fails before its fix
  by design).
- The last state is probed first (does the failure reproduce alone?), then the
  first (is it older than the round?); either result ends the bisect as
  inconclusive.
- A probe is a throwaway child of the integrator's working copy with
  `jj restore --from <state>`; old commits are only read. Checking out or
  building on a rewritten commit would make it visible again as a divergent
  change.

The culprit bug gets `needs-work`, loses `integrated` and `fix_change`, and a
comment with the passing and failing states and the failing tests. Its fixer
runs `review_loop workspace BUG` again, which replaces the stale old workspace.

## Cleanup

A fixer workspace goes stale when its fix is squashed. Running jj there
afterwards can recreate the pre-rewrite stack commits as divergent copies, which
stay visible for as long as the workspace exists. `review_loop finish EPIC`:

1. For each `fix-EPIC.*` workspace: collects the commits only that workspace
   reaches (`::wc ~ ::(visible_heads() ~ wc)`), refuses if any changes more than
   `devbox.json` (unintegrated work), otherwise forgets the workspace, abandons
   those commits and deletes the directory. A stale workspace is not snapshotted,
   so edits jj has not recorded there are not checked.
2. Deletes `<checkout>-EPIC.N` directories of workspaces forgotten earlier.
3. Abandons leftover `local: fix-EPIC.N ports`, `trial: integrate EPIC.N` and
   `bisect: EPIC state K` commits that no working copy uses, that have no other
   children and that change nothing beyond `devbox.json`.
4. Checks that no such workspace, directory or divergent commit the workspaces
   held remains, and lists unintegrated `Fix EPIC.N` changes it kept.

`--dry-run` prints the plan without changing anything (it still snapshots
non-stale working copies). `review_loop cleanup BUG` applies step 1 to one bug.
