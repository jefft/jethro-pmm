# Multi-agent review loop

How several agents review a jj stack, file and discuss bugs, fix them in
parallel, and integrate the fixes one at a time without losing or breaking
each other's work. Bugs live in a beads database shared by every agent and
every jj workspace; the error-prone jj steps are scripted in `bin/review_loop`.

```
supervisor ─ epic ─┬─ reviewers (parallel) ── file bug beads
                   ├─ cross-review (parallel) ── comment, dedupe, close invalid
                   ├─ fixers (parallel, one jj workspace each) ── failing test, fix, submit
                   ├─ integrator (one at a time, merge slot) ── gate tip+fix, squash
                   └─ closer (not author, not fixer) ── check test + gate, close
```

## Hard rules

1. **Only the integrator rewrites the shared stack.** Reviewers, fixers and
   closers never run `jj squash`, `jj rebase`, `jj describe`, `jj abandon` or
   `jj op restore` on commits outside their own fix workspace.
2. **A fix starts with a test that fails.** The bug's `verify` command must
   fail on the target commit before the fix and pass after it.
3. **One integration at a time.** `review_loop integrate` holds the beads merge
   slot; a second integrator waits or retries.
4. **A bug is closed only on evidence**: integrated (label `integrated`), the
   gate summary in a comment, and a closer who neither filed nor fixed it.
5. **Invariants in the epic win.** If a fix would break one, comment on the bug
   and stop; the supervisor decides.

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
| Fix | fixer | `review_loop workspace BUG` → test, fix → `review_loop submit BUG` | label `ready-to-integrate` |
| Integrate | integrator | `review_loop integrate BUG` | label `integrated` or `needs-work`, gate summary comment |
| Close | closer | checks, then `bd close BUG --reason "..."` | closed |
| Clean up | fixer | `review_loop cleanup BUG` | workspace forgotten |

## What the scripts guarantee

- `review_loop workspace` creates a jj workspace beside the main checkout with
  two changes: the **fix change** on the bug's target commit, and a **local
  change** on top holding that workspace's own web ports (from 8500 up, never a
  port another workspace uses or anything listens on). Separate checkouts
  already have separate functest databases (DEVELOPMENT_FUNCTESTS.md).
- `review_loop submit` moves every edit except `devbox.json` into the fix
  change, so port settings never reach the stack.
- `review_loop integrate` takes the merge slot, builds a **trial** working
  copy (tip + fix), runs `jethro_gate --also VERIFY` on it, and only if it
  passes squashes the fix into its target. A failed gate rewrites nothing. A
  squash that leaves conflicts is undone with `jj op revert` of that one
  operation, never `jj op restore`, which would roll back other agents' work.
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
different repositories, use separate `DEEPREVIEW_BEADS` directories and
non-overlapping web ports.

For a bug with no existing test, `verify` may name the test the fixer must add;
the reviewer must still reproduce the behavior and document the exact steps.
Docs-only fixes may use a narrowly scoped documentation check. Re-run
`bd list --parent EPIC --status open` immediately before each `bd create` to
avoid near-simultaneous duplicates. The walkthrough project can fail in its
setup wizard before its later tests run; do not treat those later tests as
coverage on this stack.
