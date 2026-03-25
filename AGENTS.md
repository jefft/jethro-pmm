# Jethro PMM — Agents Reference

API cleanliness > backwards-compatibility.
Ask rather than guess.
Keep Docblocks with PHPStan-compatible type signatures up-to-date when modifying code.
Your memory is docs/docs/developer/reference/**. Add or update files as changes are made. Create YAML front matter as needed. Add references to these files in docblocks for future agent sessions.
Use the grill-me and jujutsu skills.
The user may change files at any time.
Jujutsu VCS. One commit per logical change — see Before committing below.

## Non-obvious conventions

- unset LD_LIBRARY_PATH before running `mysql` or `mariadb`. 
- **Version control**: jujutsu (`jj`), not git. No staging area — all tracked changes are committed.
- **Jujutsu descriptions**: purpose/function/use-case first, then high-level implementation overview.
- **View filenames control menu placement**: `view_3_persons__5_sms.class.php` → Admin submenu, item "sms", ordered 9th. The class name becomes `View_persons__sms`. Top-level views use `view_N_name.class.php` (no `__M_` ordering).
- **Database changes**: add SQL to the most recent `upgrades/*-upgrade-to-*.sql`; never modify the DB directly. Upgrade SQL must be idempotent.
- **`ents()`** = `htmlspecialchars()` wrapper. Always use `ents()`, never `htmlspecialchars()` directly.
- **`ifdef($constantName, $fallback)`** — safe constant read with fallback. Use for all optional `conf.php` constants.
- **Settings glossary**: `docs/docs/docs/reference/settings-glossary.mdx` — every config constant configuring Jethro (not scripts/*.php), organized by category.
- **Functional test harness**: one shared nginx/PHP-FPM/MariaDB backs *all* Playwright scenarios; each gets its own config purely via a URL-prefix convention (`/tests/functional/sms/<name>/` → `<name>.conf`). Read `docs/docs/developer/reference/functional-testing.mdx` before touching anything under `tests/functional/` or writing a new functional test.

## Before committing

1. Ask the user before committing.

2. **Set the jujutsu change description** via `./bin/jjdesc`:

   ```bash
   ./bin/jjdesc <<'EOF'
   Summary of the change

   Details about why and how.
   EOF
   ```

3. **Syntax-check PHP and JS**:

   `devbox run lint`

4. **Run unit tests**:

   ```bash
   php tests/unit/run.php
   ```

5. **For web-traceable changes**, smoke-test the page before and after:

   ```bash
   ./bin/jethrocurl '?view=whatever'  # watch for stacktraces
   ```

## Github

Use `bunx gh --repo jefft/jethro-pmm` to interact with Github e.g. to inspect action run output.


## Vendored files — do not edit

| File | Library |
|------|---------|
| `include/tbs.class.php` | TinyButStrong |
| `include/tbs_plugin_opentbs.php` | OpenTBS |
| `include/htmLawed.php` | htmLawed 1.1.9.5 |
| `calls/call_envelopes.class.php` | third-party envelope printing |
| `resources/js/datastar.min.js` | Datastar v1.0.2 (drives the SMS statusline/preview SSE — see docs/docs/developer/reference/sms/SMS_DATASTAR.md) |

## Database

```bash
/opt/atl_manage/bin/atl_mysql             # 'mysql' cli - Edit DB, no password, full data
```

`person`, `person_group`, `member`, `abstract_note` are views. Use the underlying `_`-prefixed tables (`_person`, etc.), or `set @current_user_id=1;` to query through the view.

## PHPStan

```bash
./vendor/bin/phpstan analyse --error-format=raw 2>&1                      # all errors
./vendor/bin/phpstan analyse --error-format=raw 2>&1 | grep "might not"   # undefined-variable errors
```

Config: `phpstan.neon` (level 6). Bootstrap: `phpstan-bootstrap.php`.

**Common fix**: when it says "Variable might not be defined", initialize to `null`/`[]`/`''` before the `if`/`switch` blocks.

### Template `$this` scope

Templates included inside class methods have `$this` available but PHPStan doesn't know. Add `/** @var ClassName $this */` at the top.

## CLI

```bash
make build                               # build bin/jethro (or: devbox run build)
bin/jethro --help                        # all output is JSON
```

### Utility scripts

| Script | Purpose |
|--------|---------|
| `./bin/jjdesc` | Set the current jj commit description from stdin (summary line, blank line, details) |
| `./bin/jethrocurl` | Authenticated curl to the demo instance — test a URL before and after changes |
| `./bin/view2file.php` | Resolve a `?view=…` name to its source file, e.g. `admin__sms` → `views/view_3_persons__5_sms.class.php` |

## Tests

```bash
# Unit (includes jethro-sms tests)
php tests/unit/run.php

# Regression
./tests/regression/jethro_regression_tests.nu --testall
./tests/regression/jethro_regression_tests.nu --saveall  # regen snapshots after intentional changes

# Functional — Playwright (requires devbox services up -b first)
cd tests/functional && npx playwright test --reporter=list
```

Func tests use the `jethro_functest` database which can be regenerated with `devbox run jethro_db_demodata_load`.

Don't add functional tests unless asked.
When writing functional tests that interact with the DOM, prefer existing `id` or `class` attributes; add them to the PHP/HTML if they make testing cleaner.

### Functional test harness

Full writeup: `docs/docs/developer/reference/functional-testing.mdx` — covers how one shared nginx/PHP-FPM/MariaDB serves every scenario under its own URL prefix, how `devbox.d/functest_jethro_server/conf.php` resolves `<name>.conf` from `REQUEST_URI`, and how the mock SMS proxy fits in.

### SMS mock server

The SMS mock server lives at `smsmockserver/` and is served through the same PHP-FPM that runs Jethro. The nginx template routes `/tests/functional/sms/{profile}/(api|meta)/...` to its front controller — test configs set `SMS_CELLCAST_URL` and `SMS_5CENTSMS_URL` to Jethro-root-relative paths like `/tests/functional/sms/sms-bulk`. Each profile's behaviour is defined in `tests/functional/sms/{profile}.smsmock.php`. Its database (`jethro_functest_smsmockserver`) is initialized by `functest_database_setup` in `process-compose.yml`.

Test specs access the mock server's `/meta/` endpoints through the `mockMeta()` helper in `tests/functional/sms/smsmock-url.ts`, which reads `FUNCTEST_WEB_PORT` (same as `playwright.config.ts`'s `HOST`).

## SMS

The provider-independent SMS engine is the **jethro-sms package**
(`jethro-sms/` — independently buildable/testable; end product is the
`bin/jethro-sms` CLI). The Jethro bridge (DB logging, session cache,
person lookup) stays in `include/jethro_sms.php` and friends.

Before editing any file under `jethro-sms/src/`, `include/jethro_sms.php`,
`include/sms_*.php`, `calls/call_sms*.php`, `scripts/sms.php`, or the SMS
sections of `resources/js/jethro.js`, read
`docs/docs/developer/reference/sms/smsarchitecture.md` first
(extraction decisions are in `jethro-sms/docs/extraction.md`).

`jethro-sms/src/templater.php` is the s-expression template engine used by
`TokenExpandingSmsProvider` for `%(fn arg)%` token expansion.  Variables
are `%word%` (legacy) or `%(word)%`; function calls use `%(fn arg1 arg2)%`.

## Permissions

View class filenames encode minimum permission level: `view_0_` = admin, `view_1_` = standard user. Constants in `include/permission_levels.php`.

Permission checks: `$GLOBALS['user_system']->havePerm(PERM_*)`.
Feature flags: `$GLOBALS['system']->featureEnabled('FLAG_NAME')`.

## Nginx

See `nginx/CLAUDE_NGINX.md`.
