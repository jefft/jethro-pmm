# Row-security views return empty rows (not an error) without a session user

**Verdict: not a bug — intentional row-level security. The silent empty result
is the footgun, and it is worth fixing by documentation (and ideally a loud
failure).**

## What happens

`person`, `person_group`, `abstract_note` and `member` are **views**, not
tables. Their `WHERE` clause begins with `getCurrentUserID() IS NOT NULL` and
then filters rows by what that user may see (own record, family, congregation
and group restrictions):

```sql
-- db_objects/person.class.php (~lines 215-232)
WHERE getCurrentUserID() IS NOT NULL
  AND (p.id = getCurrentUserID() OR ... congregation/group restriction checks ...)
```

The application always sets the session variable behind `getCurrentUserID()`
on every request, so the app itself is fine. But any SQL run **outside** an
app request — CLI, tests, scripts, DB tools:

```sql
SELECT COUNT(*) FROM person;                 -- returns 0
SELECT id FROM person WHERE last_name='X';   -- returns nothing
```

returns **zero rows with no error at all**. The real data lives in the base
tables `_person`, `_person_group`, `_abstract_note` — which are not discoverable
without reading the app source (`_`-prefixed tables also read like "archived",
so they are easy to overlook).

## Why the current behaviour is bad

- **Silent wrong results.** A count of 0 or a missing row reads as "no data
  exists", which is the opposite of the truth. Tests and scripts then assert on
  the wrong thing or fail for the wrong reason.
- **Masks real problems.** A genuine data-loss incident in a table would look
  identical to a missing session variable.
- **Undiscoverable.** Nothing in the schema or docs tells a reader that
  `person` is a restricted view and `_person` is the table.

## Suggested improvement

1. **Document the split** — this file, plus a comment above the `getViewSQL`
   definitions naming the base table for each view (`_person`,
   `_person_group`, `_abstract_note`).
2. **Make misuse loud.** When queried without a session user, the view should
   fail instead of returning empty. In practice the clean way is to have
   `getCurrentUserID()` (or the view definition) raise an error when the
   session variable is unset. MariaDB does not allow `SIGNAL` inside stored
   functions used in views, so this may need a view/function restructure —
   worth an issue to investigate, not a quick patch. The app always sets the
   variable, so a loud failure cannot break any real request.

## Where

- `db_objects/person.class.php` — `getViewSQL()` (~lines 215-232)
- `db_objects/person_group.class.php` — `getViewSQL()` (~lines 143-147)
- `db_objects/abstract_note.class.php` — `getViewSQL()` (`abstract_note` view)
- `member` view (built from `_person` + `person_group_membership`)
