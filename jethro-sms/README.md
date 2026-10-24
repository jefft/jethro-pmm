# jethro-sms

Provider-agnostic PHP SMS engine, extracted from [Jethro PMM](https://jethro.org.au).

- **Providers**: MobileMessage, 5CentSMS v5, Cellcast, plus a generic
  HTTP-template provider (and the deprecated 5CentSMS v4 shape).
- **`SmsProvider` interface** with capability flags, decorators
  (`TokenExpandingSmsProvider`, `DeduplicatingSmsProvider` — one message per
  shared mobile number, `OverridingSmsProvider`), and a `Result`
  monad throughout. Pure functions at the core; HTTP at the edge behind a
  mockable `HttpClient` seam.
- **Token expansion**: `%firstname%`-style per-recipient personalisation via
  an s-expression templater (`%(fn arg)%`).
- **Segment/cost maths**: GSM 03.38 vs UCS-2 detection, segment counting,
  statusline/preview rendering.
- **Opaque registration state machines** for sender IDs and sender numbers.
- **CLI**: `bin/jethro-sms` — send, balance, delivery status, cancel,
  opt-outs, sender registration.

## Requirements

PHP ≥ 8.1. No runtime dependencies.

## Install / build

```bash
composer install   # metadata + autoload only; there are no dependencies
```

Or skip Composer entirely and `require_once 'src/load.php'` — the package
is written for require-once consumers too.

## Configuration

Configuration is via `SMS_*` PHP constants (the package's public
configuration surface). Standalone, define them in a PHP file and pass it
to the CLI with `--config=<file>`, or export them as environment variables
(`SMS_PROVIDER`, `SMS_CELLCAST_APIKEY`, …). See `docs/configuration.md`
for the full constant reference and `SmsProvider::getConstants()` for each
provider's requirements.

## Test

```bash
composer test        # = php tests/run.php
php tests/run.php sms/cellcast   # run a subset
```

## CLI

```bash
bin/jethro-sms info                       # provider + constant diagnostics
bin/jethro-sms balance
bin/jethro-sms sms --from=MyChurch --to=0491570159 'Hello %firstname%'
bin/jethro-sms smsinfo --id=<remote_ref>
bin/jethro-sms cancel --id=<remote_ref>
bin/jethro-sms optouts
```

Recipients are phone numbers. (Inside Jethro, `scripts/sms.php` wraps this
CLI and additionally accepts person IDs and logs sends to the database.)

### Machine-readable output

`--json` (before the action) prints exactly one JSON document on stdout;
progress lines go to stderr, so the output pipes cleanly:

```bash
bin/jethro-sms --json balance | jq .data.balance
bin/jethro-sms --json sendernums | jq -r '.data[].number'
```

```json
{"ok":true,"action":"balance","data":{"balance":1372}}
{"ok":false,"action":"balance","error":"Unable to connect to SMS Server: …"}
```

`data` is the provider-independent value from the API (deliveries, sender
IDs/numbers, opt-outs, registration steps, …) — never the upstream
provider's own JSON, so the schema is stable across providers. Exit codes
are unchanged (1 on `"ok": false`). Credential constants are masked in
`info` output. Implementations: `jethro-sms/src/Cli/{Text,Json}Presenter.php`.


## History

Extracted from Jethro's `include/sms*.php` in July 2026. The entanglements
found during extraction and how each was resolved are documented in
[docs/extraction.md](docs/extraction.md).
