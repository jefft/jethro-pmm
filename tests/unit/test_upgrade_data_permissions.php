<?php

/**
 * Upgraded and fresh-install databases must end up with the same definitions
 * for the 2.40 data-permission objects (jr-trh.14):
 *
 *  1. staff_member.data_permissions has the installer's default (1040187392)
 *     and ordinal position, including on databases that already ran the
 *     original 2.40 migration;
 *  2. the _custom_field_value foreign keys carry the installer's names;
 *  3. DEFAULT_DATA_PERMISSIONS and DATA_PERMISSIONS_MIGRATED end at the exact
 *     ranks the installer's @rank+5 sequence gives them, not at 1001 or a
 *     locally derived value;
 *  4. the latin1/UTF-8 detector ignores views (the masked family view is not a
 *     duplicate latin1 table);
 *  5. the utf8mb4 upgrader verifies every view, not a hard-coded four.
 *
 * These are static/behavioural checks: the database-level reproduction lives in
 * the bug's comments, and the DDL/setting invariants are compared here so a
 * future edit cannot reintroduce the drift silently.
 */

namespace Test\UpgradeDataPermissions;

use function Test\{test, assert_eq, assert_true, assert_contains, assert_not_contains};

if (!defined('JETHRO_ROOT')) define('JETHRO_ROOT', dirname(__DIR__, 2));
require_once __DIR__ . '/../../include/general.php';
require_once __DIR__ . '/../../include/db_object.class.php';
if (!defined('PERM_EDITPERSON')) define('PERM_EDITPERSON', 1073741824);
if (!defined('PERM_SYSADMIN')) define('PERM_SYSADMIN', 2147483647);
require_once __DIR__ . '/../../db_objects/staff_member.class.php';
require_once __DIR__ . '/../../db_objects/custom_field_value.class.php';
require_once __DIR__ . '/../../include/data_permissions.php';
require_once __DIR__ . '/../../upgrades/upgradefixes/2.40.0_fix_db_charset/dbcharsetutils.php';

const UPGRADE_2_40_SQL = __DIR__ . '/../../upgrades/2026-upgrade-to-2.40.sql';
const INSTALLER_CLASS = __DIR__ . '/../../include/installer.class.php';
const UTF8MB4_UPGRADER = __DIR__ . '/../../upgrades/2026-upgrade-to-2.40-utf8mb4.php';

/** Installer DDL for staff_member, with the globals _getFields() reads stubbed. */
function staffMemberInstallSql(): string
{
	$previousUserSystem = $GLOBALS['user_system'] ?? NULL;
	$previousDb = $GLOBALS['db'] ?? NULL;
	$GLOBALS['user_system'] = new class {
		public function getDataPermissionLevels() { return Array(); }
		public function getPermissionLevels() { return Array(); }
		public function getCurrentUser($field = '') { return NULL; }
	};
	// _getInitSQL() quotes string defaults through the connection.
	$GLOBALS['db'] = new class {
		public function quote($value) { return "'" . $value . "'"; }
	};
	try {
		$obj = (new \ReflectionClass('Staff_Member'))->newInstanceWithoutConstructor();
		$sql = $obj->getInitSQL();
		return is_array($sql) ? implode("\n", $sql) : $sql;
	} finally {
		if ($previousUserSystem === NULL) {
			unset($GLOBALS['user_system']);
		} else {
			$GLOBALS['user_system'] = $previousUserSystem;
		}
		if ($previousDb === NULL) {
			unset($GLOBALS['db']);
		} else {
			$GLOBALS['db'] = $previousDb;
		}
	}
}

/** Every line of the upgrade SQL that adds the data_permissions column. */
function dataPermissionsAddLines(string $sql): array
{
	preg_match_all('/^.*ADD\s+COLUMN\s+`data_permissions`.*$/mi', $sql, $m);
	return $m[0];
}

test('installer creates staff_member.data_permissions with the shared default', function () {
	$sql = staffMemberInstallSql();
	$sql = preg_replace('/\s+/', ' ', $sql);
	assert_contains($sql, '`data_permissions` varchar(255) NOT NULL', 'data_permissions column is missing from the installer DDL');
	assert_contains($sql, "DEFAULT '1040187392'", 'the installer default drifted from DEFAULT_DATA_PERMISSIONS');
});

test('the 2.40 upgrade adds data_permissions with the installer default', function () {
	$lines = dataPermissionsAddLines(file_get_contents(UPGRADE_2_40_SQL));
	assert_eq(count($lines), 1, 'expected exactly one ADD COLUMN for data_permissions');
	assert_contains($lines[0], '1040187392', 'the ADD COLUMN default drifted from the fresh-install default');
});

test('the 2.40 upgrade converges databases that already have data_permissions', function () {
	$sql = preg_replace('/\s+/', ' ', file_get_contents(UPGRADE_2_40_SQL));
	// The guarded ADD COLUMN does not re-run, so an idempotent MODIFY must
	// rewrite the old '0' default on sites that already ran the migration.
	assert_contains(
		$sql,
		"MODIFY COLUMN `data_permissions` varchar(255) NOT NULL DEFAULT '1040187392' AFTER `active`",
		'the upgrade never fixes data_permissions on databases that already ran the old migration'
	);
	// ADD COLUMN appends; the installer puts it between active and permissions.
	// (The statement is a prepared-statement string, so quotes are doubled.)
	assert_contains(
		$sql,
		"ADD COLUMN `data_permissions` varchar(255) NOT NULL DEFAULT ''1040187392'' AFTER `active`",
		'the upgrade adds data_permissions in a different position from the installer'
	);
});

test('the upgrade derives both setting ranks from the installer sequence', function () {
	$sql = preg_replace('/\s+/', ' ', file_get_contents(UPGRADE_2_40_SQL));
	// The installer inserts DEFAULT_PERMISSIONS, DEFAULT_DATA_PERMISSIONS and
	// DATA_PERMISSIONS_MIGRATED in one @rank+5 sequence, so an upgraded database
	// must end with DEFAULT_PERMISSIONS +5 and +10, not with local +1/1001 values.
	assert_contains(
		$sql,
		"@default_data_permissions_rank = COALESCE( (SELECT `rank` FROM setting WHERE symbol = 'DEFAULT_PERMISSIONS' LIMIT 1), 16) + 5",
		'DEFAULT_DATA_PERMISSIONS is not at the installer rank (DEFAULT_PERMISSIONS + 5)'
	);
	assert_contains(
		$sql,
		"VALUES (@default_data_permissions_rank, '', 'DEFAULT_DATA_PERMISSIONS'",
		'the DEFAULT_DATA_PERMISSIONS insert does not use the installer rank'
	);
	assert_contains(
		$sql,
		"UPDATE setting SET `rank` = @default_data_permissions_rank WHERE symbol = 'DEFAULT_DATA_PERMISSIONS' AND `rank` <> @default_data_permissions_rank",
		'already-upgraded databases do not converge DEFAULT_DATA_PERMISSIONS'
	);
	assert_contains(
		$sql,
		'@data_permissions_migrated_rank = @default_data_permissions_rank + 5',
		'DATA_PERMISSIONS_MIGRATED is not at the installer rank (DEFAULT_DATA_PERMISSIONS + 5)'
	);
	assert_contains(
		$sql,
		"UPDATE setting SET `rank` = @data_permissions_migrated_rank WHERE symbol = 'DATA_PERMISSIONS_MIGRATED' AND `rank` <> @data_permissions_migrated_rank",
		'already-upgraded databases do not converge DATA_PERMISSIONS_MIGRATED'
	);
	assert_not_contains($sql, 'VALUES (1001,', 'the upgrade still hard-codes the marker rank 1001');
});

test('the upgrade renames _custom_field_value foreign keys to the installer names', function () {
	$sql = file_get_contents(UPGRADE_2_40_SQL);

	$cfv = (new \ReflectionClass('Custom_Field_Value'))->newInstanceWithoutConstructor();
	$expected = Array();
	foreach ($cfv->getForeignKeys() as $from => $reference) {
		[$table, $column] = explode('.', $from);
		$expected[$column] = $table . $column;
	}
	assert_eq(array_keys($expected), ['personid', 'fieldid', 'value_optionid']);

	foreach ($expected as $column => $name) {
		// RENAME TABLE keeps the pre-rename constraint and index names.
		assert_contains($sql, 'DROP FOREIGN KEY `custom_field_value' . $column . '`', "the old $column FK is not dropped");
		assert_contains($sql, 'ADD CONSTRAINT `' . $name . '`', "the $name FK is not recreated");
	}
});

test('the latin1 detector samples base tables only, never views', function () {
	$db = new class {
		public array $queries = Array();
		public function queryAll($sql) { $this->queries[] = $sql; return Array(); }
	};
	$previous = $GLOBALS['db'] ?? NULL;
	$GLOBALS['db'] = $db;
	try {
		$result = \DB_Charset_Utils::detectLatin1UTF8Bytes();
	} finally {
		if ($previous === NULL) {
			unset($GLOBALS['db']);
		} else {
			$GLOBALS['db'] = $previous;
		}
	}
	assert_eq($result['risky_columns'], Array());
	assert_eq(count($db->queries), 1, 'expected one metadata query');
	$sql = preg_replace('/\s+/', ' ', $db->queries[0]);
	assert_contains($sql, 'information_schema.TABLES');
	assert_contains($sql, "TABLE_TYPE = 'BASE TABLE'", 'view columns are still sampled like tables');
});

test('the utf8mb4 upgrader verifies every view in the database', function () {
	assert_true(method_exists('DB_Charset_Utils', 'checkViews'), 'DB_Charset_Utils::checkViews() is missing');

	$db = new class {
		public array $queried = Array();
		public function queryAll($sql)
		{
			$this->queried[] = $sql;
			return Array(
				Array('v' => 'person'),
				Array('v' => 'family'),
				Array('v' => 'custom_field_value'),
			);
		}
		public function query($sql)
		{
			$this->queried[] = $sql;
			if (str_contains($sql, '`family`')) {
				throw new \PDOException('View family is broken');
			}
			return NULL;
		}
	};
	$broken = \DB_Charset_Utils::checkViews($db);

	$viewQuery = preg_replace('/\s+/', ' ', $db->queried[0]);
	assert_contains($viewQuery, 'information_schema.VIEWS', 'the upgrader does not enumerate the database views');
	assert_eq(array_keys($broken), Array('family'), 'a broken view was not reported');
	assert_contains($broken['family'], 'broken');

	$script = file_get_contents(UTF8MB4_UPGRADER);
	assert_contains($script, 'checkViews(', 'the upgrader does not call the shared view check');
	assert_not_contains($script, "Array('person', 'person_group', 'member', 'abstract_note')", 'the upgrader still checks only the hard-coded four views');
});

/**
 * Extract the symbol => bit pairs from currentUserHasDataPerm()'s CASE mapping.
 * @return array<string,int>
 */
function dataPermissionFunctionMapping(string $sql): array
{
	preg_match_all(
		'/WHEN\s+\'([A-Z]+)\'\s+THEN\s+\(\(@current_user_data_permissions\s*&\s*(\d+)\)\s*<>\s*0\)/',
		$sql,
		$m,
		PREG_SET_ORDER
	);
	$res = Array();
	foreach ($m as $row) {
		$res[$row[1]] = (int)$row[2];
	}
	return $res;
}

test('the SQL permission function maps symbols to the PHP bit values', function () {
	$expected = Array();
	foreach (\get_data_permission_levels() as $bit => $details) {
		$expected[$details[0]] = $bit;
	}
	assert_eq(count($expected), 6, 'unexpected number of data permissions');
	foreach (Array(UPGRADE_2_40_SQL, INSTALLER_CLASS) as $path) {
		$actual = dataPermissionFunctionMapping(file_get_contents($path));
		assert_eq(
			$actual,
			$expected,
			basename($path).' currentUserHasDataPerm() mapping drifted from include/data_permissions.php'
		);
	}
});

test('views pass permission symbols, not numeric bits, to currentUserHasDataPerm', function () {
	$expectedSymbols = Array();
	foreach (\get_data_permission_levels() as $details) {
		$expectedSymbols[] = $details[0];
	}
	$files = Array(
		UPGRADE_2_40_SQL,
		INSTALLER_CLASS,
		__DIR__ . '/../../db_objects/person.class.php',
		__DIR__ . '/../../db_objects/custom_field_value.class.php',
	);
	foreach ($files as $path) {
		// custom_field_value.class.php embeds the SQL in a single-quoted PHP
		// string, so its symbol quotes appear as \' in the source.
		$sql = str_replace("\\'", "'", file_get_contents($path));
		preg_match_all('/currentUserHasDataPerm\(\s*(\d+)\s*\)/', $sql, $numeric);
		assert_eq(
			$numeric[1],
			Array(),
			basename($path).' still passes a numeric bit to currentUserHasDataPerm()'
		);
		preg_match_all('/currentUserHasDataPerm\(\'([A-Z]+)\'\)/', $sql, $symbolic);
		foreach ($symbolic[1] as $symbol) {
			assert_true(
				in_array($symbol, $expectedSymbols, TRUE),
				basename($path).' uses unknown permission symbol '.$symbol
			);
		}
	}
	$personSql = file_get_contents(__DIR__ . '/../../db_objects/person.class.php');
	assert_contains($personSql, "currentUserHasDataPerm('VIEWEMAIL') THEN p.`email`");
	assert_contains($personSql, "currentUserHasDataPerm('VIEWMOBILE') THEN p.`mobile_tel`");
	assert_contains($personSql, "currentUserHasDataPerm('VIEWMOBILE') THEN p.`work_tel`");
	assert_contains($personSql, "currentUserHasDataPerm('VIEWREMARKS') THEN p.`remarks`");
});
