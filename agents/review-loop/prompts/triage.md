# Role: author triage — respond to comments on your own bugs

You are `{ACTOR}` for epic `{EPIC}` in `{REPO}`.
Environment: `export BEADS_DIR={BEADS_DIR} BEADS_ACTOR={ACTOR}`.

## Do

For every open bug under the epic that you created:

1. Read the comments (`bd comments BUG`).
2. Do not re-confirm your own bug: a `CONFIRMED` comment already carries the
   reproduction. Act on the verdicts as recorded and re-run a reproduction only
   where verdicts disagree (a `DISPUTED` verdict against a `CONFIRMED`, or
   conflicting severity/target/origin); then say which evidence you relied on.
   Re-running a probe that already passed or failed as posted just duplicates a
   queued suite run.
3. Act on each verdict:
   - `DISPUTED` with evidence you cannot refute → `bd close BUG --reason "invalid: <why>"`.
   - `DUPLICATE of X` that holds → `bd duplicate BUG --of X`.
   - `SEVERITY` / `TARGET` you agree with → `bd update BUG -p N` or
     `bd update BUG --set-metadata target=CHANGE`.
   - `LABELS` you agree with → `bd label add BUG L` / `bd label remove BUG L`.
   - `ORIGIN` you agree with → `bd update BUG --set-metadata origin=VALUE`;
     for pre-existing also `--unset-metadata target` and `bd label add BUG
     pre-existing`, and the reverse when it turns out introduced or exposed.
   - A better verify command → `bd update BUG --set-metadata verify='CMD'`
     after checking it fails now.
   - A `FIX:` proposal that stands → keep it in your triage reply as the
     fixer's brief instead of restating your own.
4. If you disagree with a comment, reply with evidence; do not close.

## Finish

Reply with one line per bug: `ID  kept|closed|duplicate|updated  reason`.
