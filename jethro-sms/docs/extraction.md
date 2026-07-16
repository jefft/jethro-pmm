---
title: Extraction from Jethro — entanglements and resolutions
---

# Extraction from Jethro — entanglements and resolutions

In July 2026 the SMS engine was extracted from the Jethro PMM codebase
(`include/sms*.php`, `include/templater.php`, `scripts/sms.php`,
`tests/sms/**`) into this package. The `SmsProvider` interface was already
the documented seam between the pure `Sms\` layer and the `Jethro\Sms\`
bridge layer, so the package boundary follows it. Everything that touches
Jethro's database, session, user system, or ORM stays in Jethro
(`include/jethro_sms.php` and friends); everything provider- and
message-shaped lives here.

Separating the code surfaced the entanglements below. Each is recorded with
the resolution chosen, so the reasoning survives the refactor.

## 1. `\Result` lived in `include/general.php`

**Entanglement.** The engine returns `\Result` from every provider method,
but the class was defined in Jethro's 1,200-line grab-bag `general.php` —
and by 2026 non-SMS code (bulk email, admin status panels) had adopted it
too, so it could not simply move out of Jethro's sight.

**Resolution.** The class moved verbatim to `src/result.php` and that file
is now its canonical home. `include/general.php` requires it from the
package, so embedded and standalone use share exactly one definition.
Direction of dependency is Jethro → package, never the reverse.

## 2. `ifdef()` and `ents()` globals

**Entanglement.** Provider `fromConstants()` methods read configuration via
Jethro's global `ifdef()`; a handful of user-facing error strings escape
HTML via `ents()`. Both are three-line functions defined in `general.php`.

**Resolution.** `src/support.php` defines both, guarded by
`function_exists()`. Embedded in Jethro, `general.php` has already defined
them and the shims are no-ops; standalone, the package's copies apply. The
bodies are verbatim copies — small enough that duplication beats an
abstraction, but they must be kept in sync (they are stable; last changed
years ago). Configuration remains constant-based (`SMS_*`) by design: that
is the package's public configuration surface, documented in the README.

## 3. `TokenExpandingSmsProvider` called `getUrlShortener()` directly

**Entanglement.** The pure token-expansion decorator registered a
`%(shorten url)%` templater function that called Jethro's global
`getUrlShortener()` — which drags in `include/url_shortener.php` and a
vendored Cloudflare client. A pure-layer class was reaching into Jethro
infrastructure at send time.

**Resolution.** `TokenExpandingSmsProvider` now takes two optional
constructor closures, `$shortenFn` and `$previewShortenFn`. The Jethro
bridge factory (`getSmsProvider()` in `include/jethro_sms.php`) injects
wrappers around `getUrlShortener()`; standalone use (CLI, tests) defaults
to identity (URLs pass through unshortened). No behaviour change embedded;
the package gains a documented seam.

## 4. `sms_statusline.php` mixed pure maths with Jethro state

**Entanglement.** The GSM/UCS-2 segment maths, cost lines, and
statusline/preview renderers are pure — but
`SmsStatuslineConfig::fromConstants()` snapshotted Jethro state:
`\Jethro\Sms\getSmsProvider()` for segment cost, `getSmsBalance()`,
`$GLOBALS['user_system']->havePerm(PERM_SYSADMIN)`.

**Resolution.** The file moved to the package under the `Sms\` namespace
with `fromConstants()` **removed from the class**. The snapshot factory now
lives in the bridge as `Jethro\Sms\makeStatuslineConfig()`, which reads
Jethro state and constructs the pure `Sms\SmsStatuslineConfig` value
object. The class itself stays injectable and constant-free — exactly what
its tests always wanted.

## 5. `scripts/sms.php` booted the whole of Jethro

**Entanglement.** The CLI bootstrapped `conf.php`, `init.php`, the database
and `User_System` — needed only because `--to=42` accepts Jethro person IDs
and because sends are DB-logged through the bridge chain.

**Resolution.** The CLI core (argument parsing, action dispatch, output
rendering) moved to the package (`src/cli.php`, entry point
`bin/jethro-sms`). It is parameterised by a provider factory and a
recipient resolver. Standalone, configuration comes from a
`--config=<file>` PHP constants file or `SMS_*` environment variables, and
recipients must be phone numbers. Jethro's `scripts/sms.php` remains as a
thin wrapper: it boots Jethro, supplies the DB-logging provider chain and a
person-ID-aware recipient resolver, then delegates to the same CLI core.

## 6. Bridge decorators stay in Jethro — deliberately

`DbLoggingSmsProvider`, `SessionSmsCache`, `LocalBalanceSmsProvider`
(reads `sms_purchases`), `EJSmsProvider` (sends email via Jethro's
`Emailer`), and the deprecated `sms_ej.php` all remain in Jethro. They
implement the package's `SmsProvider` interface, which *is* the extension
seam — a host application composes its own decorators around the package's
raw providers. Inventing package-side interfaces for balance sources or
email hooks would have added abstraction with a single consumer each.

## 7. Tests and harness

Pure-layer test directories moved here (`tests/`); tests that exercise the
bridge (`getSmsProvider()` chain assembly, DB logging, request parsing,
session cache) stayed in Jethro's `tests/sms/`. The 136-line
helpers/runner harness is duplicated into both trees rather than shared —
it is tiny, dependency-free, and the two copies may now diverge to suit
each host. Package tests must not include `include/general.php`; they get
`Result`/`ifdef`/`ents` from `src/load.php`.

## 8. Documentation

Provider-level reference docs moved to `docs/` in this package. Jethro
keeps the bridge- and UI-level docs (Datastar statusline, database schema,
web configuration) and the Docusaurus site links to the package docs where
needed. The split is recorded in each moved file's front matter.
