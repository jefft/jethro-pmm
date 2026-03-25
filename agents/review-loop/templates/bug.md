## Summary

<!-- One sentence: what is wrong, for whom. -->

## Severity

<!-- P0 data loss/security/outage · P1 broken feature · P2 wrong behaviour with a workaround · P3 nit -->

## Steps to Reproduce

1. <!-- account / mask / data, e.g. staff user with data_permissions=0 -->
2. <!-- request or action -->
3. <!-- observe -->

Observed: <!-- quote output, SQL result, HTTP status -->
Expected: <!-- -->

## Target commit

<!-- Change ID from the epic's commit map; same value as metadata "target". -->

## Verify

<!-- Shell command that FAILS now and will PASS once fixed (metadata "verify").
     Prefer the cheapest test that proves the behaviour: a unit test, a SQL check,
     or a single Playwright project. Not a test that pins wording. -->

## Acceptance Criteria

- [ ] The Verify command fails on the target commit and passes after the fix.
- [ ] `jethro_gate` passes on the tip with the fix.
- [ ] No epic invariant is broken.
