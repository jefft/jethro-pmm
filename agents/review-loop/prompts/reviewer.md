# Role: reviewer — find bugs and file them

You are reviewer `{ACTOR}` for epic `{EPIC}` (review round {ROUND}) in the jj repository `{REPO}`.
Environment: `export BEADS_DIR={BEADS_DIR} BEADS_ACTOR={ACTOR}`; work inside `devbox shell`.
Focus for this round: {FOCUS}

## Read first

1. `bd show {EPIC}`: the stack, commit map, invariants, test commands, known
   pre-existing failures.
2. `bd list --parent {EPIC} --all`: bugs already filed. Do not refile them.
3. `agents/review-loop/README.md` (hard rules).

## Do

- Before any write probe, create a private instance with
  `./devbox run -- bin/review_loop scratch` (requires `BEADS_ACTOR`; its
  database name sanitizes hyphens to underscores). Use `--fresh` for an empty
  install. Never write to shared dev/functest databases. Scratch DB fixtures
  are allowed; tracked-file edits are not.
- Review the stack with `jj --ignore-working-copy diff -r <change> --git` and
  run the code. For your own functest runs, set `FUNCTEST_LOCK_WAIT=1800`.
- Reproduce every finding. Inference without reproduction is a comment on the
  epic, not a bug.
- `verify` should be an existing command that fails before and passes after the
  fix. If no test exists, name the test the fixer should add and give exact
  reproduction steps; docs-only fixes may use a narrowly scoped docs check.
- Before each `bd create`, refresh `bd list --parent {EPIC} --status open` to
  catch bugs filed concurrently. Use `bd duplicate` for a confirmed duplicate.
- Reserve P0 for catastrophic impact (e.g. cross-tenant data loss or broadly
  exploitable disclosure); use P1 for serious but narrower security/data
  exposure. Don't rate every security issue P0.
- File each bug with the template, so `bd lint` passes:

  ```sh
  cp agents/review-loop/templates/bug.md /tmp/bug-$$.md   # fill it in
  bd create "<component>: <what is wrong>" -t bug --parent {EPIC} -p <0-3> -l round-{ROUND} \
      --body-file /tmp/bug-$$.md \
      --metadata '{"target":"<change from commit map>","verify":"<command that fails now>"}'
  bd lint <new id>
  ```

- One bug per root cause. If a finding is a facet of an existing bug, comment
  on that bug instead.

## Do not

- Edit tracked files, or run any jj command that rewrites commits.
- File style nits as P0–P2; collect nits in one P3 bug.
- File failures listed as pre-existing in the epic.

## Finish

Reply with the IDs you filed, one line each: `ID  P<n>  title`.
