<?php

/**
 * Unit tests for the contact-field data permission guard.
 * These do not touch the database.
 */

if (!defined('JETHRO_ROOT')) define('JETHRO_ROOT', dirname(__DIR__, 2));
require_once __DIR__ . '/../../include/general.php';
require_once __DIR__ . '/../../include/field_access.php';

use function Test\assert_true;
use function Test\assert_false;
use function Test\assert_eq;
use function Test\assert_contains;
use function Test\test;

test('contact field visibility follows the data mask', function () {
    $previous = $GLOBALS['user_system'] ?? null;
    $GLOBALS['user_system'] = new class {
        public int $data = 0;
        public int $func = 0;
        public function haveDataPerm($permission)
        {
            return (($this->data & $permission) == $permission);
        }
        public function havePerm($permission)
        {
            return (($this->func & $permission) == $permission);
        }
    };

    assert_false(Field_Access::canRead('mobile_tel'));
    assert_false(Field_Access::canRead('email'));
    assert_false(Field_Access::canRead('address_street'));
    assert_false(Field_Access::canRead('remarks'));

    $GLOBALS['user_system']->data = DATA_PERM_VIEWMOBILE | DATA_PERM_VIEWEMAIL | DATA_PERM_VIEWREMARKS;
    assert_true(Field_Access::canRead('mobile_tel'));
    assert_true(Field_Access::canRead('email'));
    assert_false(Field_Access::canRead('address_street'));
    assert_true(Field_Access::canRead('remarks'));

    $fields = Array(
        'first_name' => 'Old',
        'mobile_tel' => 'Old mobile',
        'email' => 'Old email',
        'address_street' => 'Old address',
        'remarks' => 'Old remarks',
    );
    $values = Array(
        'first_name' => 'New',
        'mobile_tel' => 'New mobile',
        'email' => 'New email',
        'address_street' => 'Crafted address',
        'remarks' => 'New remarks',
    );
    Field_Access::filterWritableContactChanges($fields, $values);
    assert_true(isset($fields['mobile_tel']));
    assert_true(isset($fields['email']));
    assert_true(isset($fields['remarks']));
    assert_false(isset($fields['address_street']));
    assert_true(isset($fields['first_name']));

    // A rejected change is reverted, so later save steps never see it.
    assert_eq($values['address_street'], 'Old address');
    assert_eq($values['mobile_tel'], 'New mobile');

    // Create path: hidden contact values are removed before the INSERT.
    $values = Array('first_name' => 'New', 'email' => 'x@y', 'address_street' => '1 Secret Rd');
    Field_Access::filterWritableContactValues($values);
    assert_true(isset($values['first_name']));
    assert_true(isset($values['email']));
    assert_false(isset($values['address_street']));
    assert_eq(Field_Access::permissionFor('work_tel'), DATA_PERM_VIEWMOBILE);
    assert_eq(Field_Access::permissionFor('address_state'), DATA_PERM_VIEWADDRESS);
    assert_eq(Field_Access::permissionFor('first_name'), NULL);

    if ($previous === null) {
        unset($GLOBALS['user_system']);
    } else {
        $GLOBALS['user_system'] = $previous;
    }
});

test('the installer (no logged-in user) can read every contact field', function () {
    $previous = $GLOBALS['user_system'] ?? null;
    $GLOBALS['user_system'] = new class {
        public function getCurrentUser($field = '')
        {
            return NULL; // nobody has logged in yet
        }
        public function haveDataPerm($permission)
        {
            return FALSE;
        }
    };

    // The setup wizard must render the initial account's email input.
    assert_true(Field_Access::canRead('email'));
    assert_true(Field_Access::canRead('mobile_tel'));
    assert_true(Field_Access::canRead('address_street'));
    assert_true(Field_Access::canRead('remarks'));

    if ($previous === null) {
        unset($GLOBALS['user_system']);
    } else {
        $GLOBALS['user_system'] = $previous;
    }
});

test('history shows protected values only to users who may see the field', function () {
    $previous = $GLOBALS['user_system'] ?? null;
    $GLOBALS['user_system'] = new class {
        public int $data = 0;
        public function haveDataPerm($permission)
        {
            return (($this->data & $permission) == $permission);
        }
    };
    $sensitive = Array(7 => FALSE);
    $permForKey = function ($key) use (&$sensitive) {
        if (strpos($key, 'cf:') === 0) {
            $id = (int)substr($key, 3);
            if (!isset($sensitive[$id])) return NULL;
            return DATA_PERM_VIEWCUSTOMFIELDS | ($sensitive[$id] ? DATA_PERM_VIEWSENSITIVE : 0);
        }
        return Field_Access::permissionFor($key);
    };
    $byLabel = Array('Mobile Tel' => DATA_PERM_VIEWMOBILE, 'Contact Remarks' => DATA_PERM_VIEWREMARKS, 'Status' => 0);
    $entry = "Updated by A B (#1)\n"
        .Field_Access::tagHistoryLine('remarks', "Contact Remarks changed from \"line1\nsecret line2\" to \"x\\y\"")."\n"
        .Field_Access::tagHistoryLine('cf:7', 'Medical changed from "a" to "b"')."\n"
        .Field_Access::tagHistoryLine('cf:99', 'Deleted field changed from "a" to "b"')."\n"
        ."Mobile Tel changed from \"0491570156\n0491570157\" to \"\"\n"
        .'Status changed from "Core" to "Fringe"';

    // Multi-line values, protected legacy lines and unknown tagged fields are hidden.
    assert_eq(Field_Access::filterHistoryEntry($entry, $permForKey, $byLabel),
        "Updated by A B (#1)\nContact Remarks changed\nA custom field changed\nA custom field changed\nMobile Tel changed\nStatus changed from \"Core\" to \"Fringe\"");

    $GLOBALS['user_system']->data = DATA_PERM_VIEWREMARKS | DATA_PERM_VIEWCUSTOMFIELDS | DATA_PERM_VIEWMOBILE;
    assert_eq(Field_Access::filterHistoryEntry($entry, $permForKey, $byLabel),
        "Updated by A B (#1)\nContact Remarks changed from \"line1\nsecret line2\" to \"x\\y\"\nMedical changed from \"a\" to \"b\"\nA custom field changed\n"
        ."Mobile Tel changed from \"0491570156\n0491570157\" to \"\"\nStatus changed from \"Core\" to \"Fringe\"");

    // Marking a field sensitive later also hides its existing history.
    $sensitive[7] = TRUE;
    assert_contains(Field_Access::filterHistoryEntry($entry, $permForKey, $byLabel, Array('Medical' => TRUE)), "\nA custom field changed\n");

    if ($previous === null) {
        unset($GLOBALS['user_system']);
    } else {
        $GLOBALS['user_system'] = $previous;
    }
});
