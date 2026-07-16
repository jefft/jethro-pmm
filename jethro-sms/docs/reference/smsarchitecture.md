---
sidebar_position: 1
---

# SMS Architecture

## Philosophy

The SMS subsystem is layered in three tiers:

1. **Pure layer** (`Sms\`) — provider implementations with no Jethro dependencies. Pure functions at the core, impure (HTTP, session) at the edge. Result monad throughout.
2. **Bridge layer** (`Jethro\Sms\`) — wires providers to Jethro's database, session, and user system. Also contains UI helpers.
3. **Web layer** — AJAX `Call` handlers, templates, and JavaScript.

The `SmsProvider` interface is the seam. Everything above it works identically regardless of which gateway is configured.

## Layer Diagram

```
┌─────────────────────────────────────────┐
│            Web Layer                     │
│  calls/call_sms.class.php               │
│  templates/single_message.template.php  │
│  resources/js/jethro.js                 │
└──────────────┬──────────────────────────┘
               │ calls Jethro\Sms\
┌──────────────▼──────────────────────────┐
│          Bridge Layer                    │
│  namespace Jethro\Sms                   │
│  include/jethro_sms.php                 │
│  ┌─────────────────────────────────┐    │
│  │ DbLoggingSmsProvider            │    │
│  │  ┌──────────────────────────┐   │    │
│  │  │ TokenExpandingSmsProvider │   │    │
│  │  │  ┌───────────────────┐   │   │    │
│  │  │  │ Raw Provider      │   │   │    │
│  │  │  │ (v5/v4/SMSBcast)  │   │   │    │
│  │  │  └───────────────────┘   │   │    │
│  │  └──────────────────────────┘   │    │
│  └─────────────────────────────────┘    │
└──────────────┬──────────────────────────┘
               │ uses
┌──────────────▼──────────────────────────┐
│           Pure Layer                     │
│  namespace Sms\                         │
│  jethro-sms/src/<ClassName>.php         │
│  SmsProvider, SmsDelivery, SmsStatus    │
│  HttpClient, NativeHttpClient           │
│  Concrete providers (no Jethro deps)    │
└─────────────────────────────────────────┘
```

Dependencies flow strictly downward — web → bridge → pure — with no
circular references. `jethro-sms/src/<ClassName>.php` is the current,
post-restructure home (see [Class-per-file restructure](#class-per-file-restructure-2026-07-03)
below); the old `jethro-sms/src/sms.php` monolith is now a
backward-compatibility loader only.

## Why Layering Matters

- **Test isolation** — the pure layer's tests don't need a database or session (see [Unit tests](#unit-tests)).
- **Code reuse** — the CLI and the web UI share the same code path through the bridge layer.
- **Provider swapping** — changing one constant (`SMS_PROVIDER`) switches gateways; nothing above the `SmsProvider` interface needs to change.
- **No circular dependencies** — dependencies flow down only: web → bridge → pure.

## Package split (July 2026)

The pure layer is now the standalone **jethro-sms package** (`jethro-sms/`
at the repo root): independently buildable and testable, with the
`bin/jethro-sms` CLI as its end product. Jethro consumes it via
`jethro-sms/src/load.php` (required by `include/jethro_sms.php`). The
entanglements found during extraction and their resolutions are documented
in `jethro-sms/docs/extraction.md`. Notable relocations:
provider selection/auto-detection is `Sms\resolveRawProviderClass()`
(`src/factory.php`); the statusline maths/renderers are namespace `Sms\`
with the Jethro-state snapshot factory `Jethro\Sms\makeStatuslineConfig()`
in the bridge; `TokenExpandingSmsProvider` takes injected URL-shortener
closures; `\Result` is canonical in `jethro-sms/src/result.php`.

## Class-per-file restructure (2026-07-03)

Both layers were split from monoliths into one-class-per-file:

- **Pure layer**: types live in `jethro-sms/src/<ClassName>.php`; concrete
  providers and their delivery/fake-HTTP-client types moved to
  `jethro-sms/src/Providers/` under the new namespace `Sms\Providers\`.
  The old monoliths (`sms.php`, `sms_cellcast.php`, `templater.php`) remain
  as backward-compatibility loaders only — new code requires `src/load.php`.
- **Bridge layer**: classes were extracted from `include/jethro_sms.php`
  into `include/Jethro/Sms/` (value objects, `SessionSmsCache`,
  `SmsStatusIcon`) and `include/Jethro/Sms/Providers/` (the three bridge
  decorators). `include/jethro_sms.php` keeps the namespaced functions
  (factory, send pipeline, request parsing, rendering) and requires the
  class files.

## Provider chain assembly

`getSmsProvider()` in `include/jethro_sms.php` builds the chain from the inside out.

**Every send is inherently a batch**: `send()` takes an array of *entries*
(`[{message, recipients[]}, …]`) plus one sender/`$sendAt`/`$preview`. A
single-message send is a batch of one entry, and the whole send action —
including per-recipient message overrides — traverses the chain in **one**
`send()` call. (The old model of multiple top-level `sendSms()` calls sharing
a `$batchId` is gone.)

1. Raw provider (v5, Cellcast, SMS Broadcast, etc.) with `SessionSmsCache` wired in
2. `TokenExpandingSmsProvider` — walks the entries. An entry whose message references a known variable (one of `firstname`, `lastname`, `fullname` as supplied by `getAvailableTokens()`) is split into one entry per recipient with the expanded text (person data fetched via the injected token resolver). Function-only tokens (e.g. `%(shorten url)%`) are expanded once for the whole entry. Entries without tokens pass through unchanged, preserving batched multi-recipient HTTP calls at the raw provider. A bare `%` such as "20% off" does not trigger per-recipient splitting. Expanded entries carry a `template` key with the pre-expansion text. The bridge injects an s-expression `Templater` (with a `concat` function registered); a `shorten` function is registered per send — the preview shortener closure in preview mode, the real (mapping-storing) one otherwise.
3. `DbLoggingSmsProvider` — intercepts `send()`, `registerSenderNumber()`, `updateDelivery()`, `cancel()`, `listRecentDeliveries()`, `verifySenderNumber()`. On `send()`, pre-flight-checks that token-bearing entries have only `JethroSmsRecipient` recipients, then inserts **one `sms` row for the whole batch** (storing the first entry's `template` — pre-expansion — or message) plus per-recipient `smsdelivery` rows, and wraps deliveries in `JethroSmsDelivery` (person ID + database ID). When `$preview` is true, skips DB writes entirely (`databaseId: null`). Persists `sms_registered_sender` when a completed `registerSenderNumber()` step carries `registered && number`. Caches `updateDelivery()` results in the DB (and short-circuits upstream polling once a status is final); persists statuses returned by `cancel()` and `listRecentDeliveries()`.
4. `OverridingSmsProvider` — validates the sender against `SMS_SENDER` and the `SMS_SENDER_OPTIONS` allowlist (a mismatch returns `Result::failure`, it does not throw), and applies the `SMS_SEND_COOLOFF` delay to immediate sends. The cooloff is an *undo window*: it is applied only when the send is user-initiated (`userInitiated:` mirrors `logToDb` — system sends such as 2FA and reminders skip it) **and** the inner provider has both `DEFERRED_SEND` and `DEFERRED_SEND_CANCEL` (without cancel it would be pure latency). Because the whole send action is one `send()` call, all deliveries naturally share one `sendAt`. Sits outside `DbLoggingSmsProvider` so the DB layer sees the cooloff-modified `$sendAt`.
5. `LocalBalanceSmsProvider` — wrapped in when `SMS_BALANCE` is set (non-empty). A numeric value is a hardcoded balance; `'database'` computes the live balance from the `sms_purchases` table (`SUM(quantity) − COUNT(sms)`). With `SMS_BALANCE_ENFORCED`, blocks sends whose recipient count exceeds the remaining balance *before* they reach the inner provider.

The final chain, outermost first:

```
LocalBalanceSmsProvider → OverridingSmsProvider → DbLoggingSmsProvider → TokenExpandingSmsProvider → RawProvider
```

`getSmsProvider()` is memoized per `($tfa, $logToDb)` pair — constants cannot
change within a request, so all callers share one chain instance (and the
token resolver's person-row cache persists across sends). Both success and
failure `Result`s are cached; tests can call `resetSmsProviderCache()`.

## Provider auto-detection

When `SMS_PROVIDER` is not defined (or set to `'auto'`), `getSmsProvider()` auto-detects by iterating providers sorted by `usagePreference()` (descending) and picking the first whose `getConstants()` required constants are all defined:

| Preference | Provider | Required constants |
|---|---|---|
| 10 | `FiveCentSmsV5Provider` | `SMS_5CENTSMS_APIKEY_ID`, `SMS_5CENTSMS_APIKEY` |
| 9 | `CellcastSmsProvider` | `SMS_CELLCAST_APIKEY` |
| -1 | `SmsBroadcastSmsProvider` | `SMS_SMSBROADCAST_PASSWORD` — works if configured, but not recommended over the competition |
| -1 | `FiveCentSmsV4Provider` | `SMS_HTTP_URL` — deprecated; only matches if URL points to the v4 endpoint |
| -2 | `TemplateSmsProvider` | `SMS_HTTP_URL` — generic catch-all; lowest priority |

The v4 auto-detection has an additional check: `SMS_HTTP_URL` must match the v4 endpoint exactly (either `https://www.5centsms.com.au/api/v4/sms` or `https://www.5centsms.com.au/api/v4`), otherwise the next candidate is tried.

Providers with `usagePreference()` < 0 (SMS Broadcast, v4, Template) are excluded from the "not configured" error help text.

`SMS_PROVIDER` also accepts short keys: `'5centsmsv5'`, `'cellcast'`, `'smsbroadcast'`.

## Provider capabilities

`SmsCapability` is an enum of feature flags. Callers gate UI and behaviour on these — they should never call a method without checking the corresponding capability first:

| Capability | Method | v5 | Cellcast |
|---|---|---|---|
| `GET_BALANCE` | `getBalance()` | ✓ | ✓ |
| `GET_SENDER_IDS` | `getSenderIds()` | ✓ | |
| `DEFERRED_SEND` | `send(…, $sendAt)` | ✓ | ✓ |
| `DEFERRED_SEND_CANCEL` | `cancel()` | ✓ | ✓ |
| `REGISTER_SENDER_NUMBER` | `registerSenderNumber()` | ✓ | ✓ |
| `REGISTER_SENDER_ID` | `registerSenderId()` | ✓ | ✓ |
| `LIST_OPT_OUTS` | `listOptOuts()` | ✓ | ✓ |
| `REMOVE_OPT_OUT` | `removeOptOut()` | ✓ | |
| `BATCH_DELIVERY_QUERY` | `listRecentDeliveries()` | ✓ | ✓ |

(SMS Broadcast supports only `GET_BALANCE` and `DEFERRED_SEND`.)

A convenience method `hasCapability(SmsCapability): bool` is available on the interface so callers don't need to call `getCapabilities()` + `in_array()`.

`getSenderIds(bool $getAll = false)` returns only ACMA-approved sender IDs by
default; pass `$getAll = true` for the unfiltered list (providers without ACMA
status support ignore the parameter).

`listRecentDeliveries(?int $since)` queries delivery statuses for all messages
since a timestamp in one batch call (paginated upstream). Providers without
`BATCH_DELIVERY_QUERY` return an empty list and callers fall back to
per-delivery `updateDelivery()`. This powers [history sync](#history-sync).

**Deferred send max delay**: `getDeferredSendMaxDelay(): ?int` returns the maximum seconds ahead a send can be scheduled, or `null` if the provider enforces no limit. FiveCentSMS v5 allows 365 days (31536000 s); Cellcast allows 24 hours (86400 s); SMS Broadcast returns `null`. Callers set the `max` attribute on schedule-datetime inputs and validate server-side before accepting a `sendAt` value.

## Registration — opaque state machine

Sender ID and sender number registration are **opaque, provider-specific state machines**.
Callers never know which step they're on — they feed form submissions to a method
and render whatever comes back, until the returned `fields` are empty.

```
  registerSenderId(null, null)           // → form asking for sender ID
  registerSenderId($sid, null)           // → compliance form (or failure)
  registerSenderId($sid, $params)        // → done (fields empty)

  registerSenderNumber(null, null)       // → form asking for mobile + name
  registerSenderNumber($contact, null)   // → OTP field (Cellcast) or wait-for-link (v5)
  registerSenderNumber($contact, $params)// → done (fields empty)
```

Each provider wires its own transitions behind the same two public signatures:

```php
public function registerSenderId(?SenderID $senderId = null, ?array $params = null): \Result;
public function registerSenderNumber(?ContactPhoneNumber $contact = null, ?array $params = null): \Result;
```

Callers loop: render `fields` as a form → collect user input → call again with
the domain object + collected params → repeat until `RegistrationStep::isComplete()`
(i.e. `fields` is empty). The final step still carries `message`/`instructions`/`form`
for display.

### RegistrationStep

Each call returns `\Result<RegistrationStep, string>`. `RegistrationStep`
(`jethro-sms/src/RegistrationStep.php`) is a readonly value object that
replaced the loosely-typed array (see
`docs/sms/improvements/45-registration-result-value-object.md`).
All presentation-free — the web/CLI layer renders:

| Property | Type | Meaning |
|---|---|---|
| `message` | `string` | Plain-text outcome headline |
| `fields` | `FormField[]` | Form fields to render next (empty = done, per `isComplete()`) |
| `instructions` | `string` | Plain-text next-step guidance (optional) |
| `contact` | `string` | Email/contact to act on (optional) |
| `form` | `array<{label, value}>` | Compliance details to display/email (optional) |
| `number` | `?string` | Verified sender number (set on completion) |
| `registered` | `bool` | Whether the registration is confirmed upstream |

`with(...)` produces a modified copy (mirrors `SmsDelivery::with()`).

Rendering: `Jethro\Sms\renderRegistrationStepHtml()` (web, in
`include/jethro_sms.php`) and `renderRegistrationStepText()` (CLI, in
`jethro-sms/src/cli.php`).
Pinned by `tests/sms/registration/test_results_are_structured.php`.

The creation step happens eagerly (in step 2, not step 3) so that a taken or invalid ID fails before the user fills in the compliance form.

## Sender IDs vs. sender numbers

The v5 `GET /v5/senderid` endpoint returns a single list mixing alphanumeric sender IDs and phone numbers. The provider splits them:

- **`getSenderIds()`** — returns only alphanumeric sender IDs (excludes all-digit ≥7-char entries via `filterOutPhoneNumbers()`), and by default only ACMA-approved ones (`$getAll = true` skips that filter). Cached in `SessionSmsCache` for 30 minutes, keyed by a config fingerprint that auto-invalidates when provider settings change.
- **`getSenderNumbers()`** — returns only approved phone numbers (digit-only, ≥7 chars, `acmaApproved: true`). Delegates to `fetchSendersFromApi()` → `parseSenderIds()`.

## Config constants and 2FA fallback

Every provider reads its own constants via `fromConstants(bool $tfa)`. When `$tfa` is true, each field tries its `2FA_*` variant first:

```php
// General pattern in every fromConstants():
$key = '';
if ($tfa) { $key = (string) ifdef('2FA_SMS_EXAMPLE', ''); }
if ($key === '') { $key = (string) ifdef('SMS_EXAMPLE', ''); }
```

This works field-by-field, not whole-provider. A 2FA send can share the same API credentials as regular SMS sends by simply not defining the `2FA_*` variants — they fall through to the standard constants.

`SMS_SENDER` (with `SENDER_ID` deprecated fallback) is read by `OverridingSmsProvider` to validate the sender on every `send()` call. If set and the caller passes a different sender, `OverridingSmsProvider::send()` returns `Result::failure`. `getSenderFromRequest()` also reads `SMS_SENDER` for the web layer sender.

## Known quirks

- **No `sms_registered_sender` for out-of-band verification**: v5's `registerSenderNumber()` registration step returns `registered: false` (verification is via SMS link, not OTP), so `DbLoggingSmsProvider` does not persist. Once the user clicks the verification link and the number appears as `"approved"` in the sender ID list, `verifySenderNumber()` → `getSenderNumbers()` will detect it via the API.

- **`verifySenderNumber()` is gated on `REGISTER_SENDER_NUMBER`**: the UI registration prompt in `jethro_sms.php` only calls `verifySenderNumber()` when the capability is present. This is correct — providers without sender number registration always return `true`.

- **v4 is deprecated but still in the codebase**: `FiveCentSmsV4Provider` extends `TemplateSmsProvider`. Its `usagePreference()` is -1, which excludes it from the "not configured" help text (it still auto-detects, but only when `SMS_HTTP_URL` is exactly the v4 endpoint). It exists only for existing installs.

- **`SMS_SEND_COOLOFF` default is 30 seconds**: `OverridingSmsProvider` delays immediate (non-deferred) sends by 30 seconds by default, as an undo window. Configurable via `SMS_SEND_COOLOFF`; skipped for system-initiated sends (2FA, reminders) and for providers lacking `DEFERRED_SEND` + `DEFERRED_SEND_CANCEL`.

## Value objects

Pure-layer types live in `jethro-sms/src/<ClassName>.php` (namespace `Sms\`);
bridge types in `include/Jethro/Sms/<ClassName>.php` (namespace `Jethro\Sms\`).
Some small types share a file with their interface (e.g. `PhoneNumber` in
`SmsSender.php`, `HttpRequest`/`HttpResponse` in `HttpClient.php`).

| Type | Location | Purpose |
|---|---|---|
| `SmsRecipient` (interface) | `src/SmsSender.php` | Any object with a phone number, for sending |
| `SmsSender` (interface) | `src/SmsSender.php` | Any object that can be used as a sender |
| `PhoneNumber` | `src/SmsSender.php` | Normalised phone number; implements both SmsSender and SmsRecipient |
| `ContactPhoneNumber` | `src/SmsSender.php` | A `PhoneNumber` with a human-readable `$name` label, used in `registerSenderNumber()` |
| `SenderID` | `src/SmsSender.php` | Alphanumeric or numeric sender ID (e.g. "MyChurch"); may carry `$acmaApproved` flag |
| `SmsDelivery` | `src/SmsDelivery.php` | Per-recipient delivery result: status, remote ID, timestamps, raw response, expanded message text (`$message`) |
| `SmsDeliveryBatch` | `src/SmsDelivery.php` | Group of `SmsDelivery` objects from one `send()` action. `$batchId` is null from raw providers; `DbLoggingSmsProvider` sets it to `sms.id`. |
| `SmsStatus` (enum) | `src/SmsStatus.php` | Known delivery status codes |
| `SmsCapability` (enum) | `src/SmsCapability.php` | Feature flags checked via `hasCapability()` |
| `SendSummary` (interface) | `src/SendSummary.php` | Tagged union for send results: `AllSent`, `PartialSuccess`, `Failed` |
| `RegistrationStep` | `src/RegistrationStep.php` | Typed result of a registration state-machine step (see above) |
| `FormField` | `src/FormField.php` | One form field within `RegistrationStep::$fields` |
| `SmsStatuslineConfig` | `src/SmsStatuslineConfig.php` | Config snapshot for the pure statusline maths (built by `Jethro\Sms\makeStatuslineConfig()`) |
| `HttpRequest` / `HttpResponse` | `src/HttpClient.php` | HTTP value objects for provider testing |
| `HttpClient` (interface) | `src/HttpClient.php` | Seam for HTTP — mockable in tests |
| `NativeHttpClient` / `LoggingHttpClient` | `src/HttpClient.php` | Concrete `HttpClient` implementations — real cURL-based client (with identical-request dedup) and a verbose-logging decorator |
| `FakeHttpClient` (abstract) | `src/FakeHttpClient.php` | Base for provider-specific test fakes (e.g. `CellcastFakeHttpClient`) — no network calls |
| `SmsCache` (interface) | `src/SmsCache.php` | Key-value cache for provider cross-request caching |
| `JethroSmsRecipient` | `include/Jethro/Sms/` | `SmsRecipient` with person ID |
| `JethroSmsDelivery` | `include/Jethro/Sms/` | `SmsDelivery` with person ID and database row ID |
| `JethroSmsDeliveryBatch` | `include/Jethro/Sms/` | `SmsDeliveryBatch` with `senderPersonId` (for ownership checks). `batchId` is always the `sms.id` of the persisted send. |
| `SmsRequestRecipients` | `include/Jethro/Sms/` | Parsed request result: recipients, blanks, archived, raw records |
| `SmsStatusIcon` (enum) | `include/Jethro/Sms/` | Canonical icon per delivery status, shared by the Messages tab and admin Messages page |

## SmsStatus codes

Defined in `jethro-sms/src/SmsStatus.php` (string-backed enum). See [Status
Codes](./status-codes) for behavioral notes (v5 remapping, `isFinal()` /
`isOk()` semantics, MySQL storage).

## Config constants

Provider-specific constants are documented in each provider's `fromConstants()`
docblock (see [Configuration Reference](./configuration)). Cross-cutting
constants (`SMS_SENDER`, `SMS_SEND_COOLOFF`, `SMS_SHORTEN_URLS`, etc.) are
read by the bridge layer in `include/jethro_sms.php` and
`Sms\OverridingSmsProvider`.

## Preview mode

> **The live statusline and preview panel are now server-rendered and streamed
> via Datastar SSE** (`?call=sms_statusline`). The cost/segment/unicode-policy
> maths moved out of `resources/js/jethro-sms.js` into
> `jethro-sms/src/sms_statusline.php`. See
> [SMS Datastar / HATEOAS statusline](./SMS_DATASTAR.md). The provider-level
> `$preview` mechanics described below are unchanged; the JS-specifics in the
> two sections below (debounced AJAX, click-to-edit `<span>`) are historical
> — the same behaviour is now driven by Datastar attributes on
> server-rendered markup.

`SmsProvider::send()` accepts `bool $preview = false`. When true:

- **`TokenExpandingSmsProvider`** expands `%tokens%` per recipient, sets `SmsDelivery::$message` to the expanded text, and returns immediately — no inner `send()` call, no HTTP.
- **`DbLoggingSmsProvider`** skips all database inserts. Wraps deliveries in `JethroSmsDelivery` with `databaseId: null`.
- **Concrete providers** return mock `SmsDelivery` objects with `$message` set (for non-token sends that reach them).
- **`LocalBalanceSmsProvider`** skips balance enforcement in preview mode.

`Call_SMS` reads `$_POST['preview']` and returns `{preview: [{personId, name, message, status}]}` — each recipient's expanded message with their name, for the JS to display.

The JS preview panel (`.sms-preview-panel`) is hidden by default. A "Message preview" checkbox (visible when `%tokens%` are available) toggles it. When revealed, a debounced (2s) AJAX call fires on keyup, fetching expanded messages for accurate segment/cost calculation. The counter display shows `"29 chars (1 segment) → 4 recipients = $0.20"` — template length with actual (post-expansion) segment count.

## Per-recipient message editing and batch sending

### Click-to-edit

Each expanded message in the preview panel is a clickable `<span>`. Clicking turns it into a single-line `<textarea>` (Enter is suppressed — no newlines, to avoid implying extra recipients). Escape reverts to the original expanded text. Edited messages are highlighted (`#fff3cd`).

When the template message changes (new preview AJAX response), unedited spans update to the new expanded text; edited spans keep their custom text and remain highlighted.

### Send flow

`Call_SMS` reads `$_POST['message_overrides']` — a `personId => customMessage` map produced by `JethroSMS.getMessageOverrides()` from the edited preview entries.

The send is **one** `sendSms()` call with an entries array:

1. Partition recipients into `$standard` (no override) and overridden, preserving original relative order within each group.
2. Build entries: one entry per distinct message — `['message' => $template, 'recipients' => $standard]` first (if non-empty), then one `['message' => $customMessage, 'recipients' => [$recipient]]` per override.
3. Call `sendSms($entries, [], sender: $sender, sendAt: $sendAt)` — the whole action traverses the decorator chain once. `TokenExpandingSmsProvider` splits token-bearing entries per recipient internally.
4. `DbLoggingSmsProvider` inserts one `sms` row for the whole batch (storing the first entry's pre-expansion `template`) plus one `smsdelivery` row per delivery.
5. `OverridingSmsProvider` computes the cooloff `sendAt` once — trivially shared by all deliveries.
6. `sendSummary()` is called with deliveries and recipients in entry order, so positional matching (improvement 39) remains correct.

`sendSms()` (bridge) accepts either form: `sendSms('Hello', $recips, $sender)`
(single message) or `sendSms([['message' => …, 'recipients' => […]], …], [], $sender)`
(entries).

All deliveries share one `sms` row (one send action), but each recipient gets their own (potentially personalized) message. The common case (no overrides) reduces to a single gateway call.

### Wire format

`SmsDelivery` carries two notable fields beyond status/remoteId:

| Field | Type | Purpose |
|---|---|---|
| `$message` | `?string` | The expanded message text that was (or would be) sent |
| `$statusDetail` | `?string` | Provider-supplied human-readable detail about the latest operation — e.g. why a cancel was refused ("message not found"). Transient; not persisted. |

`send()` returns `\Result<SmsDeliveryBatch, string>`. The batch groups all per-recipient
deliveries from one send action. `SmsDeliveryBatch::$batchId` is null from raw providers;
`DbLoggingSmsProvider` sets it to the `sms.id` of the persisted row (returning a
`JethroSmsDeliveryBatch` with `senderPersonId`). If the DB insert fails after the
gateway send succeeded, a plain batch with a null `batchId` is returned — the send
is still reported as successful (messages went out) and the failure is logged loudly.

### Cancelling

To cancel all scheduled deliveries in a send, call `cancel(SmsDeliveryBatch)`.
The contract (pinned by `jethro-sms/tests/cancel/test_cancel_batch.php`):

- Cancellation uses only each delivery's `remoteId`; other fields are echoed
  unchanged into the result.
- The batch-level Result is success whenever the operation could be attempted;
  `Result::failure` is reserved for operation-level errors (unsupported
  provider, auth failure).
- Per-delivery outcomes are read from each returned delivery: `CANCELLED` on
  success, **unchanged status otherwise** with the upstream/transport reason
  in `statusDetail()`.  Gateways report refusals inside an HTTP-200 envelope
  (e.g. Cellcast `status: false` / "message not found") — providers must
  check the envelope, not just the transport result (see
  `docs/sms/improvements/55-cancel-envelope-errors.md`).
- `DbLoggingSmsProvider` persists each returned delivery's status, so a
  refused cancel leaves the `smsdelivery` row `scheduled`.
- `Call_SMS_Cancel` reports counts plus distinct `statusDetail()` reasons
  ("Cancelled 0 deliveries, 3 failed (message not found).") and only morphs
  a `cancelled` span for deliveries whose cancel succeeded.

## History sync

Providers with `BATCH_DELIVERY_QUERY` expose `listRecentDeliveries(?int $since)`
— all delivery statuses since a timestamp in one (paginated) upstream call.
`DbLoggingSmsProvider` persists any returned statuses to `smsdelivery` as a
side effect, so page refreshes reflect them without AJAX polling.

Two bridge functions build on it (`include/jethro_sms.php`):

- **`checkSynchronized(?int $since)`** — formats upstream deliveries and the
  local `smsdelivery`+`sms` rows for the same period and returns a text diff
  (empty means in sync).
- **`synchronizeHistory(?int $since)`** — imports upstream history: stages
  deliveries in scratch tables (`sms_new`/`smsdelivery_new`), refines with SQL
  (feed dedup, batch grouping, personid resolution), deletes staged rows
  already present locally (heuristic matching — body + 10-minute window for
  batches; remote_id or normalised phone number for deliveries), and
  bulk-inserts the rest. Concurrent runs are serialised with a named lock;
  the scratch-table DDL implicitly commits, so don't call it inside a
  transaction you need to keep atomic.

Admin UI: `Call_Admin_Sms_Sync_History` (`?call=admin_sms_sync_history`,
`PERM_SYSADMIN`) — GET shows a confirmation form, POST runs the import.
Step-by-step pipeline description: [history-sync.md](./history-sync.md).

## Unit tests

The tests are split across two trees, each with its own copy of the
minimal dependency-free harness (`helpers.php` + `run.php`):

- **`jethro-sms/tests/`** — pure-layer tests (cancel, cellcast, overriding,
  providers, statusline, summary, token, values). Run with
  `cd jethro-sms && php tests/run.php` (or `composer test`); no Jethro
  code is loaded.
- **`tests/sms/`** — bridge tests (bridge, cache, format, registration,
  request, status, statuspanel). Run with `php tests/run.php` from the
  repo root.

```
jethro-sms/tests/                    ← pure layer (cd jethro-sms && php tests/run.php)
  helpers.php       ← assertion functions + test registry
  run.php           ← CLI runner; discovers test_*.php files
  cancel/           ← SmsDeliveryBatch cancel() contract
  cellcast/         ← registration, send/balance parsing, delivery updates
  overriding/       ← sender allowlist, cooloff, sender-ID intersection, SMS_SENDER mismatch
  providers/        ← v5 / Template / SMS Broadcast response parsing, deferred-send max delay
  statusline/       ← GSM/UCS-2 segment maths, URL shortening, non-GSM detection, renderStatusline()/renderPreviewPanel(), send-blocked flags (server port of the old jethro-sms-test.js)
  summary/          ← sendSummary() shared-mobile attribution
  token/            ← token expansion, known-token trigger, partial failure, templater
  values/           ← PhoneNumber normalisation/internationalisation

tests/sms/                           ← bridge (php tests/run.php from repo root)
  bridge/           ← provider memoization, chain composition, preview, batch partitioning, history-sync dedup
  cache/            ← SessionSmsCache TTL semantics
  format/           ← _formatDuration() rendering
  registration/     ← structured (no-HTML) registration results + renderer escaping
  request/          ← recipient resolution from request params, sender closed vocabulary
  status/           ← status semantics, delivery status indicator rendering
  statuspanel/      ← admin status panel operation dispatch
```

Run with `php tests/run.php` (all) or `php tests/run.php sms/registration` (filtered).
Test files use `namespace Test\<Area>`, require only the source files they need, and wire providers with fake/spy HttpClient instances to avoid network calls.

All test files share one PHP process, so **constants defined in one file are
visible to all** — guard defines with `if (!defined(...))` and reuse the values
established by existing files (`SMS_SENDER_OPTIONS`, `SMS_PROVIDER`,
`SMS_HTTP_URL`, `SMS_HTTP_POST_TEMPLATE`). A test that needs its own constant
table (e.g. defining `SMS_SENDER`) declares `@isolated-process` in its header
docblock; `run.php` executes it in a child PHP process after the in-process
tests and folds the counts into the grand total.

## Endpoints by provider

### 5CentSMS v5

| SmsProvider method | HTTP call |
|---|---|
| `send()` | `POST /v5/sms` |
| `getBalance()` | `GET /v5/balance` (body auth) |
| `getSenderIds()` / `getSenderNumbers()` | `GET /v5/senderid` (body auth) |
| `updateDelivery()` | `GET /v5/sms/{id}` (body auth) |
| `listRecentDeliveries()` | `GET /v5/sms` (body auth; paginated via `?after=` cursor, capped at 10 pages) |
| `cancel()` (per delivery) | `DELETE /v5/sms/{id}` (body auth) |
| `registerSenderNumber()` | `POST /v5/senderid` |
| `registerSenderId()` | `POST /v5/senderid` + compliance form |

Auth: `key-id` + `key-secret` in JSON body on every request (including GETs — non-standard but required).

### Cellcast

| SmsProvider method | HTTP call |
|---|---|
| `send()` | `POST /api/v1/gateway` |
| `getBalance()` | `GET /api/v1/apiClient/account` (different envelope) |
| `getSenderNumbers()` | `GET /api/v1/customNumber` |
| `updateDelivery()` | `GET /api/v2/report/message/{id}` |
| `listRecentDeliveries()` | `GET /api/v2/report/message?campType=sms&fromDate=…` (paginated) |
| `cancel()` (per delivery) | `POST /api/v1/gateway/cancelScheduleQuickMessage` |
| `registerSenderNumber()` (registration) | `POST /api/v1/customNumber/add` |
| `registerSenderNumber()` (verification) | `POST /api/v1/customNumber/verifyCustomNumber` |
| `registerSenderId()` | `POST /api/v1/business/add` |

Auth: Bearer token in `Authorization` header. Test mode: wraps HTTP client in `CellcastFakeHttpClient` which returns realistic JSON for all endpoints (not just `'OK'` like the v4 fake).

### SMS Broadcast

| SmsProvider method | HTTP call |
|---|---|
| `send()` | `POST https://www.smsbroadcast.com.au/api-adv.php` |
| `getBalance()` | Same endpoint with `action=balance` |
| `getSenderIds()` | Config override only (`SMS_SENDER_OPTIONS`) |

Auth: username + password in URL-encoded POST body. Response is line-based text, not JSON. No delivery polling, no cancellation.

## File Layout

One class per file since the 2026-07-03 restructure: `Sms\Foo` →
`jethro-sms/src/Foo.php`, `Sms\Providers\Foo` → `jethro-sms/src/Providers/Foo.php`,
`Jethro\Sms\Foo` → `include/Jethro/Sms/Foo.php`,
`Jethro\Sms\Providers\Foo` → `include/Jethro/Sms/Providers/Foo.php`.

| File | Namespace | Contents |
|---|---|---|
| `jethro-sms/src/load.php` | — | Canonical loader for the whole package |
| `jethro-sms/src/<ClassName>.php` | `Sms\` | One type per file: `SmsProvider` interface, decorators (`DecoratingSmsProvider`, `TokenExpandingSmsProvider`, `OverridingSmsProvider`), value objects/enums (see table above), `HttpClient` infrastructure, `Templater`, `FakeHttpClient` |
| `jethro-sms/src/Providers/*.php` | `Sms\Providers\` | Concrete providers (`FiveCentSmsV5Provider`, `FiveCentSmsV4Provider`, `CellcastSmsProvider`, `SmsBroadcastSmsProvider`, `TemplateSmsProvider`) plus their delivery subtypes and fake HTTP clients |
| `jethro-sms/src/functions.php` | `Sms\` | Standalone functions: `sendSummary()`, `statusFromV5Code()`, `messageHasTokens()`, `parseSenderIdsFromCsv()`, `formatDeliveryLine(s)()`, `logVerbose()` |
| `jethro-sms/src/sms_statusline.php` | `Sms\` | Statusline/segment maths + `renderStatusline()`/`renderPreviewPanel()` |
| `jethro-sms/src/factory.php` | `Sms\` | providerShortNames(), providerCandidates(), resolveRawProviderClass() — SMS_PROVIDER resolution + auto-detection |
| `jethro-sms/src/result.php` | — | \Result monad (canonical home; required by include/general.php) |
| `jethro-sms/src/support.php` | — | Guarded standalone fallbacks for ifdef()/ents() |
| `jethro-sms/src/cli.php`, `src/Cli/CliEnvironment.php` | `Sms\Cli` | CLI core: CliEnvironment, main(), all action handlers |
| `jethro-sms/src/sms.php`, `sms_cellcast.php`, `templater.php` | — | Backward-compatibility loaders for the pre-restructure monoliths; new code requires `load.php` |
| `jethro-sms/bin/jethro-sms` | — | Standalone CLI (SMS_* constants via --config file or env vars) |
| `include/jethro_sms.php` | `Jethro\Sms\` | Bridge functions: getSmsProvider() factory, sendSms(), request parsing, delivery lifecycle (updateDelivery, cancelSms, loadSmsBatch), history sync (checkSynchronized, synchronizeHistory), opt-outs, HTML rendering (`printSmsModal()`, `printBulkSmsForm()`), insertSms(), classifySmsStatus() |
| `include/Jethro/Sms/*.php` | `Jethro\Sms\` | Bridge types: JethroSmsRecipient, JethroSmsDelivery, JethroSmsDeliveryBatch, SmsRequestRecipients, SessionSmsCache, SmsStatusIcon |
| `include/Jethro/Sms/Providers/*.php` | `Jethro\Sms\Providers\` | Bridge decorators: DbLoggingSmsProvider, LocalBalanceSmsProvider |
| `templates/list_messages.template.php`, `single_message.template.php` | — | Render the Messages tab: message list wrapper and one row per message (status badge, actions) |
| `calls/call_sms.class.php` | — | AJAX send + preview |
| `calls/call_sms_statusline.class.php` | — | Datastar SSE statusline/preview endpoint (see [SMS_DATASTAR.md](./SMS_DATASTAR.md)) |
| `calls/call_sms_info.class.php` | — | AJAX delivery status poll |
| `calls/call_sms_cancel.class.php` | — | AJAX cancel scheduled (single + bulk) |
| `calls/call_sms_balance.class.php` | — | AJAX balance query |
| `calls/call_sms_sendernum.class.php` | — | AJAX register + validate sender number |
| `calls/call_admin_statuspanel_sms.class.php` | — | Admin config status panel |
| `calls/call_admin_statuspanel_operation_sms.class.php` | — | Status panel operation dispatch (registration wizard steps, Datastar-morphed) |
| `calls/call_admin_sms_sync_history.class.php` | — | Admin history-sync confirmation form + import |
| `resources/js/jethro-sms.js` | — | JethroSMS module: send-flow AJAX, registration/OTP UI, cancel links, delivery-status polling (all maths is server-side) |
| `scripts/sms.php` | — | Thin Jethro wrapper around the package CLI: adds person-ID recipients, DB-logged chain, DB-aware cancel, export-smslog |

## Testing
Playwright functional tests use a mock SMS server
(`tests/functional/sms/smsmockserver/`, a PHP app served through the same
nginx + PHP-FPM) to simulate Cellcast and 5CentSMS v5 APIs.

## See Also

- [SMS Design Decisions](./design-decisions)
- [Send Pipeline](./send-pipeline)
- [Provider Abstraction](./provider-abstraction)
