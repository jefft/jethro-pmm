# Spec 03: Opted-out recipient excluded from send

## Goal
Verify that when a family member's mobile number is in the upstream opt-out list:
1. The statusline shows them excluded (fewer recipients than family members with mobiles).
2. The send result reports the opted-out person separately from successful sends.

## Test people / family
**Williamson family, familyid=14** (2 mobile recipients):
- Person 29: Jamison Williamson, mobile 0491570159 → international `61491570159` ← **opted out in this scenario**
- Person 30: Bridget Williamson, mobile 0491570158 → international `61491570158` ← **gets the SMS**

Do not use persons 4 or 5.

## Playwright scenario name
`sms-opted-out-recipient`

## `.conf` override file
`tests/functional/sms/sms-opted-out-recipient.conf`

```php
<?php
// Test scenario: sms-opted-out-recipient
// Proxy returns Jamison Williamson as opted-out; Bridget gets the SMS.
define('SMS_CELLCAST_URL', 'http://127.0.0.1:12345/cellcast-williamson-optout');
```

## Mock config
Create **`tests/functional/sms/smsmockserver/cellcast-williamson-optout.json`**:

```json
{
    "//": "Cellcast overrides — Jamison Williamson (61491570159) is opted out.",
    "UPSTREAM": "https://api.cellcast.com",

    "GET /api/v1/apiClient/account": {
        "meta": {"code": 200, "status": "success"},
        "data": {"sms_balance": 12345}
    },

    "GET /api/v1/apiClient/getOptout": {
        "meta": {"code": 200, "status": "SUCCESS"},
        "message": "You have 1 optout contact(s)",
        "data": {
            "items": [
                {
                    "number": "61491570159",
                    "first_name": "Jamison",
                    "last_name": "Williamson"
                }
            ],
            "total": 1,
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
        "status": true,
        "message": "SMS Sent Successfully",
        "data": {
            "queueResponse": "{{CONTACTS}}"
        }
    }
}
```

The `number` field `"61491570159"` is the international format of `0491570159` (strip leading `0`, prepend `61`).

## Playwright config entry
Add `"sms-opted-out-recipient"` to the `SCENARIOS` array in `playwright.config.ts`.

## Test steps

### Test: "Opted-out family member is excluded from send and reported"

1. Navigate to `?view=families&familyid=14` (Williamson family).
2. Fill the message textarea with a timestamped message.
3. Wait for the statusline (`#sms-statusline-bulk`) to render after the debounce.
4. Assert the statusline shows **1 recipient** (not 2) — Jamison is excluded because his number is in the opt-out list.
5. Click the Send button.
6. Wait for the result element to appear.
7. Assert the result contains `"Message successfully sent to 1 recipient"` (Bridget).
8. Assert the result contains text indicating Jamison was not sent due to opt-out (e.g. `"opted out"` or `"1 recipient not sent"`). Check the exact phrasing in `jethro_sms.php`'s `failed_opted_out` result category.

## Assertions summary
- Statusline: 1 recipient (not 2) when opt-out list contains Jamison's number.
- Send result: 1 successful + 1 opted-out, not a failure.

## Safety note
Opt-out list is fetched from the mock proxy. The actual gateway POST also goes to the proxy. No real SMS is sent.
