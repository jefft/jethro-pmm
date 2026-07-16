# GET /api/v1/customNumber

**Status: VERIFIED** — responses confirmed against the real Cellcast API (see `logs/easyjethro.log`).

Returns all registered senders for the authenticated account. A single endpoint serves two distinct sender types: **phone numbers** and **alphanumeric sender IDs**. The caller routes entries based on whether `number` is all-digits or not.

---

## Request

```
GET /api/v1/customNumber
Authorization: Bearer <token>
```

No request body. No query parameters.

---

## Response

```json
{
  "data": [
    { "number": "StJohnsWPH",    "name": "St Johns WPH" },
    { "number": "614915701588",  "name": "Church Main"  },
    { "number": "61402000002",   "name": "Youth Group"  }
  ]
}
```

### Fields

| Field | Type | Description |
|---|---|---|
| `data` | array | List of registered sender entries. Empty array `[]` if none registered. |
| `data[].number` | string | The sender identity. All-digit strings are phone numbers; mixed/alpha strings are sender IDs. |
| `data[].name` | string | Human-readable label assigned in the Cellcast dashboard. Not used programmatically. |

### Error response (auth failure / server error)

When authentication fails or a server error occurs, Cellcast returns a top-level `code`/`message` object **without** a `data` key:

```json
{ "code": 401, "message": "Unauthorized" }
```

`getSenderNumbers()` detects this by checking `isset($data['code']) && !isset($data['data'])` and returns a `Result::failure`.

---

## Routing: phone numbers vs. sender IDs

This single endpoint is the source for two provider methods. The split is done by `number` format:

| Condition on `number` | Matches regex | Goes to | Returns |
|---|---|---|---|
| All digits, optional leading `+` | `/^\+?\d+$/` | `getSenderNumbers()` | `PhoneNumber[]` |
| Contains letters or non-digit chars | does **not** match | `getSenderIds()` | `SenderID[]` |

Both methods call `GET /api/v1/customNumber` independently. Each silently skips the entries it doesn't own — `getSenderNumbers()` skips alphanumeric entries, `getSenderIds()` skips numeric ones.

---

## Source references

### `getSenderNumbers()` — phone number entries only

**`jethro-sms/src/sms_cellcast.php`, lines 632–661**

```php
public function getSenderNumbers(): \Result
{
    $result = $this->request('GET', '/api/v1/customNumber');
    // ...
    foreach ($items as $item) {
        $number = $item['number'];
        // Skip alphanumeric sender IDs — getSenderIds() handles those
        if (!preg_match('/^\+?\d+$/', $number)) continue;
        $numbers[] = new PhoneNumber($number);
    }
    return \Result::success($numbers);
}
```

### `getSenderIds()` — alphanumeric entries only

**`jethro-sms/src/sms_cellcast.php`, lines 427–451**

```php
public function getSenderIds(bool $getAll = false): \Result
{
    // Cellcast returns both phone numbers and alphanumeric sender IDs
    // from the same /api/v1/customNumber endpoint. Phone numbers go
    // through getSenderNumbers(); alphanumeric entries are sender IDs.
    $result = $this->request('GET', '/api/v1/customNumber');
    // ...
    foreach ($items as $item) {
        $number = (string) $item['number'];
        // Skip entries that are valid phone numbers —
        // those are sender numbers, not alphanumeric sender IDs.
        if (preg_match('/^\+?\d+$/', $number)) continue;
        $ids[] = new \Sms\SenderID($number);
    }
    return \Result::success($ids);
}
```

---

## Test fixtures

### `tests/sms-mock-overrides-cellcast-stjohns.json` — mixed (phone numbers + sender ID)

This variant exercises the alphanumeric split. `StJohnsWPH` is routed to `getSenderIds()`; the two numeric entries are routed to `getSenderNumbers()`.

```json
"GET /customNumber": {
    "data": [
        {"number": "StJohnsWPH",   "name": "St Johns WPH"},
        {"number": "614915701588", "name": "Church Main"},
        {"number": "61402000002",  "name": "Youth Group"}
    ]
}
```

### `tests/sms-mock-overrides-cellcast.json` — phone numbers only

```json
"GET /customNumber": {
    "data": [
        {"number": "614915701588", "name": "Church Main"},
        {"number": "61402000002",  "name": "Youth Group"}
    ]
}
```

---

## Real API response (from logs)

Recorded in `logs/easyjethro.log`. The live API returns the same mixed shape as the `stjohns` fixture:

```json
{"data":[{"number":"StJohnsWPH","name":"St Johns WPH"},{"number":"614915701588","name":"Church Main"},{"number":"61402000002","name":"Youth Group"}]}
```

---

## Error Response Structure

Unknown — not yet documented.
