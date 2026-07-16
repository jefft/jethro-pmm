# POST /api/v1/gateway — Send SMS

**Status:** MOCK ONLY — not verified against real API  
(The gateway POST mock is disabled in `tests/sms-mock-overrides-cellcast.json`; `parseSendResponse()` logic is unit-tested via the fake HTTP client.)

---

## Overview

Enqueues one SMS to one or more recipients.  
Called by `CellcastSmsProvider::send()` in `jethro-sms/src/sms_cellcast.php` (≈ line 287).

---

## Request

```
POST https://api.cellcast.com/api/v1/gateway
Authorization: Bearer <SMS_CELLCAST_APIKEY>
Content-Type: application/json
```

### Body

```json
{
  "message":     "Hello from St Johns",
  "contacts":    ["614915701588", "61402000002"],
  "sender":      "614915701588",
  "countryCode": 61
}
```

| Field         | Type             | Required | Description |
|---------------|------------------|----------|-------------|
| `message`     | string           | yes      | SMS body text. |
| `contacts`    | array of strings | yes      | Recipient numbers in international format (no leading `+`), e.g. `"614915701588"`. |
| `sender`      | string           | yes      | Originator: an E.164-style number or an alphanumeric Sender ID registered with Cellcast. |
| `countryCode` | integer          | yes      | Numeric country code, e.g. `61` for Australia. Derived from `SMS_INTERNATIONAL_PREFIX`. |
| `scheduleAt`  | string           | no       | UTC datetime in `"YYYY-MM-DD HH:MM:SS"` format. Omit for immediate delivery; include to schedule a future send. |

#### Scheduled send example

```json
{
  "message":     "Reminder: service starts at 10am",
  "contacts":    ["614915701588"],
  "sender":      "StJohnsWPH",
  "countryCode": 61,
  "scheduleAt":  "2026-06-23 13:36:00"
}
```

`scheduleAt` is set by `send()` when `$sendAt !== null`:

```php
// jethro-sms/src/sms_cellcast.php ≈ line 283
if ($sendAt !== null) {
    $body['scheduleAt'] = gmdate('Y-m-d H:i:s', $sendAt);
}
```

---

## Response

### Success — immediate send

```json
{
  "status":  true,
  "message": "SMS Sent Successfully",
  "data": {
    "queueResponse": [
      {
        "Number":    "614915701588",
        "MessageId": "abc123",
        "jobInfo": {
          "data": {
            "messageData": {
              "status": "sent"
            }
          }
        }
      }
    ]
  }
}
```

### Success — scheduled send

```json
{
  "status":  true,
  "message": "SMS Sent Successfully",
  "data": {
    "scheduleAt":   "2026-06-23 13:36:00",
    "queueResponse": [
      {
        "Number":    "614915701588",
        "MessageId": "abc123",
        "jobInfo": {
          "data": {
            "messageData": {
              "status": "queued"
            }
          }
        }
      }
    ]
  }
}
```

### Failure

```json
{
  "status":  false,
  "message": "Your sender id is not registered.",
  "error":   { "sender": "Your sender id is not registered." },
  "data":    []
}
```

---

## Response fields

### Top-level envelope

| Field     | Type    | Description |
|-----------|---------|-------------|
| `status`  | boolean | `true` = accepted; `false` = rejected (check `error` or `message`). |
| `message` | string  | Human-readable result, e.g. `"SMS Sent Successfully"`. |
| `data`    | object  | Present on success; contains `queueResponse` and optionally `scheduleAt`. |
| `error`   | object  | Present on failure; maps field names to error strings. |

### `data` object

| Field           | Type             | Description |
|-----------------|------------------|-------------|
| `queueResponse` | array of objects | One entry per successfully enqueued recipient. Recipients absent from this array are treated as failed. |
| `scheduleAt`    | string           | Echoed back when the message was scheduled; absent for immediate sends. |

### `queueResponse` items

| Field       | Type   | Description |
|-------------|--------|-------------|
| `Number`    | string | Recipient number as submitted, in international format. |
| `MessageId` | string | Cellcast-assigned message identifier. Stored as `SmsDelivery::$remoteId`; used by the delivery-status endpoint (`GET /api/v2/report/message/{MessageId}`). |
| `jobInfo`   | object | Nested job metadata; see below. |

### `jobInfo.data.messageData.status` values

Accessed at path `jobInfo → data → messageData → status` (see `CellcastSmsDelivery`, line 61).

| API value   | Mapped `SmsStatus`      | Meaning |
|-------------|-------------------------|---------|
| `"queued"`  | `SmsStatus::SCHEDULED`  | Message accepted and held for scheduled delivery. |
| `"pending"` | `SmsStatus::SCHEDULED`  | Message accepted but not yet dispatched. |
| _(anything else)_ | `SmsStatus::SENT` | Message dispatched to carrier. |

`CellcastSmsDelivery` treats `queued` and `pending` as `SCHEDULED`; every other value (including `sent`) becomes `SENT`:

```php
// jethro-sms/src/sms_cellcast.php ≈ line 61-65
$msgStatus  = $rawItem['jobInfo']['data']['messageData']['status'] ?? null;
$isScheduled = $msgStatus === 'queued' || $msgStatus === 'pending';
parent::__construct(
    status: $isScheduled ? SmsStatus::SCHEDULED : SmsStatus::SENT,
    ...
);
```

---

## Error handling

When `status` is `false`, `extractSendError()` (≈ line 316) builds the error string:

1. If `error` is a non-empty associative array, joins its values with spaces.  
2. Otherwise falls back to the top-level `message` string.  
3. Last resort: `"Unknown error — raw: <json>"`.

The returned `\Result::failure(string)` propagates up to the caller without throwing.

---

## Recipient matching

`parseSendResponse()` (≈ line 338) builds a `Number → queueItem` map from `queueResponse` and cross-references every submitted recipient:

- **Found in map** → `CellcastSmsDelivery` (with `MessageId` and status from `jobInfo`).
- **Not found** → `SmsDelivery` with `SmsStatus::FAILED`.

This means a partial failure (one recipient accepted, one rejected) is handled gracefully per-recipient rather than as an all-or-nothing result.

---

## Error Response Structure

Unknown — not yet documented.

---

## Source references

| Symbol | File | Approx. line |
|--------|------|--------------|
| `send()` | `jethro-sms/src/sms_cellcast.php` | 250–308 |
| `parseSendResponse()` | `jethro-sms/src/sms_cellcast.php` | 338–373 |
| `extractSendError()` | `jethro-sms/src/sms_cellcast.php` | 316–325 |
| `CellcastSmsDelivery` | `jethro-sms/src/sms_cellcast.php` | 57–72 |
| Disabled mock | `tests/sms-mock-overrides-cellcast.json` | key `"DISABLED POST /gateway"` |

**Parsed by:** `CellcastSmsProvider::parseSendResponse()`

