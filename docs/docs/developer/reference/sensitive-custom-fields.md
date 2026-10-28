# Sensitive custom fields

## Permissions

- `DATA_PERM_VIEWREMARKS` (`33554432`) — see the person contact remarks field.
- `DATA_PERM_VIEWCUSTOMFIELDS` (`67108864`) — see any custom field at all.
- `DATA_PERM_VIEWSENSITIVE` (`16777216`) — see custom fields marked sensitive;
  requires `DATA_PERM_VIEWCUSTOMFIELDS`.

`VIEWREMARKS` and `VIEWCUSTOMFIELDS` are granted by default and are added to
existing accounts by the upgrade. `VIEWSENSITIVE` is not granted by default.
`SYSADMIN` includes all three.

## Enforcement

`Custom_Field::isFieldDataVisibleToCurrentUser()` first requires
`DATA_PERM_VIEWCUSTOMFIELDS`, then applies `DATA_PERM_VIEWSENSITIVE` when the field is
marked sensitive. `Custom_Field::isVisibleToCurrentUser()` is the central check
used by summaries, forms, form processing, search, merge data, reports, CSV
exports, imports, bulk updates, action plans, note templates, merge help, and
the attachment download endpoint. `Person::setCustomValue()` also ignores writes
to hidden fields.

A note template that sets any custom field writes only "Custom field values are
recorded on the person record." into the note text. It never copies custom-field
names or values into notes, because note readers may not have permission to see
those fields. Authorized users can view the stored values on the person record.

`Person::currentUserCanViewRemarks()` gates the core `remarks` field. It is
enforced in person summaries, forms, processing, formatted values, search,
reports, merge data, merge help, and the mixed-search search-in options. Without
`DATA_PERM_VIEWCUSTOMFIELDS` and `DATA_PERM_VIEWREMARKS`, staff see only the person core
fields.

CLI scripts are treated as trusted. Filtering `Person::getCustomFields()`
globally would be unsafe because internal save and cleanup paths still need the
complete field list.
