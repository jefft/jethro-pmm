# Multi-agent review loop

How several agents review a jj stack, file and discuss bugs, fix them in
parallel, and integrate the fixes one at a time without losing or breaking
each other's work. Bugs live in a beads database shared by every agent and
every jj workspace; the error-prone jj steps are scripted in `bin/review_loop`.

```
supervisor ─ epic ─┬─ reviewers (parallel) ── file bug beads
                   ├─ cross-review (parallel) ── comment, dedupe, close invalid
                   ├─ fixers (parallel, one jj workspace each) ── failing test, fix, submit
                   ├─ integrator (one at a time, merge slot) ── fast gate tip+fix, squash, ledger
                   ├─ final gate (integrator, once per round) ── full suite; on failure bisect,
                   │                                             culprit back to its fixer
                   ├─ closer (not author, not fixer) ── check test + final gate, close
                   └─ finish (supervisor, last) ── remove fixer workspaces and leftover commits
```

## Hard rules

1. **Only the integrator rewrites the shared stack.** Reviewers, fixers and
   closers never run `jj squash`, `jj rebase`, `jj describe`, `jj abandon` or
   `jj op restore` on commits outside their own fix workspace.
2. **A fix starts with a test that fails.** The bug's `verify` command must
   fail on the target commit before the fix and pass after it.
3. **One integration at a time.** `review_loop integrate`, `final` and
   `bisect` hold the beads merge slot; a second integrator waits or retries.
4. **A bug is closed only on evidence**: integrated (label `integrated`), its
   integration covered by a passing final gate (`integration_seq` ≤ the epic's
   `final_passed_seq`), and a closer who neither filed nor fixed it.
5. **Invariants in the epic win.** If a fix would break one, comment on the bug
   and stop; the supervisor decides.
6. **Never `jj workspace update-stale` in a fixer workspace.** Squashing a fix
   rewrites every fixer workspace built on that target. If one holds edits jj
   has not recorded, update-stale moves them into a divergent copy and resets
   the files without them. `review_loop resync BUG` recovers instead.
7. **Cleanup is part of done.** `review_loop finish EPIC` must report `clean`;
   leftover workspaces and their commits otherwise clutter the repository.

## Setup (supervisor, once)

```sh
review_loop init                      # prints: export BEADS_DIR=~/beads/jethro-review/.beads
export BEADS_DIR=~/beads/jethro-review/.beads BEADS_ACTOR=supervisor
bd create "Review: <stack>" -t epic --body-file agents/review-loop/templates/epic.md   # then edit it
```

Fill in the epic's invariants, commit map (which change a fix for each area
goes in) and test commands before starting reviewers. Every agent gets
`BEADS_DIR`, its own `BEADS_ACTOR`, the epic ID and its role prompt from
`agents/review-loop/prompts/`.

## Bug life cycle

| Step | Who | Command | Result |
|---|---|---|---|
| File | reviewer | `bd create TITLE -t bug --parent EPIC --body-file BUG.md --metadata '{"target":"CHANGE","verify":"CMD"}'` | open bug |
| Discuss | cross-reviewers | `bd comment BUG ...` | agree, dispute, `bd duplicate BUG --of OTHER`, or group under a root-cause parent |
| Triage | author | `bd close BUG --reason invalid` if disputed successfully | |
| Fix | fixer | `review_loop workspace BUG` → test, fix → `review_loop submit BUG "one-line fix summary"` | label `ready-to-integrate` |
| Integrate | integrator | `review_loop integrate BUG` | label `integrated` (ledger entry `#N`) or `needs-work`, gate summary comment |
| Final gate | integrator | `review_loop final EPIC`, once every ready fix of the round is integrated | epic `final_passed_seq`, or bisect: culprit `needs-work` |
| Close | closer | checks, then `bd close BUG --reason "..."` | closed |
| Clean up | supervisor | `review_loop finish EPIC`, after the last round | workspaces and leftover commits gone |

## Integration, final gate and bisect

Integration runs no functional tests: each fix gets the fast checks
(conflicts, markers, `php -l`, unit, upgrades) plus its `verify` command
unless that runs Playwright. The full suite runs once, in `review_loop final`,
on the tip after the round's last integration. Every integration is appended
to a ledger (`runs/EPIC/integrations.tsv` beside the beads database) with the
tip's commit before and after it, so the merge order and every intermediate
state stay reproducible after later squashes rewrite the stack.

When the final gate fails, `final` bisects the ledger entries since the last
passing final gate: it reruns only the failed Playwright tests
(`--last-failed`) and failed verify commands on restored intermediate states,
finds the first integration after which they fail, labels that bug
`needs-work`, clears its `fix_change` and comments the evidence. Its fixer runs
`review_loop workspace BUG` again (a fresh workspace on the target, which
already holds the earlier fix), fixes on top and submits; the integrator
integrates it and reruns `final`. A failure that does not reproduce alone, or
already occurs before the first integration, is reported on the epic instead.
`review_loop bisect EPIC` redoes the bisect of the last failed final gate.

## What the scripts guarantee

- `review_loop workspace` creates a jj workspace beside the main checkout with
  two changes: the **fix change** on the bug's target commit, and an empty
  **local change** on top for the fixer to edit in. Web ports and the MailPit
  socket directory need no per-workspace edits: devbox.json's init hook resolves `_default_` ports from
  the workspace's own path (with explicit port overrides allowed; distinct paths
  normally give distinct ports; a collision makes the second nginx fail to
  bind). Creation holds a lock, and the new workspace's files are forced to the
  target's tree: a fresh git worktree starts from git HEAD, and its delta would
  otherwise land in the fix change. Separate checkouts already have separate
  functest databases (DEVELOPMENT_FUNCTESTS.md). For a fix sent back by bisect
  it first removes the bug's stale old workspace.
- `review_loop submit BUG SUMMARY` moves every edit except `devbox.json` into
  the fix change and records SUMMARY (one line saying what the fix does) as the
  bug's `fix_summary`.
- `review_loop integrate` takes the merge slot, builds a **trial** working
  copy (a merge of the integrator's working copy and the fix), runs
  `jethro_gate --no-functests` (plus the `verify` command unless it runs
  Playwright) on it, returns to the working copy and abandons the trial, and
  only if the gate passed squashes the fix into its target and records it in
  the ledger. The squash keeps the target's description and appends
  `Review: BUG — SUMMARY` to a block of such lines at its end, one per bug (a
  rework replaces its bug's line), so each reviewed change says what review
  changed in it. A failed gate rewrites nothing. A squash that leaves conflicts in
  the stack is undone with `jj op revert` of that one operation, never
  `jj op restore`, which would roll back other agents' work. A pending fix of
  another bug that now conflicts does not block it: that bug gets `needs-work`
  and a comment. A fix that conflicts with the tip itself (a later commit
  rewrote the same lines) is reported as such, not as a failed gate, with the
  rebase the fixer needs to do.
  Just before and after the squash (and after a revert) it records the edits of
  every other workspace whose working copy sits on the target or a later commit
  (fixers', and any person's) and updates its files to the rewritten commits,
  so they keep working on the current stack instead of a stale copy; a
  workspace that went stale with edits jj had not recorded keeps them (see
  `resync`). Do not keep your own working copy *inside* a reviewed commit
  during a run: integrations rewrite it, so start a new commit on top instead.
- An integrator command interrupted by an error, Ctrl-C or SIGTERM still
  returns to the working copy, abandons its trial or probe and releases the
  merge slot. After a SIGKILL the next `integrate`, `final` or `bisect` finds
  the trial or probe as `@` and does the same, unless someone edited it: then
  it stops and says so. Never work in a `trial:` or `bisect:` commit. A killed
  run cannot release the merge slot: once its process is gone, free it with
  `bd merge-slot release --holder ACTOR`.
- `review_loop resync BUG`, run inside the fixer's workspace when jj reports it
  stale, runs update-stale and, if that forked the working-copy commit, keeps
  the copy holding the unrecorded edits as `@` and abandons the other.
- `review_loop final` runs the full `jethro_gate` on the tip, with the
  non-Playwright verify commands of every integrated bug; bisect probes restore
  recorded trees into a throwaway child of the working copy and never check out
  old commits, so rewritten commits never become visible (divergent) again.
- `review_loop finish` forgets every `fix-EPIC.*` workspace, deletes its
  directory, abandons leftover `local: fix-…`, `trial: integrate …` and
  `bisect: …` commits that change nothing beyond `devbox.json`, abandons empty
  `Fix EPIC.N` changes left behind by squashes, and checks that none remain,
  including divergent copies those workspaces held. It keeps and reports
  anything holding other changes: unintegrated work. `--dry-run` shows the plan.
- `integrate`, `final` and `bisect` refuse to start unless `devbox.d/bin/jethro_gate`
  exists in the checkout and is on PATH: without it every gate check silently
  passes, and the bug is then rejected for the wrong reason.
- `jethro_gate` (devbox.d/bin) is the single definition of "acceptable":
  conflicts, markers, `php -l`, unit tests, upgrade idempotence, functional
  suite, plus the bug's own `verify` command.

Run everything inside `devbox shell` so `jethro_gate`, `php` and `mariadb` are
on PATH.

## Reviewer scratch instances

Do not use the shared dev or functest database for write probes. From the repo,
run `./devbox run -- bin/review_loop scratch` (set `BEADS_ACTOR` first). It
creates `jethro_review_<sanitised-actor>` with demo data, a private attachment
directory and a temporary docroot, then prints a `php -S` command and demo
login. Use `--fresh` for an empty fresh install, or `--db NAME --port PORT` to
override defaults. Database names use underscores, not actor-name hyphens.
Reviewer-created DB fixtures are allowed; do not edit tracked files.

Only one full deepreview should run per repository and reviewed range at a
time. The merge slot serializes integration only; it does not prevent duplicate
review/fixer work or collisions in shared workspaces and test services. For
different repositories, use separate `DEEPREVIEW_BEADS` directories; web ports
already differ per checkout path (`devbox.d/bin/jethro_utils`).

For a bug with no existing test, `verify` may name the test the fixer must add;
the reviewer must still reproduce the behavior and document the exact steps.
Docs-only fixes may use a narrowly scoped documentation check. Re-run
`bd list --parent EPIC --status open` immediately before each `bd create` to
avoid near-simultaneous duplicates. The walkthrough project can fail in its
setup wizard before its later tests run; do not treat those later tests as
coverage on this stack.
