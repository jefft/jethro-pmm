<?php

/**
 * Regression for pre-2.40 history whose custom-field label is no longer known.
 */

if (!defined('JETHRO_ROOT')) define('JETHRO_ROOT', dirname(__DIR__, 2));
require_once __DIR__ . '/../../include/field_access.php';

use function Test\assert_eq;
use function Test\test;

test('unknown legacy custom-field values are visible only to fully privileged viewers', function () {
    $previous = $GLOBALS['user_system'] ?? null;
    $GLOBALS['user_system'] = new class {
        public int $data = 0;
        public function haveDataPerm($permission)
        {
            return (($this->data & $permission) == $permission);
        }
    };

    $entry = 'Birthday changed from "private-old" to "private-new"';
    $permissionForKey = function ($key) { return Field_Access::permissionFor($key); };
    $permissionByLabel = Array();
    $hidden = 'A custom field changed';

    $GLOBALS['user_system']->data = 0;
    assert_eq(Field_Access::filterHistoryEntry($entry, $permissionForKey, $permissionByLabel), $hidden);

    $GLOBALS['user_system']->data = DATA_PERM_VIEWCUSTOMFIELDS;
    assert_eq(Field_Access::filterHistoryEntry($entry, $permissionForKey, $permissionByLabel), $hidden);

    $GLOBALS['user_system']->data = DATA_PERM_VIEWCUSTOMFIELDS | DATA_PERM_VIEWSENSITIVE;
    assert_eq(Field_Access::filterHistoryEntry($entry, $permissionForKey, $permissionByLabel), $entry);

    if ($previous === null) {
        unset($GLOBALS['user_system']);
    } else {
        $GLOBALS['user_system'] = $previous;
    }
});
