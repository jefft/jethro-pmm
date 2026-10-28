<?php
/**
 * The unconditional SMS gate on staff password resets.
 * @isolated-process
 *
 * Invoke the real token/completion/login handlers with an in-memory account
 * store. The store raises if a handler tries to change the password: refusal
 * paths must return without doing so. No SMS service or real account is involved.
 */
namespace Test\PasswordResetGate;

use function Test\{test, assert_eq, assert_true, assert_false};

require_once __DIR__.'/../../include/general.php';
require_once __DIR__.'/../../include/user_system.class.php';

require_once __DIR__.'/../../include/db_object.class.php';
require_once __DIR__.'/../../db_objects/staff_member.class.php';
// Resolve the real permission catalogue, as User_System does on normal requests.
set_include_path(__DIR__.'/../../include'.PATH_SEPARATOR.get_include_path());
define('2FA_REQUIRED_PERMS', '1');
define('2FA_EVEN_FOR_RESTRICTED_ACCTS', true);
define('SMS_PROVIDER', 'cellcast');
define('SMS_CELLCAST_APIKEY', 'test-mock-key');
define('2FA_SENDER_ID', 'StDemos');

final class PasswordWrite extends \RuntimeException {}

final class AccountStore
{
	public string $password = 'original-password-hash';
	public bool $armed = true;

	public function __construct(public array $row) {}

	public function quote($value): string
	{
		return "'".str_replace("'", "''", (string)$value)."'";
	}

	public function queryRow(string $sql): ?array
	{
		return $this->armed ? $this->row : null;
	}

	public function exec(string $sql): int
	{
		if (preg_match('/SET\s+password\s*=/i', $sql)) {
			$this->password = 'replaced-password-hash';
			throw new PasswordWrite('Reset tried to replace the password without the required SMS factor');
		}
		if (str_contains($sql, 'SET resethash = NULL')) {
			$this->armed = false;
		}
		return 1;
	}
}

function account(int $permissions, bool $sent = true): AccountStore
{
	return new AccountStore([
		'id' => 184,
		'username' => 'sms-gate-fixture',
		'first_name' => 'Synthetic',
		'permissions' => $permissions,
		'congregation_restrictions' => null,
		'group_restrictions' => null,
		'resethash' => password_hash('email-reset-token', PASSWORD_DEFAULT),
		'resetsmshash' => $sent ? password_hash('123456', PASSWORD_DEFAULT) : null,
		'resetsmsattempts' => 0,
		'resetsmssent' => $sent ? date('Y-m-d H:i:s') : null,
		'reset_valid' => 1,
		'reset_sms_valid' => (int)$sent,
		'reset_sms_cooldown' => (int)$sent,
	]);
}

function handler(AccountStore $db): \User_System
{
	$GLOBALS['db'] = $db;
	$_GET = $_POST = $_REQUEST = [];
	$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
	$_COOKIE = [];
	$_SESSION = [];
	// Skip the constructor's HTTP dispatch; getPermissionLevels() lazily loads
	// the same real catalogue when the policy handler asks for it.
	static $system;
	return $system ??= (new \ReflectionClass(\User_System::class))->newInstanceWithoutConstructor();
}

function complete(\User_System $system, AccountStore $db): void
{
	$_SESSION['password_reset'] = [
		'userid' => $db->row['id'],
		'username' => $db->row['username'],
		'resethash' => $db->row['resethash'],
		'first_name' => $db->row['first_name'],
	];
	$_REQUEST['new_user_pw1'] = 'Synthetic-reset-password-42!';
	(new \ReflectionMethod(\User_System::class, '_processResetCompletion'))->invoke($system);
}

test('completion requires the SMS challenge even when the account does not use 2FA at login', function () {
	$db = account(0);
	$system = handler($db);
	complete($system, $db);
	assert_eq($db->password, 'original-password-hash', 'Email-only proof must not replace the password');
	assert_true($db->armed, 'An unverified password post must not consume the request');
	assert_false(isset($_SESSION['2fa_verified']));
});

test('an unsent challenge cannot authorize completion', function () {
	$db = account(0, false);
	$system = handler($db);
	complete($system, $db);
	assert_eq($db->password, 'original-password-hash');
	assert_true($db->armed, 'The request stays armed for an explicit code send');
	assert_false(isset($_SESSION['2fa_verified']));
});

test('correct SMS supplied on password post does not bypass explicit verification', function () {
	$db = account(1);
	$system = handler($db);
	$_POST['reset_sms_code'] = '123456';
	$_REQUEST['reset_sms_code'] = '123456';
	complete($system, $db);
	assert_eq($db->password, 'original-password-hash');
	assert_true($db->armed);
	assert_false(isset($_SESSION['2fa_verified']));
});

test('verification credit must match the current unexpired challenge hash', function () {
	$db = account(1);
	$system = handler($db);
	$_SESSION['password_reset'] = ['sms_verified' => $db->row['resetsmshash']];
	$verified = new \ReflectionMethod(\User_System::class, '_resetSmsVerified');
	assert_true($verified->invoke($system, $db->row));

	$db->row['resetsmshash'] = password_hash('654321', PASSWORD_DEFAULT);
	assert_false($verified->invoke($system, $db->row), 'Resending revokes the old challenge credit');
	$_SESSION['password_reset']['sms_verified'] = $db->row['resetsmshash'];
	$db->row['reset_sms_valid'] = 0;
	assert_false($verified->invoke($system, $db->row), 'Expired code cannot authorize a password change');

	$db->row['reset_sms_valid'] = 1;
	$_SESSION['password_reset']['sms_verified'] = true;
	assert_false($verified->invoke($system, $db->row), 'A boolean session flag is not challenge-bound proof');
	$db->row['resetsmshash'] = null;
	$_SESSION['password_reset']['sms_verified'] = '';
	assert_false($verified->invoke($system, $db->row), 'An unsent challenge cannot be verified');
});

test('reset SMS verification satisfies exactly one password-authenticated login', function () {
	$db = account(1);
	$system = handler($db);
	$_SESSION['2fa_verified'] = ['userid' => 184, 'expires' => time() + 300];
	$requires = new \ReflectionMethod(\User_System::class, '_require2FA');
	assert_false($requires->invoke($system, $db->row), 'Recent verification satisfies this account');
	assert_true($requires->invoke($system, $db->row), 'A second login needs normal 2FA');
});

test('expired reset verification cannot satisfy login 2FA', function () {
	$db = account(1);
	$system = handler($db);
	$_SESSION['2fa_verified'] = ['userid' => 184, 'expires' => time() - 1];
	assert_true((new \ReflectionMethod(\User_System::class, '_require2FA'))->invoke($system, $db->row));
});

test('reset verification cannot satisfy another account or survive logout', function () {
	$db = account(1);
	$system = handler($db);
	$requires = new \ReflectionMethod(\User_System::class, '_require2FA');
	$_SESSION['2fa_verified'] = ['userid' => 185, 'expires' => time() + 300];
	assert_true($requires->invoke($system, $db->row), 'Account mismatch must require SMS');
	$other = $db->row;
	$other['id'] = 185;
	assert_true($requires->invoke($system, $other), 'A mismatched use also consumes the marker');
	$_SESSION['2fa_verified'] = ['userid' => 184, 'expires' => time() + 300];
	(new \ReflectionMethod(\User_System::class, '_logOut'))->invoke($system);
	assert_true($requires->invoke($system, $db->row), 'Explicit logout clears recent verification');
});
