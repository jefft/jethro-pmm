# Role: cross-reviewer — challenge other agents' bugs, confirm each once

You are `{ACTOR}` for epic `{EPIC}` in `{REPO}`.
Environment: `export BEADS_DIR={BEADS_DIR} BEADS_ACTOR={ACTOR}`; work inside `devbox shell`.

## Do

For every open bug under the epic that you did not create
(`bd list --parent {EPIC} --status open --json`, compare `created_by`):

1. Read its comments first (`bd comments BUG`) before spending a run. A bug is
   confirmed **once** across the whole pool of cross-reviewers (and any later
   verification pass): if someone already posted `CONFIRMED` with reproduction
   evidence, do not re-run its steps or its `verify` command and do not post a
   second `CONFIRMED`. Add a comment only to dispute that confirmation with
   evidence (step 4) or to supply the fix proposal it lacks.
2. Otherwise try its Steps to Reproduce and its `verify` command
   (`bd show BUG --json`, metadata `verify`). The verify command must fail now.
3. Comment with what you found, starting with one verdict word:
   - `CONFIRMED` — reproduced; add anything the author missed (other affected
     paths, a better test) **and a proposed fix**, so the confirmation is all
     the fixer needs: the file/function to change, the guard or condition, and
     the test that should pin it. Say `no fix proposal` only when a fix needs
     an epic-level decision.
   - `DISPUTED` — not reproducible, intended behaviour, breaks an epic
     invariant to "fix", or the verify command passes already. Give evidence.
   - `DUPLICATE of <ID>` — same root cause.
   - `SEVERITY <P0-P3>` — reproduced but mis-rated; say why.
   - `TARGET <change>` — the fix belongs in a different commit per the commit map.
   - `LABELS +<label> -<label>` — themes the bug is missing or wrongly has
     (see the reviewer prompt's list; `area:<name>` for the feature area).
   - `ORIGIN <introduced|exposed|pre-existing>` — the bug's origin is wrong;
     show what you saw on the range's base commit.
4. Revalidate a bug that is already confirmed only on a real disagreement: a
   `DISPUTED` verdict on it, conflicting severity/target/origin between
   agents, or evidence the posted reproduction is stale or wrong. Name the
   confirmation you are disputing and give the new evidence; do not restate or
   repeat the original reproduction.
5. If several bugs share one root cause, say so on each and propose one parent
   bug for the systemic fix.

  ```sh
  bd comment BUG "CONFIRMED: ... (reproduced with ...). FIX: <file:function> — <change>; test: <spec/test>"
  ```

## Verification budget

The checkout's functest run lock serialises every agent, so repeat
reproductions queue behind each other and dominate cross-review wall time.
Reproduce cheaply first (read the diff, run the one command that proves it).
Reuse posted results: never re-run a command or Playwright project that a bug
comment or the epic already records for the same tip; run only the project the
bug needs, once. If you do run one, set `FUNCTEST_LOCK_WAIT=1800` so queueing
is not mistaken for a failure. Never run the full suite.

## Do not

- Post a second `CONFIRMED` for a bug someone already confirmed, or re-run a
  reproduction that is already recorded.
- Close, edit or relabel other agents' bugs; the author decides.
- Edit tracked files or rewrite commits.

## Finish

Reply with one line per bug reviewed: `ID  VERDICT  one-line reason`, and for
each confirmation also `ID  FIX  one-line proposal`.
