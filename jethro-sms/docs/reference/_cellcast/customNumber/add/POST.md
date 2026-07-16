# POST /api/v1/customNumber/add

Register a phone number as a custom sender number with Cellcast.

**Status: MOCK ONLY — not verified against real API**

This is Phase 1 of a two-phase registration flow. After a successful call, Cellcast either
registers the number immediately (no OTP required) or sends a verification code to the number,
which must then be submitted via [POST /customNumber/verifyCustomNumber](../verifyCustomNumber/POST.md).

---

## Request

```
POST https://api.cellcast.com.au/api/v1/customNumber/add
Authorization: Bearer <token>
Content-Type: application/json
```

### Body

| Field    | Type   | Required | Description                                                   |
|----------|--------|----------|---------------------------------------------------------------|
| `number` | string | Yes      | Phone number in international format (e.g. `61491570158`)     |
| `name`   | string | Yes      | Display label for the number (e.g. `Church Main`)             |

The `number` field is normalised by the provider before sending: if the stored number begins with
the configured local prefix (e.g. `0`), that prefix is replaced with the international prefix
(e.g. `61`) before the request is made. See `toInternational()` in `jethro-sms/src/sms_cellcast.php`.

### Example

```json
{
  "number": "61491570158",
  "name": "Church Main"
}
```

---

## Response

```
HTTP/1.1 200 OK
Content-Type: application/json
```

### Schema

| Field     | Type    | Description                                          |
|-----------|---------|------------------------------------------------------|
| `status`  | boolean | `true` on success, `false` (or absent) on failure    |
| `message` | string  | Human-readable outcome or error description          |

The `message` value controls what happens next:

| `message` contains   | Outcome                                                        |
|----------------------|----------------------------------------------------------------|
| `"already exist"`    | Number is already registered — no OTP needed, immediately usable |
| `"created"`          | Number was created without OTP — immediately usable            |
| Anything else        | OTP has been sent; caller must submit it via `/verifyCustomNumber` |

### Outcome: immediately registered (already exists or created without OTP)

```json
{
  "status": true,
  "message": "Number already exist in system"
}
```

```json
{
  "status": true,
  "message": "Custom number created"
}
```

### Outcome: OTP verification required

```json
{
  "status": true,
  "message": "OTP sent to your number successfully"
}
```

### Failure

```json
{
  "status": false,
  "message": "Some error description"
}
```

---

## Test-mode mock response

In test mode the `CellcastFakeHttpClient` (defined in `jethro-sms/src/sms.php`) intercepts this request
and returns a fixed payload that triggers the "immediately registered" branch (message contains
`"created"`):

```json
{
  "status": true,
  "message": "Custom number created (test mode — not actually created)",
  "testMode": true
}
```

No actual registration is performed and no OTP is sent.

---

## Provider integration

**Source:** `jethro-sms/src/sms_cellcast.php`

| Symbol                  | Approx. line | Role                                             |
|-------------------------|--------------|--------------------------------------------------|
| `registerSenderNumber()` | 678          | Entry point; dispatches Phase 1 vs Phase 2       |
| Phase 1 request         | 691–694      | Builds and fires `POST /api/v1/customNumber/add` |
| "already exist" branch  | 708–710      | Sets cache flag, returns `registered: true`      |
| "created" branch        | 714–716      | Sets cache flag, returns `registered: true`      |
| OTP branch              | 720–726      | Returns `registered: false` with OTP field schema |

The provider caches successful registrations under the key
`sms_sender_registered_<phoneNumber>` so subsequent checks skip the upstream call.

---

## Registration flow

```
Caller
  │
  ├─ registerSenderNumber($contact, null)
  │       POST /api/v1/customNumber/add  ──────────────────────────────┐
  │                                                                     │
  │       ┌─ status=false ─────────────────────────────────────► failure Result
  │       │
  │       ├─ message contains "already exist" or "created" ──────► RegistrationStep(registered=true)
  │       │
  │       └─ message is anything else (OTP sent) ───────────────► RegistrationStep(registered=false, fields=[otp])
  │
  └─ (if OTP required) registerSenderNumber($contact, ['otp' => '123456'])
          POST /api/v1/customNumber/verifyCustomNumber
          └─ see verifyCustomNumber/POST.md
```

---

## Related endpoints

- [GET /customNumber](../GET.md) — list registered sender numbers
- [POST /customNumber/verifyCustomNumber](../verifyCustomNumber/POST.md) — submit OTP (Phase 2)

---

## Error Response Structure

Unknown — not yet documented.
