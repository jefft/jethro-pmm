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

## Trials, probes and interrupted runs

`integrate`'s trial is `jj new <integrator working copy> <fix>`; a bisect probe
is a child of the working copy with a state's tree restored into it. Both name
their home (and the probe its state) in `review_loop-home:` /
`review_loop-tree:` description trailers. Keeping the working copy a parent
matters: jj abandons an empty, undescribed working-copy commit as soon as `@`
moves off it, and the earlier trial (`jj new <tip parents> <fix>`) then had
nothing to return to.

An EXIT trap (with INT/TERM/HUP turned into exits) returns to the home and
abandons the trial or probe before releasing the merge slot. A SIGKILL skips
the trap; `recover_interrupted`, run when the next integrator command takes the
slot, finds the `trial:`/`bisect:` commit at `@`, and returns and abandons it if
it still matches what was created (a trial: no diff against its parents; a
probe: its recorded tree). Otherwise it stops: in the jr-trh review a trial
outlived its integrate run as `@`, conflicts were resolved in it, and later
integrations took it as their home, so review edits ended up in a commit named
"trial: integrate jr-trh.11".

After the squash, only conflicts in the stack (`target:: & ::@`) revert it.
Other bugs' pending fix changes are also descendants of the target; one that
now conflicts is labelled `needs-work` with a comment instead of blocking the
integration (checking all of `target::` reverted a sound fix whenever another
pending fix edited adjacent lines of the same file).

## Stale fixer workspaces

Every fixer workspace is built on its bug's target, so squashing any fix into
that target (or an earlier commit) rewrites the other fixers' working-copy
commits from the integrator's workspace, and their working copies go stale. If
a stale workspace holds edits jj has not recorded,
`jj workspace update-stale` snapshots them into a second copy of the
working-copy commit (jj already rebases it onto the new parents) and resets the
files to the copy without them: a divergent change, and the fixer's edits
vanish from disk (observed with jj 0.46).

`heal_workspace` in `bin/review_loop` avoids both: it runs `jj status` (records
edits; works while current), or update-stale and then, if the commit forked,
`jj edit`s the copy with the edits and abandons the other. `integrate` heals
every other workspace whose working copy is the target or built on it
(`working_copies() & target::`: fixers on that target, and anyone else's) just
before the squash and again just after it (or after reverting it), so they
keep working on the current stack; only edits
made in the sub-second gap between the two can still fork, and that heal
repairs them. `review_loop resync BUG` runs the same heal for a fixer whose
workspace went stale another way.

## Cleanup

Leftover fixer workspaces and their port, trial and bisect commits clutter the
repository, and a workspace left stale keeps pre-rewrite copies of the stack
visible as divergent changes. `review_loop finish EPIC`:

1. For each `fix-EPIC.*` workspace: heals it, collects the commits only that
   workspace reaches (`::wc ~ ::(visible_heads() ~ wc)`), refuses if any
   changes more than `devbox.json` (unintegrated work), otherwise forgets the
   workspace, abandons those commits and deletes the directory.
2. Deletes `<checkout>-EPIC.N` directories of workspaces forgotten earlier.
3. Abandons leftover `local: fix-EPIC.N ports`, `trial: integrate EPIC.N` and
   `bisect: EPIC state K` commits that no working copy uses, that have no other
   children and that change nothing beyond `devbox.json`.
4. Checks that no such workspace, directory or divergent commit the workspaces
   held remains, and lists unintegrated `Fix EPIC.N` changes it kept.

`--dry-run` prints the plan without healing or abandoning anything (it still
records edits of current working copies). `review_loop cleanup BUG` applies
step 1 to one bug.

## Helper scripts

`devbox.d/bin/` on this branch carries the gate the review loop calls:

- `jethro_gate [--from REV] [--no-functests] [--functests-args ARGS] [--also CMD]...` — conflicts, conflict
  markers, `php -l` on changed files, unit tests, `jethro_check_upgrades`, the functional suite and each
  `--also` command; it keeps going after a failure, logs under `.devbox/run/gate/` and prints a summary.
- `jethro_check_upgrades [--from VERSION]` — applies the upgrade scripts to the previous release's demo data
  and to a fresh install, each twice, failing if a script errors or a second pass changes anything.
- `jethro_install_db DB` — runs the web installer from the command line into an empty database.

They need the checked-out application (templates, upgrade SQL, unit tests), so a checkout must also contain
the Jethro tree — every real checkout does; only this branch's bare history lacks it.
