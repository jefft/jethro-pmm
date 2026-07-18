# Spec 07: Admin SMS history filters

## Goal
Verify the Admin > SMS > Send History tab filter controls work correctly:
- Recipient name filter narrows the list to rows matching the typed name.
- Body text filter narrows the list to rows whose message contains the typed text.
- Clearing filters restores the full list.

## Test people / family
**Mann family, familyid=5** (2 mobile recipients — both have 0491570159, so they appear as one bulk send):
- Person 11: Sena Mann, mobile 0491570159
- Person 12: Mendy Mann, mobile 0491570159

The test sends a bulk SMS to this family first to ensure a known row exists in history.

Do not use persons 4 or 5.

## Playwright scenario name
`sms-admin-history-filters`

## `.conf` override file
`tests/functional/sms/sms-admin-history-filters.conf`

```php
<?php
// Test scenario: sms-admin-history-filters
define('SMS_CELLCAST_URL', 'http://127.0.0.1:12345/cellcast');
```

## Mock config
Reuse **`tests/functional/sms/smsmockserver/cellcast.json`**.

## Playwright config entry
Add `"sms-admin-history-filters"` to the `SCENARIOS` array in `playwright.config.ts`.

## Test steps

### Test: "Send SMS, then filter history by recipient name"

1. Generate a unique timestamp string: `const ts = Date.now().toString()`.
2. Navigate to `?view=families&familyid=5` (Mann family).
3. Fill the message textarea with `Filter test message ${ts}`.
4. Click Send and wait for success result.
5. Navigate to `?view=persons__sms` (Admin > SMS page).
6. The page defaults to the "Send History" tab (or click it if there are tabs).
7. Wait for the SMS history table to render and contain at least one row.
8. **Recipient filter**: Type `Sena Mann` into the recipient filter input (`input[data-bind="filterRecipient"]` or similar).
9. Wait for the client-side Datastar filter to apply (rows hidden/shown without a full page reload).
10. Assert that at least one visible row contains `"Sena Mann"`.
11. Assert that rows for other recipients not matching "Sena Mann" are hidden (or filtered out).
12. Clear the recipient filter (empty the input).
13. **Body filter**: Type the timestamp `ts` into the body filter input (`input[data-bind="filterBody"]`).
14. Wait for the filter to apply.
15. Assert that the SMS row with the timestamp is visible.
16. Assert rows with unrelated bodies are hidden.
17. Clear the body filter.
18. Verify the full list is restored (more than 1 row visible).

## Notes on filter mechanism
The admin SMS history filters are Datastar `data-bind` signals applied client-side — no server round-trip. Rows are shown/hidden via `data-show` or similar Datastar bindings. The test may need to use Playwright's `toBeVisible()` / `toBeHidden()` rather than `toHaveCount`.

Check `views/view_3_persons__5_sms.class.php` for the exact signal names (`filterRecipient`, `filterBody`, `filterSender`, `filterDateFrom`, `filterDateTo`, `filterSingleOnly`).

## Assertions summary
- After send: success result with "Sena Mann" or "Mendy Mann".
- After recipient filter "Sena Mann": matching row visible.
- After body filter with timestamp: matching row visible, others hidden.
- After clearing filters: multiple rows visible.

## Safety note
All SMS traffic goes to the mock proxy. No real carrier is contacted.
