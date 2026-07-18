# Spec 05: Unicode mode "always" — emoji message sends without warning

## Goal
Verify that when `SMS_UNICODE_PERMITTED=always`, a message containing emoji:
1. Does **not** trigger the "Remove special characters" warning in the statusline.
2. Shows a UCS-2 segment count (70 chars/segment, not 160).
3. Sends successfully.

This contrasts with the existing `sms-unicode-when-free` scenario, which tests the conditional policy.

## Test person
**Person 185: Magdalena Luther** (familyid=3), mobile 0491570158.
Open via the SMS modal on Magdalena's person page (`?view=persons&personid=185`).

Do not use persons 4 or 5.

## Playwright scenario name
`sms-unicode-always`

## `.conf` override file
`tests/functional/sms/sms-unicode-always.conf`

```php
<?php
// Test scenario: sms-unicode-always
// Emoji is always allowed (UCS-2 encoding).
define('SMS_CELLCAST_URL', 'http://127.0.0.1:12345/cellcast');
define('SMS_UNICODE_PERMITTED', 'always');
```

## Mock config
Reuse **`tests/functional/sms/smsmockserver/cellcast.json`**.

## Playwright config entry
Add `"sms-unicode-always"` to the `SCENARIOS` array in `playwright.config.ts`.

## Test steps

### Test: "Emoji message is permitted and sends in 'always' unicode mode"

1. Navigate to `?view=persons&personid=185` (Magdalena Luther).
2. Click the SMS modal trigger (mobile number dropdown → "SMS via Jethro").
3. Type `Hello Magdalena! 🎉 Great news today.` in the message textarea.
4. Wait for the statusline to render (after debounce).
5. Assert the statusline does **not** contain `"Remove special characters"` (no warning).
6. Assert the Send button is **enabled** (not disabled via `$smsSendBlocked`).
7. Assert the statusline shows a UCS-2 segment count. The message is short (< 70 chars), so it should fit in 1 UCS-2 segment. Check for "1 segment" or similar.
8. Click the Send button.
9. Wait for the success result.
10. Assert the result contains `"Message successfully sent to 1 recipient"` or success indicator.

## Assertions summary
- No "Remove special characters" warning in statusline.
- Send button enabled.
- Statusline shows 1 UCS-2 segment.
- Send succeeds.

## Safety note
All SMS traffic goes to the mock proxy. No real carrier is contacted.
