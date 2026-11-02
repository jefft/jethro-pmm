---
title: Contact field permissions
sidebar_position: 11
---

# Contact field permissions

Staff can be granted these person fields independently, as **Viewable Person Fields** on the user-account form:

- `VIEWADDRESS` — family address and home phone
- `VIEWEMAIL` — person email
- `VIEWMOBILE` — person mobile and work phone
- `VIEWREMARKS` — contact remarks
- `VIEWCUSTOMFIELDS` — all custom fields
- `VIEWSENSITIVE` — custom fields marked sensitive (see [Sensitive custom fields](./sensitive-custom-fields.md))

Reads are masked by MySQL views. Writes are guarded in PHP, because Jethro writes through `DB_Object`, `Person` and `Family`.

## Data permissions

Data visibility is a separate mask from the function/action permissions:

```text
staff_member.data_permissions
  DATA_PERM_VIEWADDRESS      = 134217728
  DATA_PERM_VIEWEMAIL        = 536870912
  DATA_PERM_VIEWMOBILE       = 268435456
  DATA_PERM_VIEWREMARKS      = 33554432
  DATA_PERM_VIEWCUSTOMFIELDS = 67108864
  DATA_PERM_VIEWSENSITIVE    = 16777216
```

`DEFAULT_DATA_PERMISSIONS` (1040187392) grants all but sensitive custom fields to new accounts. On upgrade, existing staff receive the same bits once, guarded by the `DATA_PERMISSIONS_MIGRATED` setting so a re-run cannot restore access an administrator revoked.

`SYSADMIN` holds every data permission, in PHP (`haveDataPerm()`) and in SQL. It remains subject to congregation/group row restrictions.

## Session and SQL function

`User_System` calls `JethroDB::setCurrentUserID($id, $dataMask)` on every request. It sets:

```sql
@current_user_id
@current_user_data_permissions
```

The mask is taken from the session, the same source `haveDataPerm()` uses, so PHP and SQL always agree on which fields are visible. A SysAdmin gets `-1` (every bit). Changes to an account's data permissions take effect at its next login.

```sql
currentUserHasDataPerm(symbol)
```

returns true for member sessions, for public/CLI mode (`@current_user_id = -1`), and for staff sessions whose mask holds the permission named by `symbol` (`'VIEWADDRESS'`, `'VIEWEMAIL'`, `'VIEWMOBILE'`, `'VIEWREMARKS'`, `'VIEWCUSTOMFIELDS'` or `'VIEWSENSITIVE'`). The symbol-to-bit mapping is inside the function and mirrors `include/data_permissions.php`.

## Masked views

The contact columns stay in `_person` and `_family`. The `person` and `family` views blank a field when the session lacks its permission:

```sql
CREATE VIEW person AS
SELECT p.id, p.first_name, p.last_name, p.gender, p.age_bracketid,
       CASE WHEN currentUserHasDataPerm('VIEWEMAIL') THEN p.email ELSE '' END AS email,
       CASE WHEN currentUserHasDataPerm('VIEWMOBILE') THEN p.mobile_tel ELSE '' END AS mobile_tel,
       CASE WHEN currentUserHasDataPerm('VIEWMOBILE') THEN p.work_tel ELSE '' END AS work_tel,
       CASE WHEN currentUserHasDataPerm('VIEWREMARKS') THEN p.remarks ELSE '' END AS remarks,
       ...
FROM _person p
WHERE ...;
```

The `family` view does the same for `address_street`, `address_suburb`, `address_state`, `address_postcode` and `home_tel`. The `custom_field_value` view returns only rows of fields the session may see.

`Person` and `Family` read through the views and write `_person` / `_family`. Scripts that run without a Jethro session (shell scripts using the `mysql` client) must read the base tables `_family` and `_custom_field_value`; the views would return blank or no data.

## Write guard

`Field_Access` maps contact fields to their data permission and implements:

- `canRead($field)` / `canWrite($field)` — write requires the action permission (the object's normal save check) plus data visibility
- `filterWritableContactChanges(&$old_values, &$values)` — drops hidden or crafted contact changes before `DB_Object::save()` and reverts their values
- `filterWritableContactValues(&$values)` — removes hidden contact values before `DB_Object::create()`

A hidden field is not changed by a crafted POST, import, bulk update or action plan.

## History

Person and family history records old and new values. A line about a protected field is tagged with the field (`Field_Access::tagHistoryLine()`, e.g. `[hidden:mobile_tel]` or `[hidden:cf:7]`) and kept on one stored line. On display, `Field_Access::filterHistoryEntry()` looks up the field's current permission and reduces a line the viewer may not see to "*Field* changed". Untagged lines written before 2.40 are recognised by their "*Label* changed from" prefix. A line whose field identity is no longer known (for example, a renamed or deleted custom field) is treated as a custom-field history line and its values are shown only to users with both Custom fields and Sensitive custom fields permissions.

## Migration and installer

`upgrades/2026-upgrade-to-2.40.sql` renames `family` to `_family` and `custom_field_value` to `_custom_field_value`, adds `staff_member.data_permissions`, the `currentUserHasDataPerm()` function, the `DEFAULT_DATA_PERMISSIONS` setting, the masked views and the one-time grant. The installer creates the same for fresh installs and records the grant as done.

## Tradeoffs and gaps

- Masking applies to the views, not to raw SQL against `_person`, `_family` or `_custom_field_value`.
- `CASE` expressions can prevent index use when filtering or sorting on a masked contact field.
- Member sessions keep their existing all-contact behaviour.

## Key files

- `include/data_permissions.php` — bit definitions
- `include/field_access.php` — read/write guard and history filtering
- `include/jethrodb.php`, `include/user_system.class.php` — session mask
- `db_objects/person.class.php`, `db_objects/family.class.php`, `db_objects/staff_member.class.php`
- `include/installer.class.php`, `upgrades/2026-upgrade-to-2.40.sql`
- `tests/unit/test_field_access.php`
