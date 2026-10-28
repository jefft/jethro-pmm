<?php

/**
 * Unit tests for the message Person::_saveCustomValues() reports when a
 * staged attachment upload has disappeared before the save (the staging file
 * expired, or the form was re-posted after the cleanup).
 *
 * The save path is exercised through reflection with a fake DB and system
 * object: no database is used, and the "upload" is simply a staging token that
 * was never staged.
 *
 * @isolated-process   defines ATTACHMENTS_ROOT_PATH and fake $GLOBALS['db'] /
 *                     $GLOBALS['system'], which would leak into other files.
 */

if (!defined('JETHRO_ROOT')) define('JETHRO_ROOT', dirname(__DIR__, 2));
// The class declarations use these; the real values come from
// include/permission_levels.php via the user system.
if (!defined('PERM_SYSADMIN')) define('PERM_SYSADMIN', 2147483647);
if (!defined('PERM_EDITPERSON')) define('PERM_EDITPERSON', 1);
// Keep the staging-directory lookup out of the checkout.
define('ATTACHMENTS_ROOT_PATH', sys_get_temp_dir().'/jethro-unit-attachments-'.getmypid());

require_once __DIR__ . '/../../include/general.php';
require_once __DIR__ . '/../../include/db_object.class.php';
require_once __DIR__ . '/../../include/attachments_manager.class.php';
require_once __DIR__ . '/../../db_objects/custom_field.class.php';
require_once __DIR__ . '/../../db_objects/custom_field_attachment.class.php';
require_once __DIR__ . '/../../db_objects/person.class.php';

use function Test\assert_contains;
use function Test\assert_not_contains;
use function Test\test;

/**
 * Minimal stand-ins for the two globals _saveCustomValues() touches.
 */
final class FakeAttachmentTestDB
{
	public function query($sql) { return TRUE; }
	public function quote($value) { return $value === NULL ? 'NULL' : "'".addslashes((string)$value)."'"; }
}

final class FakeAttachmentTestSystem
{
	public function __construct(private array $customFields) {}
	public function getDBObjectData($type, $params = Array(), $logic = 'OR', $order = NULL)
	{
		return $this->customFields;
	}
}

const ATTACHMENT_TEST_FIELD_ID = 30;

/**
 * Run Person::_saveCustomValues() for one attachment field and return what
 * add_message() printed (the CLI branch echoes the message).
 *
 * @param array $values      posted values, e.g. Array('new:<32 hex>')
 * @param array $oldValues   values already stored, e.g. Array('7')
 * @return string
 */
function save_attachment_field(array $values, array $oldValues): string
{
	$GLOBALS['system'] = new FakeAttachmentTestSystem(Array(
		ATTACHMENT_TEST_FIELD_ID => Array(
			'name' => 'Docs',
			'type' => 'attachment',
			'allow_multiple' => TRUE,
		),
	));
	$GLOBALS['db'] = new FakeAttachmentTestDB();

	$person = (new ReflectionClass('Person'))->newInstanceWithoutConstructor();
	foreach (Array('_custom_values' => Array(ATTACHMENT_TEST_FIELD_ID => $values),
		'_old_custom_values' => Array(ATTACHMENT_TEST_FIELD_ID => $oldValues),
		'_attachment_rows_to_delete' => Array()) as $prop => $val) {
		$ref = new ReflectionProperty('Person', $prop);
		$ref->setValue($person, $val);
	}

	$method = new ReflectionMethod('Person', '_saveCustomValues');
	ob_start();
	$method->invoke($person);
	return (string)ob_get_clean();
}

test('an expired upload on a new person says the attachment was not saved', function () {
	$messages = save_attachment_field(Array('new:'.str_repeat('a', 32)), Array());

	// The defect: this claimed an existing attachment was kept when the person
	// had no attachment at all.
	assert_not_contains($messages, 'existing attachment was kept',
		'nothing was kept when there was no previous attachment');
	assert_contains($messages, 'Docs', 'the message names the field');
	assert_contains($messages, 'was not saved', 'the message says nothing was saved');
});

test('an expired replacement keeps the existing attachment and says so', function () {
	$messages = save_attachment_field(Array('new:'.str_repeat('b', 32)), Array('7'));

	assert_contains($messages, 'Docs', 'the message names the field');
	assert_contains($messages, 'existing attachment was kept',
		'the previous attachment is kept, and the message must say so');
});
