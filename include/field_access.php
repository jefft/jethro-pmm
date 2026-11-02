<?php
require_once __DIR__.'/data_permissions.php';

/**
 * Central field-level read/write policy for contact data.
 *
 * Read filtering is done by MySQL views. This class is the write/UI guard:
 * a field must be visible through the data mask before it is writable.
 *
 * @see docs/docs/developer/reference/contact-field-permissions.md
 */
class Field_Access
{
	/** Prefix of a history line about a field hidden by data permissions; the field key follows. */
	const HISTORY_TAG_PREFIX = '[hidden:';

	/**
	 * The data permission protecting a contact field.
	 * @param string $field
	 * @return int|null NULL if the field is not a protected contact field
	 */
	public static function permissionFor($field)
	{
		switch ($field) {
			case 'mobile_tel':
			case 'work_tel':
				return DATA_PERM_VIEWMOBILE;
			case 'email':
				return DATA_PERM_VIEWEMAIL;
			case 'address_street':
			case 'address_suburb':
			case 'address_state':
			case 'address_postcode':
			case 'home_tel':
				return DATA_PERM_VIEWADDRESS;
			case 'remarks':
				return DATA_PERM_VIEWREMARKS;
			default:
				return NULL;
		}
	}

	/**
	 * Whether the current session holds every bit in $permission.
	 * Sessions without a user system (installer, bare CLI) see everything.
	 * @param int $permission
	 * @return bool
	 */
	public static function haveDataPerm($permission)
	{
		if (empty($GLOBALS['user_system'])) return TRUE;
		if (!empty($GLOBALS['JETHRO_INSTALLING'])) return TRUE;
		if (!method_exists($GLOBALS['user_system'], 'haveDataPerm')) return TRUE;
		// User_System::haveDataPerm() only accepts a single known bit.
		foreach (array_keys(get_data_permission_levels()) as $bit) {
			if (((int)$permission & $bit) && !$GLOBALS['user_system']->haveDataPerm($bit)) return FALSE;
		}
		return TRUE;
	}

	/**
	 * Tag a history line about a protected field. The line is stored on one
	 * physical line (newlines in values are escaped), so a multi-line value
	 * cannot spill past the tag. The permission is looked up from $key when
	 * the history is displayed, so later changes (e.g. marking a field
	 * sensitive) also apply to existing history.
	 * @param string $key Field key: a contact field name, or "cf:<id>" for a custom field
	 * @param string $line "<label> changed from ... to ..."
	 * @return string
	 */
	public static function tagHistoryLine($key, $line)
	{
		return self::HISTORY_TAG_PREFIX.$key.']'.str_replace(Array('\\', "\n", "\r"), Array('\\\\', '\\n', ''), $line);
	}

	/**
	 * Prepare a stored history entry for display. A line about a field the
	 * viewer may not see is reduced to "<label> changed". This covers lines
	 * tagged by tagHistoryLine(), and untagged lines written before tagging
	 * existed, which are recognised by their "<label> changed from" prefix
	 * (any following lines of a multi-line value are dropped with them).
	 * @param string $entry One history entry (lines separated by "\n")
	 * @param callable $permissionForKey fn(string $key): ?int; NULL = unknown field, hidden from everyone but SysAdmins
	 * @param array<string,int> $permissionByLabel Field label => permission, for untagged lines
	 * @param array<string,bool> $customFieldLabels Labels of custom fields, hidden when the line is hidden
	 * @return string
	 */
	public static function filterHistoryEntry($entry, callable $permissionForKey, array $permissionByLabel, array $customFieldLabels = Array())
	{
		$out = Array();
		$hidingContinuation = FALSE;
		foreach (explode("\n", (string)$entry) as $line) {
			if (preg_match('/^'.preg_quote(self::HISTORY_TAG_PREFIX, '/').'([^\]]+)\](.*)$/', $line, $m)) {
				$hidingContinuation = FALSE;
				$text = preg_replace_callback('/\\\\(\\\\|n)/', function ($e) { return $e[1] === 'n' ? "\n" : '\\'; }, $m[2]);
				$perm = $permissionForKey($m[1]);
				$isCustomField = (strpos($m[1], 'cf:') === 0);
				if ($perm === NULL) {
					$perm = DATA_PERM_VIEWCUSTOMFIELDS | DATA_PERM_VIEWSENSITIVE;
					$isCustomField = TRUE;
				}
				if (self::haveDataPerm($perm)) {
					$out[] = $text;
				} else {
					$out[] = ($isCustomField ? 'A custom field' : self::_historyLabel($text)).' changed';
				}
			} else if (preg_match('/^(.+?) changed from "/', $line, $m)) {
				$perm = $permissionByLabel[$m[1]] ?? NULL;
				// A pre-2.40 label that is no longer known may be a renamed or
				// deleted custom field. Show its value only to SysAdmins.
				$isUnknown = ($perm === NULL);
				if ($isUnknown) $perm = DATA_PERM_VIEWCUSTOMFIELDS | DATA_PERM_VIEWSENSITIVE;
				$hidingContinuation = !self::haveDataPerm($perm);
				$out[] = $hidingContinuation
					? (($isUnknown || isset($customFieldLabels[$m[1]])) ? 'A custom field' : $m[1]).' changed'
					: $line;
			} else if (!$hidingContinuation) {
				$out[] = $line;
			}
		}
		return implode("\n", $out);
	}

	/**
	 * @param string $text "<label> changed from ..."
	 * @return string
	 */
	private static function _historyLabel($text)
	{
		$pos = strpos($text, ' changed');
		return ($pos === FALSE) ? 'A hidden field' : substr($text, 0, $pos);
	}

	public static function isContactField($field)
	{
		return self::permissionFor($field) !== NULL;
	}

	public static function canRead($field)
	{
		$perm = self::permissionFor($field);
		if ($perm === NULL) return TRUE;
		return self::haveDataPerm($perm);
	}

	public static function canWrite($field)
	{
		// Write requires the action permission AND data visibility. The action
		// permission is enforced by the object's normal save permission; here
		// we enforce the data half.
		return self::canRead($field);
	}

	/**
	 * Drop changes to contact fields the current user may not write, before
	 * DB_Object builds its UPDATE. The field's value is put back to its old
	 * value so nothing later in the save sees the rejected input. Writable
	 * changes stay, so the base column is updated and history records them.
	 * @param array<string,mixed> $old_values DB_Object::$_old_values
	 * @param array<string,mixed> $values DB_Object::$values
	 * @return void
	 */
	public static function filterWritableContactChanges(&$old_values, &$values)
	{
		foreach (array_keys($old_values) as $field) {
			if (self::isContactField($field) && !self::canWrite($field)) {
				$values[$field] = $old_values[$field];
				unset($old_values[$field]);
			}
		}
	}

	/**
	 * Remove non-writable contact values from a create request.
	 * @param array<string,mixed> $values
	 * @return void
	 */
	public static function filterWritableContactValues(&$values)
	{
		foreach (array_keys($values) as $field) {
			if (self::isContactField($field) && !self::canWrite($field)) {
				unset($values[$field]);
			}
		}
	}
}
