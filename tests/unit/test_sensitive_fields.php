<?php

/**
 * Unit tests for sensitive custom-field visibility.
 * These do not touch the database.
 */

if (!defined('JETHRO_ROOT')) define('JETHRO_ROOT', dirname(__DIR__, 2));
require_once __DIR__ . '/../../include/general.php';
require_once __DIR__ . '/../../include/db_object.class.php';
require_once __DIR__ . '/../../db_objects/custom_field.class.php';

if (!defined('PERM_SYSADMIN')) define('PERM_SYSADMIN', 2147483647);

use function Test\assert_true;
use function Test\assert_false;
use function Test\test;

test('custom fields require the custom-fields view permission', function () {
    $previous = $GLOBALS['user_system'] ?? null;
    $GLOBALS['user_system'] = new class {
        public int $data = 0;
        public function getCurrentUser($field = '')
        {
            return Array('id' => 1, 'data_permissions' => $this->data);
        }
        public function haveDataPerm($permission)
        {
            return ($this->data & $permission) == $permission;
        }
    };

    assert_false(Custom_Field::isFieldDataVisibleToCurrentUser(Array('sensitive' => 0)));
    assert_false(Custom_Field::isFieldDataVisibleToCurrentUser(Array('sensitive' => 1)));

    $GLOBALS['user_system']->data = DATA_PERM_VIEWCUSTOMFIELDS;
    assert_true(Custom_Field::isFieldDataVisibleToCurrentUser(Array('sensitive' => 0)));
    assert_false(Custom_Field::isFieldDataVisibleToCurrentUser(Array('sensitive' => 1)));

    $GLOBALS['user_system']->data = DATA_PERM_VIEWCUSTOMFIELDS | DATA_PERM_VIEWSENSITIVE;
    assert_true(Custom_Field::isFieldDataVisibleToCurrentUser(Array('sensitive' => 1)));

    if ($previous === null) {
        unset($GLOBALS['user_system']);
    } else {
        $GLOBALS['user_system'] = $previous;
    }
});
