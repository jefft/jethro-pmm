# Spec 09: Sender number registration (OTP flow)

## Goal
Verify the "register and verify your mobile as a sender number" flow:
1. Person page SMS modal shows "your mobile number needs verifying" message.
2. Clicking the register link triggers the registration form/OTP flow.
3. Submitting the OTP completes verification successfully.

This tests the `sms-register-number` link → registration modal/form → `POST /api/v1/customNumber/add` → OTP entry → `POST /api/v1/customNumber/verifyCustomNumber` chain.

## Test person
**Person 1: Dennis Demo** (familyid=1), mobile 0491570158.
The logged-in user (Dennis Demo) sees their own mobile listed in the sender dropdown with "needs verifying" state.

Note: person 1 is the logged-in admin account. We are registering THEIR OWN mobile as a sender number. This is a different flow from registering a dedicated sender ID.

## Playwright scenario name
`sms-sender-number-registration`

## `.conf` override file
`tests/functional/sms/sms-sender-number-registration.conf`

```php
<?php
// Test scenario: sms-sender-number-registration
// Routes through mock proxy for customNumber registration and OTP verify endpoints.
define('SMS_CELLCAST_URL', 'http://127.0.0.1:12345/cellcast');
```

## Mock config
Reuse **`tests/functional/sms/smsmockserver/cellcast.json`** — it already mocks:
- `POST /api/v1/customNumber/add` → `{"status": true, "message": "Custom number created"}`
- `POST /api/v1/customNumber/verifyCustomNumber` → `{"status": true, "message": "Number verified successfully (test mode)"}`

## Playwright config entry
Add `"sms-sender-number-registration"` to the `SCENARIOS` array in `playwright.config.ts`.

## Test steps

### Test: "Register and verify own mobile number as SMS sender"

**Setup**: Explore the actual UI first. Navigate to `?view=persons&personid=1` and open the SMS modal. Observe:
- Whether the `sms-register-number` link is present.
- What it does when clicked (opens an inline form? a new modal? a page?).
- What form fields appear (phone number, OTP code entry).

The implementation should match whatever the actual UI does. The key assertions are below.

**Steps** (adjust selectors to match actual UI):

1. Navigate to `?view=persons&personid=1` (Dennis Demo).
2. Click the SMS modal trigger.
3. In the modal, assert the sender dropdown or sender area shows `"your mobile number needs verifying"` (or the exact phrase from `views/view_3_persons__5_sms.class.php` / `include/jethro_sms.php`).
4. Click the `a.sms-register-number` link.
5. Assert a registration form appears with an input for the phone number to register (pre-filled with the user's mobile, or empty).
6. If the phone is not pre-filled, type `0491570158`.
7. Click the "Register" or "Send OTP" button.
8. Wait for the mock `POST /api/v1/customNumber/add` to be called (the proxy returns success).
9. Assert the UI now shows an OTP entry field (or step 2 of the flow).
10. Type any 6-digit OTP (e.g. `123456`) into the OTP field.
11. Click "Verify" (or equivalent).
12. Wait for the mock `POST /api/v1/customNumber/verifyCustomNumber` to be called.
13. Assert the UI shows a success message (e.g. "Number verified successfully") or the sender dropdown updates to show the number without the "needs verifying" notice.

## Discovering the exact UI
Before implementing, check:
```
grep -n 'sms-register-number\|register.*number\|otp\|OTP\|verify.*number' \
  include/jethro_sms.php views/view_3_persons__5_sms.class.php resources/js/jethro.js
```
This will reveal:
- The AJAX call target (what `?call=` is used).
- Whether the OTP flow is a multi-step modal or separate page.
- The exact response format the JS uses to detect success.

## Assertions summary
- Sender area shows "needs verifying" message before registration.
- After registration POST: OTP entry field appears.
- After OTP verify POST: success indication shown.

## Safety note
All provider API calls go to the mock proxy. No real OTP is sent to the phone. No real number is registered with Cellcast.
