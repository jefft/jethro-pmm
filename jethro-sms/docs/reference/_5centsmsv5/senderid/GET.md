# GET /senderid — List Registered Sender IDs

Retrieves all sender IDs registered on the account.  The provider uses this
list to populate the sender dropdown and, when `SMS_SENDER_OPTIONS` is set,
to intersect against the admin-configured allowlist.

> **Non-standard transport** — this is a GET request that carries a JSON body
> for authentication.  Most HTTP clients support this; the PHP implementation
> sets `Content-Type: application/json` and `Content-Length` headers
> alongside the body.

---

## Endpoint

```
GET https://www.5centsms.com.au/api/v5/senderid
```

The base URL defaults to `https://www.5centsms.com.au/api/v5` and is
configurable via the `SMS_5CENTSMS_URL` constant.  The provider appends
`/senderid` (singular) to form the full URL.

---

## Authentication

Credentials are sent in the JSON request body, **not** in HTTP headers.

| Field        | Type   | Description                    |
|--------------|--------|--------------------------------|
| `key-id`     | string | API key ID                     |
| `key-secret` | string | API key secret                 |

---

## Request

### Headers

```
Content-Type: application/json
Content-Length: <byte length of body>
```

### Body

```json
{
  "key-id": "your-key-id",
  "key-secret": "your-key-secret"
}
```

### Example (curl)

```bash
curl -X GET https://www.5centsms.com.au/api/v5/senderid \
  -H "Content-Type: application/json" \
  -d '{"key-id":"your-key-id","key-secret":"your-key-secret"}'
```

---

## Response

### Success — `200 OK`

The API has returned sender IDs in at least four different JSON shapes over
its lifetime.  The provider accepts all of them:

| Key tried (in order) | Notes                              |
|----------------------|------------------------------------|
| `senderids`          | Primary shape (current API)        |
| `sender_ids`         | Alternative underscore form        |
| `data`               | Generic data wrapper               |
| `senderid`           | Singular key wrapping an array     |

Each item in the array is either a **plain string** or an **object**:

**Plain-string item**

```json
{
  "senderids": ["MYCHURCH", "MYORG"]
}
```

**Object item** (with optional ACMA approval status)

```json
{
  "senderids": [
    { "senderid": "MYCHURCH",  "status": "approved" },
    { "senderid": "NEWID",     "status": "pending"  },
    { "senderid": "REJECTED1", "status": "rejected" }
  ]
}
```

Object items may also carry `id` or `sender_id` keys instead of `senderid`;
all three are recognised.

#### ACMA Approval Mapping

The `status` field on object items controls the `acmaApproved` flag exposed
internally:

| `status` value  | `acmaApproved` |
|-----------------|----------------|
| `"approved"`    | `true`         |
| `"acma_approved"` | `true`       |
| any other value | `false`        |
| field absent    | `null`         |

Only sender IDs with `acmaApproved === true` are surfaced to end-users by
default (`getAll: false`).  Pass `getAll: true` (internal) to receive all IDs
regardless of approval status.

### Phone-number Filtering

Entries that are entirely numeric and seven or more characters long are
treated as phone numbers and excluded from the sender ID list.  Phone numbers
are returned separately by `getSenderNumbers()`.

### Error

```json
{ "error": "Invalid credentials" }
```

A non-empty `error` field causes the provider to return an empty array rather
than a failure — the caller falls back to config overrides or the user's
mobile number.  Invalid JSON also silently produces an empty array.

---

## Caching

Successful responses are cached under the key `sms_senderids` for **1800
seconds** (30 minutes).  Each cached item stores `value` and `acmaApproved`
so the approval flag survives cache round-trips.

---

## Source Reference

| Symbol | File | Approx. line |
|--------|------|-------------|
| `FiveCentSmsV5Provider::getSenderIds()` | `jethro-sms/src/sms.php` | 2582 |
| `FiveCentSmsV5Provider::fetchSendersFromApi()` | `jethro-sms/src/sms.php` | 2648 |
| `FiveCentSmsV5Provider::parseSenderIds()` | `jethro-sms/src/sms.php` | 2692 |
| `FiveCentSmsV5Provider::filterOutPhoneNumbers()` | `jethro-sms/src/sms.php` | 2618 |
| `FiveCentSmsV5Provider::filterAcmaApproved()` | `jethro-sms/src/sms.php` | 2635 |

### Test Coverage

`jethro-sms/tests/providers/test_v5_parsing.php` — exercises all four response-key
shapes, plain-string vs object items, `approved`/`acma_approved`/other status
values, and phone-number exclusion.

---

## Configuration Constants

| Constant              | Required | Default                                    | Purpose                                |
|-----------------------|----------|--------------------------------------------|----------------------------------------|
| `SMS_5CENTSMS_APIKEY_ID` | yes   | —                                          | API key ID sent as `key-id`            |
| `SMS_5CENTSMS_APIKEY`    | yes   | —                                          | API key secret sent as `key-secret`    |
| `SMS_5CENTSMS_URL`    | no       | `https://www.5centsms.com.au/api/v5`       | Base URL; provider appends `/senderid` |
| `SMS_SENDER_OPTIONS`  | no       | —                                          | CSV allowlist; intersects with API results when set |

## Error Response Structure

Unknown — not yet documented.

---

**Parsed by:** `FiveCentSmsV5Provider::parseSenderIds()`

