## Success Criteria

- Every bug filed under this epic is closed: integrated with a passing gate, or
  closed as invalid / duplicate with a reason.
- `jethro_gate` passes on the final tip.
- A final review round finds only nits.

## Stack under review

<!-- jj revset, e.g. qvptorrk::ywxqtvup, and one line per change -->

## Commit map

Where a fix for each area belongs (bug metadata `target`):

| Area | Change ID | Notes |
|---|---|---|
| <!-- e.g. field visibility, upgrade SQL --> | <!-- qvptorrk --> | |
| <!-- e.g. attachment custom field --> | <!-- mpskvkls --> | |

## Invariants

Decisions every fix must respect. A fix that would break one is a comment on
the bug, not a change.

- <!-- e.g. Hidden person fields are dropped from reports, never shown blank. -->
- <!-- e.g. Note text never contains sensitive custom-field values. -->

## Test commands

- Gate (integrator): `review_loop integrate BUG` (runs `jethro_gate --also VERIFY`)
- Unit: `php tests/unit/run.php`
- Upgrades and fresh install: `jethro_check_upgrades`
- One functest project: `cd tests/functional && bun run ./node_modules/.bin/playwright test --project=NAME`

## Known pre-existing failures

Failures the gate shows on trunk, which are not this epic's bugs:

- <!-- e.g. walkthrough: installer has no install_email field -->
