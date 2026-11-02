<?php
/**
 * Data-visibility permissions ("Viewable Person Fields").
 *
 * These live in a separate mask (staff_member.data_permissions) from the
 * function/action permissions in permission_levels.php, so a role can have a
 * function without broad data access, and data visibility can be revoked
 * without removing unrelated functionality.
 *
 * The person, family and custom_field_value views call the SQL function
 * currentUserHasDataPerm('VIEWEMAIL') etc.; its symbol-to-bit mapping mirrors
 * these constants (include/installer.class.php,
 * upgrades/2026-upgrade-to-2.40.sql).
 *
 * @see docs/docs/developer/reference/contact-field-permissions.md
 */
define('DATA_PERM_VIEWADDRESS', 134217728);
define('DATA_PERM_VIEWEMAIL', 536870912);
define('DATA_PERM_VIEWMOBILE', 268435456);
define('DATA_PERM_VIEWREMARKS', 33554432);
define('DATA_PERM_VIEWCUSTOMFIELDS', 67108864);
define('DATA_PERM_VIEWSENSITIVE', 16777216);

/**
 * Data permissions in display order.
 * @return array<int, array{0: string, 1: string, 2: string}> bit => (symbol, label, feature code)
 */
function get_data_permission_levels(): array
{
	return Array(
		DATA_PERM_VIEWADDRESS      => Array('VIEWADDRESS', 'Address', ''),
		DATA_PERM_VIEWEMAIL        => Array('VIEWEMAIL', 'Email', ''),
		DATA_PERM_VIEWMOBILE       => Array('VIEWMOBILE', 'Mobile', ''),
		DATA_PERM_VIEWREMARKS      => Array('VIEWREMARKS', 'Contact remarks', ''),
		DATA_PERM_VIEWCUSTOMFIELDS => Array('VIEWCUSTOMFIELDS', 'Custom fields', ''),
		DATA_PERM_VIEWSENSITIVE    => Array('VIEWSENSITIVE', 'Sensitive custom fields', ''),
	);
}
