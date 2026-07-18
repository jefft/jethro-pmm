# Spec 08: Failed send — provider returns error

## Goal
Verify that when the SMS provider returns a failure response, the UI:
1. Shows an error/failure alert (not a success message).
2. Does not claim recipients were reached.

## Test person
**Person 29: Jamison Williamson** (familyid=14), mobile 0491570159.
Open via the SMS modal on Jamison's person page (`?view=persons&personid=29`).

Do not use persons 4 or 5.

## Playwright scenario name
`sms-failed-send`

## `.conf` override file
`tests/functional/sms/sms-failed-send.conf`

```php
<?php
// Test scenario: sms-failed-send
// Proxy returns a failure from the gateway endpoint.
define('SMS_CELLCAST_URL', 'http://127.0.0.1:12345/cellcast-send-fail');
```

## Mock config
Create **`tests/functional/sms/smsmockserver/cellcast-send-fail.json`**:

```json
{
    "//": "Cellcast overrides — gateway rejects all sends (insufficient credits).",
    "UPSTREAM": "https://api.cellcast.com",

    "GET /api/v1/apiClient/account": {
        "meta": {"code": 200, "status": "success"},
        "data": {"sms_balance": 0}
    },

    "GET /api/v1/apiClient/getOptout": {
        "meta": {"code": 200, "status": "SUCCESS"},
        "message": "You have 0 optout contact(s)",
        "data": {
            "items": [],
            "total": 0,
            "limit": 100,
            "current": 1,
            "totalPages": 1,
            "hasPrevPage": false,
            "hasNextPage": false,
            "prevPage": null,
            "nextPage": null
        },
        "error": {}
    },

    "POST /api/v1/gateway": {
        "status": false,
        "message": "Insufficient credits",
        "data": {}
    }
}
```

## Playwright config entry
Add `"sms-failed-send"` to the `SCENARIOS` array in `playwright.config.ts`.

## Test steps

### Test: "Send failure is reported to the user"

1. Navigate to `?view=persons&personid=29` (Jamison Williamson).
2. Click the SMS modal trigger (mobile number dropdown → "SMS via Jethro").
3. Type `Test failed send message` in the textarea.
4. Click the Send button.
5. Wait for the result element to appear (the AJAX response from `?call=sms`).
6. Assert the result does **not** contain `"Message successfully sent"`.
7. Assert the result contains a failure/error indicator. The exact text depends on how `jethro_sms.php` surfaces a `Failed` result — look for "failed", "error", "could not be sent", or similar. Check the `onAJAXSuccess` handler in `resources/js/jethro-sms.js` for the failure branch, or check `jethro_sms.php`'s response for a `Failed` result type.

## Checking the exact failure wording
Run `grep -n 'Failed\|failed\|could not\|error' include/jethro_sms.php | grep -i 'send\|result\|message'` to find the user-facing failure string, and use that in the assertion.

## Assertions summary
- No success message.
- Error/failure message shown to user.

## Safety note
The mock proxy returns a synthetic failure. No real SMS provider is called.
