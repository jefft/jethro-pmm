<?php

/**
 * 2FA policy resolution shared by login and the self-service password reset
 * (User_System::resolve2FARequired()).
 *
 * The password reset requires an SMS code for exactly the accounts whose
 * permission set requires 2FA at login, so reset and login must decide
 * identically. These tests pin the pure policy decision: which permission
 * matches, that an unknown permission errs toward requiring 2FA, and that an
 * empty 2FA_REQUIRED_PERMS disables the policy.
 */

namespace Test\PasswordReset;

use function Test\{test, assert_eq, assert_true, assert_false};

require_once __DIR__ . '/../../include/general.php';
require_once __DIR__ . '/../../include/user_system.class.php';

test('empty 2FA_REQUIRED_PERMS disables the policy', function () {
	list($required, $matched, $unknown) = \User_System::resolve2FARequired('', 2147483647, Array(2 => 'SENDSMS'));
	assert_false($required);
	assert_eq($matched, null);
	assert_eq($unknown, null);
});

test('a held permission requires 2FA and is reported', function () {
	$levels = Array(2 => 'SENDSMS', 48 => 'VIEWNOTE', 2147483647 => 'SYSADMIN');
	list($required, $matched, $unknown) = \User_System::resolve2FARequired('48', 2147483647, $levels);
	assert_true($required);
	assert_eq($matched, 48);
	assert_eq($unknown, null);
});

test('a permission that includes the required bits requires 2FA', function () {
	// VIEWNOTE (48) includes VIEWMYNOTES (16): requiring 16 must match an account
	// holding 48, or widening the account's permissions would silently exempt it.
	$levels = Array(16 => 'VIEWMYNOTES', 48 => 'VIEWNOTE');
	list($required, $matched, $unknown) = \User_System::resolve2FARequired('16', 48, $levels);
	assert_true($required);
	assert_eq($matched, 16);
	assert_eq($unknown, null);
});

test('an unheld permission does not require 2FA', function () {
	$levels = Array(2 => 'SENDSMS', 48 => 'VIEWNOTE');
	list($required, $matched, $unknown) = \User_System::resolve2FARequired('48', 2, $levels);
	assert_false($required);
	assert_eq($matched, null);
	assert_eq($unknown, null);
});

test('the first matching permission in the list wins', function () {
	$levels = Array(2 => 'SENDSMS', 48 => 'VIEWNOTE');
	list($required, $matched, $unknown) = \User_System::resolve2FARequired('48,2', 2, $levels);
	assert_true($required);
	assert_eq($matched, 2);
	assert_eq($unknown, null);
});

test('an unknown permission errs toward requiring 2FA', function () {
	$levels = Array(2 => 'SENDSMS');
	list($required, $matched, $unknown) = \User_System::resolve2FARequired('12345', 2147483647, $levels);
	assert_true($required);
	assert_eq($unknown, '12345');
	assert_eq($matched, null);
});
