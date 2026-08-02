# Family table: `history` column is NOT NULL with no DEFAULT

**Verdict: real bug — any raw SQL INSERT that omits `history` fails with a
database error, even though the application always sets it through its ORM
layer.**

## What happens

The `family` table's `history` column is defined as `text NOT NULL` with no
default value:

```sql
`history` text NOT NULL,
```

The application (`DB_Object::create()` at line 200 of
`include/db_object.class.php`) always initialises `history` to a serialised
array:

```php
$this->values['history'] = Array(time() => $created);
```

So families created through the UI and the CSV importer work fine. But any
direct SQL INSERT that omits `history` fails:

```sql
INSERT INTO family (family_name, status) VALUES ('Adams', 'current');
-- ERROR 1364 (HY000): Field 'history' doesn't have a default value
```

This bit the functional test suite's bulk-data insertion path
(`tests/functional/tests/walkthrough.spec.ts`) which creates families via
raw `INSERT` statements.

## Why the current behaviour is bad

- **Schema is lying.** The column claims to be `NOT NULL`, but the only
  programmatic guarantee it's set comes from a PHP method 4 abstraction
  layers away. A reader of `SHOW CREATE TABLE` has no way to know that the
  ORM fills it.
- **Bites automation and tooling.** Scripts, tests, CI, or DB tools that
  insert directly into `family` hit a confusing error that reads like a
  missing required field — and `history` is anything but required.
- **No DB-level safety.** If a future refactor removes the ORM's
  initialisation or an edge-case code path bypasses `create()`, inserts
  will fail silently in production with no compile-time or static-analysis
  warning.

## Suggested improvement

Set a sensible default for the column:

```sql
ALTER TABLE family MODIFY history text NOT NULL DEFAULT '';
```

The empty string is the closest SQL-level equivalent of "no history yet".
The ORM's `create()` path already overwrites it, so no code change is
needed elsewhere.

## Where

- `upgrades/*.sql` (most recent upgrade file) — add the `ALTER TABLE`
- `include/db_object.class.php`, line 200 — verifies the ORM already
  handles this
