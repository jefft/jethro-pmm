# Jethro PMM — Agents Reference

API cleanliness > backwards-compatibility.
Ask rather than guess.
Keep Docblocks with PHPStan-compatible type signatures up-to-date when modifying code. Docblocks document code objectively, minimising session-specific details.
Your memory is docs/docs/developer/reference/**. Add or update files as changes are made. Create YAML front matter as needed. Add references to these files in docblocks for future agent sessions.
Use the grill-me skill.
The user may change files at any time.
One commit per logical change — see Before committing below.
Never post/comment or alter issues on github or online.
Do not mention real church names/ids/people/mobile numbers in comments, commits or example code, except 'StBarnabas' or 'St Johns WPH' which may be used as sample Sender ID. Ensure no accidental mentions of 'connectcc', 'coastec', 'easyjethro', 'asne' or 'easy jethro' (case-insensitive) in code, comments, commits, test data or developer docs. Exception: deliberate attribution of the hosting provider in end-user docs (`docs/docs/administration/config/sms.mdx`, `docs/docs/changelog/index.mdx`) is allowed.

Mobile numbers (aka 'senderid') that need to look realistic:
- Prefer one of ACMA's 30 reserved fictional mobiles (https://www.acma.gov.au/phone-numbers-use-tv-shows-films-and-creative-works), e.g. `0491 570 156`–`0491 570 159`, `0491 570 006`. With country code: `61491570156`.
- When more distinct numbers are needed than ACMA provides (e.g. many test persons, which must not share a mobile), use the **pseudo-ACMA block** `0491 570 000`–`0491 579 999` (`61491570000`–`61491579999`). It contains all 30 ACMA numbers but is not itself reserved by ACMA, so numbers in it are merely unlikely to resolve. Never use numbers outside this block.

Server-specific instructions (version control, local paths, DB access, GitHub repo) live in `AGENTS.local.md` (untracked); read it if present:

@AGENTS.local.md

## Non-obvious conventions

- **Commit messages**: purpose/function/use-case first, then high-level implementation overview.
- **View filenames control menu placement**: `view_3_persons__5_sms.class.php` → Admin submenu, item "sms", ordered 9th. The class name becomes `View_persons__sms`. Top-level views use `view_N_name.class.php` (no `__M_` ordering).
- **Database changes**: add SQL to the most recent `upgrades/*-upgrade-to-*.sql`; never modify the DB directly. Upgrade SQL must be idempotent.
- **`ents()`** = `htmlspecialchars()` wrapper. Always use `ents()`, never `htmlspecialchars()` directly.
- **`ifdef($constantName, $fallback)`** — safe constant read with fallback. Use for all optional `conf.php` constants.
- **Settings glossary**: `docs/docs/docs/reference/settings-glossary.mdx` — every config constant configuring Jethro (not scripts/*.php), organized by category.
- **Functional test harness**: One shared nginx/PHP-FPM/MariaDB backs *all* Playwright scenarios; each gets its own config purely via a URL-prefix convention (`/tests/functional/sms/<name>/` → `<name>.conf`). Read `docs/docs/developer/DEVELOPMENT_FUNCTESTS.md` before touching anything under `tests/functional/` or writing a new functional test.
- Do not use `_()` / `gettext()` for text unless the code line already uses it.

## Before committing


1. **Write the commit message**. 120 char line wrap. Leading 'subject' line in form:
   ```
   $Component: Summary of the change (#nnnn)

   <Change details here>

   Fixes #nnnn
   ```

where $Component is a one-word description affected area, e.g. 'SMS', 'Scripts', 'Services', etc, and may be omitted if unclear. `(#nnnn)` references a bug report id if the issue has a bug.


2. **Syntax-check PHP and JS**:

   `./devbox run lint` (or run the implied `php -l` command directly)

3. **Run unit tests**:

   ```bash
   ./devbox run unittests
   ```

4. **For web-traceable changes**, smoke-test the page before and after:

   ```bash
   ./bin/jethrocurl '?view=whatever'  # watch for stacktraces
   ```

 5. **Changelog for feature-complete end-user-relevant changes**, add an entry to docs/docs/changelog/index.mdx

## Vendored files — do not edit

| File | Library |
|------|---------|
| `include/tbs.class.php` | TinyButStrong |
| `include/tbs_plugin_opentbs.php` | OpenTBS |
| `include/htmLawed.php` | htmLawed 1.1.9.5 |
| `calls/call_envelopes.class.php` | third-party envelope printing |
| `resources/js/datastar.min.js` | Datastar v1.0.4 (drives the SMS statusline/preview SSE — see docs/docs/developer/reference/sms/SMS_DATASTAR.md) |

## Database

`person`, `person_group`, `member`, `abstract_note` are views. Use the underlying `_`-prefixed tables (`_person`, etc.), or `set @current_user_id=1;` to query through the view.

## PHPStan

Not currently used.

#```bash
#./vendor/bin/phpstan analyse --error-format=raw 2>&1                      # all errors
#./vendor/bin/phpstan analyse --error-format=raw 2>&1 | grep "might not"   # undefined-variable errors
#```
#
#Config: `phpstan.neon` (level 6). Bootstrap: `phpstan-bootstrap.php`.
#
#**Common fix**: when it says "Variable might not be defined", initialize to `null`/`[]`/`''` before the `if`/`switch` blocks.
#
#### Template `$this` scope
#
#Templates included inside class methods have `$this` available but PHPStan doesn't know. Add `/** @var ClassName $this */` at the top.
#

### Utility scripts

| Script | Purpose |
|--------|---------|
| `./bin/jethrocurl` | Authenticated curl to the demo instance — test a URL before and after changes. Also handles hosts behind Cloudflare Access: when `CLOUDFLARE_ACCESS_CLIENT_ID`/`CLOUDFLARE_ACCESS_CLIENT_SECRET` are set (in `devbox.json` env), run as `devbox run jethrocurl <url>` and the service token is presented automatically |
| `./bin/view2file.php` | Resolve a `?view=…` name to its source file, e.g. `admin__sms` → `views/view_3_persons__5_sms.class.php` |

## Tests

```bash
# Unit (includes jethro-sms tests)
./devbox run unittests

# Functional — Playwright (requires './devbox services up -b' first)
./devbox run functests
```

Func tests use the `jethro_functest` database which can be regenerated with `./devbox run demodata`.

When writing functional tests that interact with the DOM, prefer existing `id` or `class` attributes; add them to the PHP/HTML if they make testing cleaner.

### Functional test harness

Full writeup: `docs/docs/developer/DEVELOPMENT_FUNCTESTS.md` — covers how one shared nginx/PHP-FPM/MariaDB serves every scenario under its own URL prefix, how `devbox.d/functest_jethro_server/conf.php` resolves `<name>.conf` from `REQUEST_URI`, and how the mock SMS proxy fits in.

## SMS

The provider-independent SMS engine is the **jethro-sms package**
(`jethro-sms/` — independently buildable/testable; end product is the
`bin/jethro-sms` CLI). The Jethro bridge (DB logging, session cache,
person lookup) stays in `include/jethro_sms.php` and friends.

Before editing any file under `jethro-sms/src/`, `include/jethro_sms.php`,
`calls/call_sms*.php`, `scripts/sms.php`, or `resources/js/jethro-sms.js`, read
`docs/docs/developer/sms/smsarchitecture.md` first
(extraction decisions are in `jethro-sms/docs/extraction.md`).

`jethro-sms/src/Templater.php` is the s-expression template engine used by
`TokenExpandingSmsProvider` for `%(fn arg)%` token expansion.  Variables
are `%word%` (legacy) or `%(word)%`; function calls use `%(fn arg1 arg2)%`.

### SMS mock server

The SMS mock server lives at `smsmockserver/` and is served through the same PHP-FPM that runs Jethro. The nginx template (`devbox.d/nginx/nginx.template`) routes `/smsmockserver/...` to its front controller; `/smsmockserver/tests/functional/{dir}/{scenario}/...` loads `tests/functional/{dir}/{scenario}.smsmock.php`. Test configs set `SMS_CELLCAST_URL`/`SMS_5CENTSMS_URL`/… to Jethro-root-relative paths like `/smsmockserver/tests/functional/sms/sms-bulk`. Its database (`jethro_functest_smsmockserver`) is initialized by `functest_database_setup` in `process-compose.yml`.

Test specs access the mock server's `/meta/` endpoints through the `mockMeta()` helper in `tests/functional/sms/smsmock-url.ts`, which reads `FUNCTEST_WEB_PORT` (same as `playwright.config.ts`'s `HOST`).


## Permissions

View class filenames encode minimum permission level: `view_0_` = admin, `view_1_` = standard user. Constants in `include/permission_levels.php`.

Permission checks: `$GLOBALS['user_system']->havePerm(PERM_*)`.
Feature flags: `$GLOBALS['system']->featureEnabled('FLAG_NAME')`.

## Nginx

See `nginx/CLAUDE_NGINX.md`.
