# Spec 01: Schedule send and cancel scheduled SMS

## Goal
Verify the deferred-send flow end-to-end: compose → pick a future send time → send → confirm "scheduled" status in admin history → cancel → confirm "cancelled" status.

## Test people / family
**Calvin family, familyid=2** (3 mobile recipients):
- Person 2: John Calvin, mobile 0491570156
- Person 3: Idelette de Bure, mobile 0491570158
- Person 190: Pierre de Bure, mobile 0491570157
- Person 189: Judith de Bure (no mobile — will be skipped)

Do not use persons 4 or 5.

## Playwright scenario name
`sms-schedule-and-cancel`

## `.conf` override file
`tests/functional/sms/sms-schedule-and-cancel.conf`

```php
<?php
// Test scenario: sms-schedule-and-cancel
// Routes through mock proxy so no real SMS is sent.
define('SMS_CELLCAST_URL', 'http://127.0.0.1:12345/cellcast');
```

No `SMS_TESTMODE` — we want real Cellcast code paths with the proxy intercepting. Cellcast declares `DEFERRED_SEND` and `DEFERRED_SEND_CANCEL` capabilities.

## Mock config
Reuse **`tests/functional/sms/smsmockserver/cellcast.json`** (already has `POST /api/v1/gateway` and `POST /api/v1/gateway/cancelScheduleQuickMessage` mocked).

The `POST /api/v1/gateway` response uses `{{CONTACTS}}` so fake `MessageId` values are generated per recipient — the cancel call uses these IDs.

## Playwright config entry
Add `"sms-schedule-and-cancel"` to the `SCENARIOS` array in `playwright.config.ts`.

## Test steps

### Test: "Schedule SMS to Calvin family for future delivery"

1. Navigate to `?view=families&familyid=2`.
2. Fill the message textarea with a timestamped message, e.g. `Scheduled test ${timestamp}`.
3. Verify the "Schedule Send…" checkbox is visible (Cellcast capability gating).
4. Check "Schedule Send…" — the datetime picker (`input[name="send_at"]`) should appear.
5. Set `send_at` to a datetime 24 hours in the future (use `page.evaluate` to set the value directly on the input, since datetime-local pickers are awkward in Playwright).
6. Click the Send button.
7. Wait for the success toast/result to contain `"Message successfully sent to 3 recipients"`.
8. Also assert result contains `"John Calvin"`, `"Idelette de Bure"`, `"Pierre de Bure"`.

### Test: "Scheduled SMS appears as 'scheduled' in admin history then can be cancelled"

Run **after** the previous test (they share DB state).

1. Navigate to `?view=persons__sms`.
2. Locate the SMS just sent (by message text containing the timestamp from step 2 above).
3. Assert at least one delivery row shows a `scheduled` badge.
4. Find the Cancel button (`[id^="sms-cancel-"]`) for this SMS entry.
5. Click the Cancel button and wait for the Datastar POST (`?call=sms_cancel`) to complete.
6. Assert the delivery status badge changes to `cancelled`.

## Assertions summary
- Send result: "Message successfully sent to 3 recipients"
- Admin history: delivery shows `scheduled` badge before cancel
- Admin history: delivery shows `cancelled` badge after cancel

## Safety note
`SMS_TESTMODE` is NOT set; all SMS traffic goes to the mock proxy at port 12345. No real carrier receives any message.
