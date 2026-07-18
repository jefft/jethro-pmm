# Spec 02: Per-recipient message override

## Goal
Verify that a user can open the preview panel, click the pencil/edit button on a specific recipient's row, type a different message for that person, blur away, and that the override is:
1. Highlighted in the preview panel (yellow background).
2. Preserved when the message compose box changes (no spurious reset).
3. Sent to the correct recipient when the form is submitted.

## Test people / family
**Calvin family, familyid=2** (3 mobile recipients):
- Person 2: John Calvin, mobile 0491570156
- Person 3: Idelette de Bure, mobile 0491570158
- Person 190: Pierre de Bure, mobile 0491570157
- Person 189: Judith de Bure (no mobile)

Do not use persons 4 or 5.

## Playwright scenario name
`sms-per-recipient-override`

## `.conf` override file
`tests/functional/sms/sms-per-recipient-override.conf`

```php
<?php
// Test scenario: sms-per-recipient-override
define('SMS_CELLCAST_URL', 'http://127.0.0.1:12345/cellcast');
```

## Mock config
Reuse **`tests/functional/sms/smsmockserver/cellcast.json`**.

## Playwright config entry
Add `"sms-per-recipient-override"` to the `SCENARIOS` array in `playwright.config.ts`.

## Test steps

### Test: "Per-recipient override is preserved and sent"

1. Navigate to `?view=families&familyid=2`.
2. Fill the message textarea with `Hello %firstname%`.
3. Check the "Message Preview" checkbox to show the preview panel.
4. Wait for the preview panel to render all 3 recipient rows (each containing the person's first name in the expanded message).
5. Find the row for John Calvin. Click the pencil/edit button (`button.sms-preview-edit-btn` or similar) on that row — the override textarea for that row should appear.
6. Clear the override textarea and type `Hi John, this is a CUSTOM message for you`.
7. Blur the textarea (click elsewhere or press Tab). Wait for the Datastar `sms_statusline` POST triggered by the blur to complete.
8. Assert John Calvin's preview row now shows the background highlight indicating an override (CSS `background-color` set to the yellow/amber highlight colour, or a CSS class like `sms-override-active`).
9. Assert the other rows (Idelette, Pierre) still show the token-expanded default message.
10. Now type an extra character in the main compose box — assert John Calvin's override textarea **still contains** `Hi John, this is a CUSTOM message for you` (the override is not reset by compose-box keystrokes).
11. Click the Send button and wait for success toast.
12. Assert the result contains `"Message successfully sent to 3 recipients"`.

## Assertions summary
- Override textarea appears on pencil click.
- After blur: override row highlighted, other rows unchanged.
- After compose-box keystroke: override preserved.
- Send succeeds with 3 recipients.

## Safety note
All SMS traffic goes to the mock proxy. `SMS_TESTMODE` is not set; the real Cellcast code path runs but the HTTP client hits port 12345.
