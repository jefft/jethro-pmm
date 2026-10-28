# Custom-field attachments

Person custom fields of type `attachment` store files on the filesystem and
metadata in `custom_field_attachment`.

## Storage

- Root: `ATTACHMENTS_ROOT_PATH`, default `<Jethro root>/attachments`.
- Tree: `<root>/person/<personid>/field/<fieldid>/<token>`.
- `token` is random hex plus an optional safe extension; it is unique in the
  metadata table.
- `custom_field_value.value_text` holds `custom_field_attachment.id`.
- Files are never served directly. `?call=custom_field_attachment&id=N` checks
  the restricted `person` view and the field's visibility (`Custom fields`,
  plus `Sensitive custom fields` for a sensitive field), then
  sends the file with `Content-Disposition: attachment`.

The default directory is denied by `nginx/conf.d/php-fpm.conf` and contains an
`.htaccess` deny rule. A configured root must be protected by deployment
configuration if it sits inside the web root.

## Code map

- `include/attachments_manager.class.php` — path building, upload validation,
  storage, deletion, and safe recursive cleanup.
- `db_objects/custom_field_attachment.class.php` — metadata table, staged
  uploads, lookup and cleanup helpers.
- `db_objects/custom_field.class.php` — `attachment` type, parameters, widget,
  formatting, and field deletion.
- `db_objects/person.class.php` — load/print/process/save integration and
  attachment cleanup on archive/delete.
- `calls/call_custom_field_attachment.class.php` — authenticated download.
- `upgrades/2026-upgrade-to-2.40.sql` — idempotent table creation.

## Save lifecycle

`Custom_Field::processWidget()` validates uploads and registers them as
`new:<random>` markers. No file or metadata row is written yet.
`Person::_saveCustomValues()` runs inside the person save transaction, moves
staged files into the tree, inserts metadata rows, and deletes metadata for
removed values. Files for removed values are unlinked after commit.

A shutdown function verifies that each newly stored metadata row still exists;
if a surrounding transaction rolled back, the file is removed.

## Provenance and integrity

`custom_field_attachment` also stores:

- `created_by` — person ID of the uploader, `NULL` for records that predate this
  field or a deleted uploader (`ON DELETE SET NULL`).
- `sha1` — SHA-1 hex digest of the stored file.
- `created` — upload timestamp.

`Custom_Field_Attachment::checkIntegrity()` compares the stored SHA-1 with the
file on disk. Attachments with no stored hash get one recorded on first view.
`Custom_Field::printFormattedValue()` and `printAttachmentWidget()` replace the
link with an error if verification fails, and use the uploader/timestamp as the
link title. The download endpoint also returns HTTP 409 on mismatch.

## Deliberate exclusions

Attachment fields are hidden from CSV imports, note templates, bulk updates,
action plans, and system-wide search. Reports can display the filename and
filter on presence/absence.
