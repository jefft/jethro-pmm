# Spec 06: Unicode mode "never" — emoji message blocks send

## Goal
Verify that when `SMS_UNICODE_PERMITTED=never`, a message containing emoji:
1. Triggers a warning in the statusline (e.g. "Remove special characters").
2. Disables the Send button.
3. Replacing the emoji with plain text re-enables the Send button.

## Test person
**Person 185: Magdalena Luther** (familyid=3), mobile 0491570158.
Open via the SMS modal on Magdalena's person page (`?view=persons&personid=185`).

(Same person as spec 05 — these are separate Playwright scenarios with independent DB state.)

Do not use persons 4 or 5.

## Playwright scenario name
`sms-unicode-never`

## `.conf` override file
`tests/functional/sms/sms-unicode-never.conf`

```php
<?php
// Test scenario: sms-unicode-never
// Emoji is never permitted; statusline warns and blocks send.
define('SMS_TESTMODE', true);
define('SMS_UNICODE_PERMITTED', 'never');
```

`SMS_TESTMODE=true` uses `FakeHttpClient` so no proxy is needed — but Send is blocked anyway, so it's just a safety net.

## Mock config
None — `SMS_TESTMODE=true` intercepts at the PHP level.

## Playwright config entry
Add `"sms-unicode-never"` to the `SCENARIOS` array in `playwright.config.ts`.

## Test steps

### Test: "Send button is disabled when message contains emoji in 'never' unicode mode"

1. Navigate to `?view=persons&personid=185` (Magdalena Luther).
2. Click the SMS modal trigger (mobile number dropdown → "SMS via Jethro").
3. Type `Hello 🎉 this has emoji` in the message textarea.
4. Wait for the statusline to render.
5. Assert the statusline contains a warning about special characters (e.g. `"Remove special characters"` or the exact phrase used by `sms_statusline.php` for the `never` policy).
6. Assert the Send button is **disabled** (has `disabled` attribute or `$smsSendBlocked` signal is true).
7. Clear the textarea and type `Hello this is plain text only`.
8. Wait for the statusline to re-render.
9. Assert the warning is **gone**.
10. Assert the Send button is **enabled**.

## Assertions summary
- Emoji message: warning shown, Send disabled.
- Plain text message: warning gone, Send enabled.

## Safety note
`SMS_TESTMODE=true` means even if Send were accidentally clicked, `FakeHttpClient` intercepts it. No real SMS is ever sent.
