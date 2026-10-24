# POST /v1/senders — Register a Sender Number

Register one of the account's phone numbers (or a pending verification for a
new one) with MobileMessage. Read by
`MobileMessageSmsProvider::registerSenderNumber()`.

## Endpoint

```
POST https://api.mobilemessage.com.au/v1/senders
```

## Authentication

HTTP **Basic** auth (`SMS_MOBILEMESSAGE_APIKEY_USERNAME` /
`SMS_MOBILEMESSAGE_APIKEY_PASSWORD`) — unlike 5CentSMS, which authenticates
in the JSON body.

## Request Body

```json
{
  "number": "0491570157",
  "label": "Office mobile"
}
```

| Field    | Type   | Required | Description                                                    |
|----------|--------|----------|----------------------------------------------------------------|
| `number` | string | Yes      | The phone number to register, as entered                       |
| `label`  | string | No       | Human label (GET /v1/senders items return it back as `label`)  |

**The number must be under `number`.** POSTing it as `sender` (the key the
*GET* items use) is rejected with `{"error":"\"number\" is required."}`.

A successful creation texts a verification link to the number; the owner must
click it on the handset (max 3 pending verifications at a time). There is no
API to complete or poll the click — poll `GET /v1/senders` instead.

## Responses

Observed (2026-10, live account):

```json
{"error": "This number is already an active sender on your account."}
```

The API surfaces refusals as an `error` key; the provider turns any non-empty
`error` into a `Result::failure` — except "already an active sender", which
`registerSenderNumber()` reports as a completed registration
(`registered: true`), matching the Cellcast/v5 treatment of
already-registered numbers.

The success envelope is not yet captured — `{"status":"complete", …}` is
expected but unverified. The provider does not read the success body beyond
checking for the error envelope.

## Related

- `GET /v1/senders` returns items shaped `{"sender":"614…","type":"own|shared|dedicated|alpha","label":…,"is_default":…}`
  (note `sender`, not `number`). `type: "alpha"` items are alphanumeric
  Sender IDs; the rest are sender numbers.

## Source Reference

| Symbol | File | Approx. line |
|--------|------|--------------|
| `MobileMessageSmsProvider::registerSenderNumber()` | `jethro-sms/src/Providers/MobileMessageSmsProvider.php` | 464 |
| `MobileMessageSmsProvider::fetchSenders()` | same | 344 |
| `MobileMessageSmsProvider::getSenderNumbers()` | same | 408 |
| `MobileMessageSmsProvider::verifySenderNumber()` | same | 428 |
