<?php
require_once dirname(__FILE__).'/general.php';
require_once dirname(__FILE__).'/abstract_user_system.class.php';

/**
 * This class is the user system for fully-logged-in users (eg staff members).
 * See members/include/member_user_system for church-member login handling.
 */
class User_System extends Abstract_User_System
{
	private $_error;
	private $_is_public = FALSE;
	private $_is_cli_script = FALSE;
	/** Null, or why 2FA cannot send a code for the pending login (see _require2FA()). */
	private ?string $_2faBlockedReason = null;

	public function __construct()
	{
		$this->_loadPermissionLevels();

		if (empty($_SESSION['user']) && ifdef('PASSWORD_RESET_ENABLED', TRUE)) {
			// Self-service "forgot password" flow (see docs/docs/developer/reference/password-reset.mdx)
			$this->_processPasswordReset();
		}

		if (!empty($_REQUEST['logout'])) {
			$this->_logOut();
		} else if (empty($_SESSION['user']) && !empty($_POST['username'])) {
			// process the login form
			if (!request_origin_is_proven_current()) {
				// Not a browser POST from a Jethro page (typically a scanner): refuse
				// before the password check. See docs/docs/developer/reference/request-origin-protection.mdx
				log_unproven_login_origin('staff');
				$this->_error = 'Your browser did not confirm this login came from this site. Please try again.';
				return;
			}
			$user_details = $this->_findUser($_POST['username'], $_POST['password']);
			if (empty($user_details)) {
				unset($_SESSION['2fa_verified']);
				// No user found matching those credentials.
				$this->_error = 'Incorrect username or password';
				// Waste scammers' time with a randomised delay to slow down brute force attempts
				$_SESSION['bad_password_attempts'] = array_get($_SESSION, 'bad_password_attempts', 0) + 1;
				$sleeptime = rand(1, min(18, $_SESSION['bad_password_attempts'] * 2))/2;
				sleep($sleeptime);
			} else {
				// Found a user matching username and password. But check some things before making them the current user.
				$go_ahead = TRUE;
				if ($errs = self::getPasswordStrengthErrors($_POST['password'])) {
					// Found a user, but thier  password does not meet the current strength requirements.
					// Record their details but don't log them in.
					$_SESSION['user_requiring_password_upgrade'] = $user_details;
					$_SESSION['password_upgrade_key'] = generate_random_string(32);
					$_SESSION['password_upgrade_expiry'] = time() + (60*5); // 5 mins to change password
					$go_ahead = FALSE;
				}
				if ($this->_require2FA($user_details)) {
					$this->_init2FA($user_details);
					$go_ahead = FALSE;
				}
				if ($go_ahead) {
					// Found a user, nothing further required, so do the business to make them the logged-in user.
					$this->_logUserIn($user_details);
				} else {
					// The 2FA or password-upgrade form comes next (or an error message, from
					// add_message()). Redirect so the browser's history holds a GET, not a
					// POST that would re-send the password and another SMS code on reload.
					session_write_close();
					header('Location: '.build_url(Array()), true, 303);
					exit;
				}
			}
		} else if (!empty($_SESSION['2fa']['pending_user']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
			// We are processing a 2FA code. GETs (including the redirect after the
			// login POST) fall through to printLogin(), which shows the 2FA form.
			if (!empty($_POST)) {
				if ($_SESSION['2fa']['expiry'] < time()) {
					add_message('SMS-code form has expired. Please try again.', 'warning');
					$this->_reset2FA();
					return;
				} else if (!hash_equals((string) $_SESSION['2fa']['key'], (string) array_get($_POST, '2fa_key'))) {
					add_message("2-factor authentication was interrupted. Please try again.", 'warning');
					$this->_reset2FA();
					return;
				}
				if (!empty($_POST['2fa_code'])) {
					// Constant-time comparison of the strings, not ==.
					if (hash_equals((string) $_SESSION['2fa']['code'], trim((string) $_POST['2fa_code']))) {
						$this->_2faLog($_SESSION['2fa']['pending_user']['username']." entered the correct code and will be logged in.");
						$this->_process2FATrust();
						if (empty($_SESSION['user_requiring_password_upgrade'])) {
							$this->_logUserIn($_SESSION['2fa']['pending_user']);
						}
						$this->_reset2FA();
					} else {
						$this->_error = 'Incorrect 2-factor code. Please try again';
						$this->_2faLog("Received incorrect code for ".$_SESSION['2fa']['pending_user']['username']);
						$_SESSION['2fa']['wrong_code_count']++;
						if ($_SESSION['2fa']['wrong_code_count'] > 1) $this->_reset2FA(); // start again from scratch after 2 wrong codes.
						return;
					}
				}
			}

		} else if (!empty($_SESSION['user_requiring_password_upgrade']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
			// We are processing a password upgrade. GETs fall through to
			// printLogin(), which shows the password-upgrade form.
			if ($_SESSION['password_upgrade_expiry'] < time()) {
				unset($_SESSION['user_requiring_password_upgrade']);
				return;
			} else if (array_get($_POST, 'password_upgrade_key') != $_SESSION['password_upgrade_key']) {
				unset($_SESSION['user_requiring_password_upgrade']);
				return;
			}
			if (!empty($_POST['new_user_pw1'])) {
				$hashed = Staff_Member::processPasswordField('new_');
				if ($hashed) {
					$this->_setUserPassword($_SESSION['user_requiring_password_upgrade']['id'], $hashed);
					$this->_logUserIn($_SESSION['user_requiring_password_upgrade']);
					add_message('Password updated');
					unset($_SESSION['user_requiring_password_upgrade']);
				}
				// else we fall through to show the password-upgrade form again.
			}
		}

		if (!empty($_SESSION['user'])) {
			if (defined('SESSION_TIMEOUT_MINS') && constant('SESSION_TIMEOUT_MINS')) {
				if ((time() - $_SESSION['last_activity_time']) / 60 > SESSION_TIMEOUT_MINS) {
					// They have been idle too long
					$this->_logOut();
				}
			}
			if (defined('SESSION_MAXLENGTH_MINS') && constant('SESSION_MAXLENGTH_MINS')) {
				if ((time() - $_SESSION['login_time']) / 60 > SESSION_MAXLENGTH_MINS) {
					// They have been logged in too long
					$this->_logOut();
				}

			}
			$_SESSION['last_activity_time'] = time();

			if (!empty($_SESSION['user'])) {
				$GLOBALS['db']->setCurrentUserID((int)$_SESSION['user']['id']);
			}
		}

	}//end constructor

	private function _logUserIn($user_details)
	{
		// Recreate session when logging in
		session_regenerate_id();
		$_SESSION = Array();
		$_SESSION['user'] = $user_details;
		$_SESSION['login_time'] = time();
		$_SESSION['last_activity_time'] = time();
		include_once 'include/size_detector.class.php';
		SizeDetector::processRequest();
		session_write_close();
		header('Location: '.build_url(Array()), true, 303); // the login form was POSTed; 303 See Other makes the subsequent page load a clean GET request.
		exit;
	}


	public function setError($s)
	{
		$this->_error = $s;
	}

	private function _logOut() {
		if (!empty($_SESSION['user'])) {
			DB_Object::releaseAllLocks($_SESSION['user']['id']);
		}
		unset($_SESSION['2fa_verified'], $_SESSION['password_reset']);
		$_SESSION['user'] = NULL;
		$_SESSION['login_time'] = NULL;
		$_SESSION['last_activity_time'] = NULL;
	}

	private function _setUserPassword($userid, $hashed) {
		$db = $GLOBALS['db'];
		// Also voids any outstanding self-service reset request (see _processResetCompletion()).
		$SQL = 'UPDATE staff_member
				SET password = '.$db->quote($hashed).',
					resethash = NULL, resetexpires = NULL, resetsmshash = NULL,
					resetsmssent = NULL, resetsmsattempts = 0
				WHERE id = '.(int)$userid;
		return $db->exec($SQL);
	}

	/**
	 * Get details of the currently-authorised user account
	 * @see Abstract_User_System::getCurrentPerson()
	 * @param string $field	Particular field to return; null=return all fields
	 * @return mixed
	 */

	public function getCurrentPerson($field='')
	{
		return $this->getCurrentUser($field);
	}

	/**
	 * Get details of the currently-authorised *user account* (staff member)
	 * @param string $field	Particular field to return; null=return all fields
	 * @return mixed
	 */
	public function getCurrentUser($field='')
	{
		if (empty($_SESSION['user']) || $this->_is_public) {
			return NULL;
		} else {
			if (empty($field)) {
				return $_SESSION['user'];
			} else {
				return array_get($_SESSION['user'], $field, '');
			}
		}

	}//end getCurrentUser()

	public function getCurrentRestrictions($type=NULL)
	{
		$res = Array();
		if (!empty($_SESSION['user']['group_restrictions'])) $res['group'] = $_SESSION['user']['group_restrictions'];
		if (!empty($_SESSION['user']['congregation_restrictions'])) $res['congregation'] = $_SESSION['user']['congregation_restrictions'];
		if ($type) $res = array_get($res, $type);
		return $res;
	}

	public function havePerm($permission)
	{
		if (!is_int($permission)) throw new \RuntimeException("Non-numeric permission level is invalid");
		if ($permission == 0) return true;
		if (!empty($GLOBALS['JETHRO_INSTALLING'])) return true;
		if (!array_key_exists($permission, $this->_permission_levels)) return false; // disabled feature
		// Beware: the & operator is less sticky than the == operator. So the parentheses around the & operation are very important!
		return (($this->getCurrentUser('permissions') & $permission) == $permission);
	}

	public function checkPerm($permission)
	{
		if (!$this->havePerm($permission)) {
			trigger_error('Your user account does not have permission to perform this action');
			exit;
		}
	}
	
	// Called by the public interface to indicate no login expected
	public function setPublic()
	{
        $GLOBALS['db']->setCurrentUserID(-1);
		$this->_is_public = TRUE;
	}
	
	/**
	 * Similar to setPublic - there is no current user. Called by scripts.
	 */
	public function setCLIScript()
	{
        $GLOBALS['db']->setCurrentUserID(-1);
		$this->_is_cli_script = TRUE;
	}
	
	public function isCLIScript()
	{
		return $this->_is_cli_script;
	}

	public function printLogin()
	{
		if (!$this->hasUsers()) {
			throw new \RuntimeException("This system has no user accounts - it has not been installed properly");
			exit;
		}

		if (ifdef('PASSWORD_RESET_ENABLED', TRUE)) {
			if (!empty($_SESSION['password_reset']) && ($reset_user = $this->_loadPasswordResetUser()) !== null) {
				$reset_state = $_SESSION['password_reset'];
				$reset_sms_verified = $this->_resetSmsVerified($reset_user);
				require TEMPLATE_DIR.'/reset_password_form.template.php';
				exit;
			}
			if (!empty($_REQUEST['forgot_password'])) {
				require TEMPLATE_DIR.'/forgot_password_form.template.php';
				exit;
			}
			if (!empty($_REQUEST['forgot_username'])) {
				require TEMPLATE_DIR.'/forgot_username_form.template.php';
				exit;
			}
		}

		if (!empty($_SESSION['2fa']['pending_user'])) {
			// Print the 2fa form
			if ($_SESSION['2fa']['expiry'] < time()) {
				// took too long. Forget about them, and fall through to the login form again.
				unset($_SESSION['2fa']['pending_user']);
			} else {
				$key = $_SESSION['2fa']['key'];
				$person_name = $_SESSION['2fa']['pending_user']['first_name'];
				require TEMPLATE_DIR.'/2fa_form.template.php';
				exit;
			}
		}


		if (!empty($_SESSION['user_requiring_password_upgrade'])) {
			if ($_SESSION['password_upgrade_expiry'] < time()) {
				// took too long. Forget about them, and fall through to the login form again.
				unset($_SESSION['user_requiring_password_upgrade']);
			} else {
				$password_upgrade_key = $_SESSION['password_upgrade_key'];
				$person_name = $_SESSION['user_requiring_password_upgrade']['first_name'].' '.$_SESSION['user_requiring_password_upgrade']['last_name'];
				require TEMPLATE_DIR.'/upgrade_password_form.template.php';
				exit;
			}
		}

		require TEMPLATE_DIR.'/login_form.template.php';

	}//end printLogin()

	private function _findUser($username, $password)
	{
		$db =& $GLOBALS['db'];
		$sql = 'SELECT sm.*, p.*, GROUP_CONCAT(cr.congregationid) as congregation_restrictions, GROUP_CONCAT(gr.groupid) as group_restrictions
				FROM staff_member sm
					JOIN _person p ON sm.id = p.id
					LEFT JOIN account_congregation_restriction cr ON cr.personid = sm.id
					LEFT JOIN account_group_restriction gr ON gr.personid = sm.id
				WHERE sm.username = '.$db->quote($username).'
					AND active = 1
				GROUP BY p.id';
		$row = $db->queryRow($sql);
		if (!empty($row) && jethro_password_verify($password, $row['password'])) {
			$row['congregation_restrictions'] = empty($row['congregation_restrictions']) ? Array() : explode(',', $row['congregation_restrictions']);
			$row['group_restrictions'] = empty($row['group_restrictions']) ? Array() : explode(',', $row['group_restrictions']);
			return $row;
		}
		return NULL;

	}

	/**
	 * Does this user's login need a 2FA code, once trust-cookie and policy checks are done?
	 *
	 * Policy decision only: no trust-cookie lookup and no sendability check (those stay
	 * in _require2FA()). Password resets do not consult this: every reset requires an
	 * SMS code regardless of the account's login policy (see
	 * docs/docs/developer/reference/password-reset.mdx).
	 *
	 * @param array $user_details
	 * @return boolean
	 */
	private function _accountRequires2FA($user_details)
	{
		// Allow 2FA to be forcibly turned off regardless of admin settings e.g. for dev environments
		if (defined('2FA_ENABLED') && constant('2FA_ENABLED')==False) return FALSE;

		$req_perms = ifdef('2FA_REQUIRED_PERMS', '');
		if (!strlen($req_perms)) return FALSE;
		if (!empty($user_details['congregation_restrictions']) && !ifdef('2FA_EVEN_FOR_RESTRICTED_ACCTS', true)) {
			$this->_2faLog($user_details['username'].' (with cong restrictions) does not require 2FA');
			return FALSE;
		}
		if (!empty($user_details['group_restrictions']) && !ifdef('2FA_EVEN_FOR_RESTRICTED_ACCTS', true)) {
			$this->_2faLog($user_details['username'].' (with group restrictions) does not require 2FA');
			return FALSE;
		}

		list($required, $matchedPerm, $unknownPerm) = self::resolve2FARequired($req_perms, $user_details['permissions'], $this->getPermissionLevels());
		if ($unknownPerm !== NULL) {
			$this->_2faLog("ERROR: Unknown permission level ".$unknownPerm." in system setting 2FA_REQUIRED_PERMS");
			trigger_error("Unknown permission level ".$unknownPerm." in system setting 2FA_REQUIRED_PERMS", E_USER_WARNING);
		}
		if ($matchedPerm !== NULL) {
			$this->_2faLog($user_details['username'].' requires 2FA because they hold permission "'.$this->getPermissionLevels()[$matchedPerm].'"');
		}
		if (!$required) {
			$this->_2faLog($user_details['username'].' does not require 2FA');
		}
		return $required;
	}

	/**
	 * Pure 2FA-policy decision over the 2FA_REQUIRED_PERMS setting, so it can be
	 * unit-tested and shared by the password reset.
	 *
	 * @param string $req_perms		Value of 2FA_REQUIRED_PERMS ('' = policy disabled)
	 * @param int $permissions		The user's permission bits
	 * @param array $levels			Permission levels from getPermissionLevels()
	 * @return array				[required(bool), matched_perm(?int), unknown_perm(?string)];
	 *								unknown_perm is set (and required forced TRUE) when the
	 *								policy names an unknown permission level - error on the
	 *								side of requiring 2FA.
	 */
	public static function resolve2FARequired($req_perms, $permissions, $levels)
	{
		if (!strlen((string)$req_perms)) return Array(FALSE, NULL, NULL);
		$required = FALSE;
		$matchedPerm = NULL;
		$unknownPerm = NULL;
		foreach (explode(',', $req_perms) as $perm) {
			if (!isset($levels[$perm])) {
				$unknownPerm = $perm;
				$required = TRUE; // error on the side of requiring 2FA
				break;
			}

			if (((int)$permissions & (int)$perm) == (int)$perm) {
				$matchedPerm = (int)$perm;
				$required = TRUE;
				break;
			}
		}
		return Array($required, $matchedPerm, $unknownPerm);
	}

	private function _require2FA($user_details)
	{
		$verified = $_SESSION['2fa_verified'] ?? null;
		unset($_SESSION['2fa_verified']);
		if (!$this->_accountRequires2FA($user_details)) return FALSE;
		if ($verified !== null
			&& $verified['userid'] === (int)$user_details['id']
			&& $verified['expires'] > time()) {
			return FALSE;
		}

		// The trust-cookie lookup runs after the policy check (it moved there when
		// the policy was extracted for the password reset): outcomes are identical,
		// and logins that don't need 2FA skip the cookie query entirely.
		if (!empty($_COOKIE['Jethro2FATrust'])) {
			$db = $GLOBALS['db'];
			$SQL = 'SELECT * FROM 2fa_trust
					WHERE userid = '.(int)$user_details['id'].'
					AND expiry > NOW()
					AND token = '.$db->quote($_COOKIE['Jethro2FATrust']);
			$trust_record = $db->queryOne($SQL);

			if ($trust_record) {
				$this->_2faLog($user_details['username'].' has an active "trust this browser" record, so no 2FA is required');
				return FALSE;
			}

			if (rand(0, 10) == 0) {
				// Clean up old trust records
				$SQL = 'DELETE FROM 2fa_trust WHERE expiry < NOW()';
				$db->exec($SQL);
			}
		}

		require_once __DIR__ . '/jethro_sms.php';

		// This user's login needs 2FA. If no code can be sent, stop the login
		// rather than silently letting it through without a second factor.
		$blocked = $this->_2faBlockedReason();
		if ($blocked !== null) {
			$this->_2faLog('ERROR: '.$user_details['username'].' requires 2FA but cannot be sent a code: '.$blocked);
			$this->_notifySysadmins("The user ".$user_details['username']." was unable to log in: two-factor authentication is required for them, but Jethro cannot send a code (".$blocked."). Fix the SMS settings, or disable 2FA with define('2FA_ENABLED', false) in conf.php.");
			$this->_2faBlockedReason = $blocked;
		}
		return TRUE;
	}

	/**
	 * Handle the self-service "forgot password" flow for staff accounts.
	 *
	 * Entry points, all pre-login:
	 *  - POST of the forgot-password form: arms a reset and emails a link;
	 *  - POST of the forgot-username form: emails registered usernames;
	 *  - GET of the emailed link: validates the token, but never sends SMS;
	 *  - POSTs to send and verify the reset's SMS challenge;
	 *  - POST of the new-password form: completes the reset.
	 *
	 * Every reset requires an SMS code after the emailed link is opened, even for
	 * an account that does not use 2FA at login, so a compromised mailbox alone
	 * cannot take over the account. If no code can be sent (no gateway, no mobile
	 * number, or SMS_2FA_BLOCK_MESSAGES), the reset cannot complete and an
	 * administrator must reset the password.
	 * See docs/docs/developer/reference/password-reset.mdx.
	 *
	 * @return void
	 */
	private function _processPasswordReset()
	{
		if (!empty($_POST['forgot_password_username'])) {
			$this->_processResetRequest();
		} else if (!empty($_POST['forgot_username_email'])) {
			$this->_processForgotUsername();
		} else if (isset($_POST['reset_sms_send'])) {
			$this->_processResetSmsSend();
		} else if (isset($_POST['reset_sms_verify'])) {
			$this->_processResetSmsVerification();
		} else if (isset($_POST['reset_password_submitted'])) {
			$this->_processResetCompletion();
		} else if (!empty($_GET['reset_password'])) {
			$this->_validateResetToken();
		} else if (!empty($_REQUEST['reset_cancel'])) {
			// "Cancel and return to login" from the mid-reset form.
			unset($_SESSION['password_reset'], $_SESSION['2fa_verified']);
			session_write_close();
			header('Location: '.build_url(Array('*' => NULL)), true, 303);
			exit;
		}
	}

	/**
	 * Find an active staff account by username, with the person fields and
	 * restriction summaries the reset and 2FA-policy code need.
	 * @param string $username
	 * @return array|NULL
	 */
	private function _findResetUser($username)
	{
		$db =& $GLOBALS['db'];
		$sql = 'SELECT sm.id, sm.username, sm.permissions, sm.resethash, sm.resetsmshash,
					sm.resetsmsattempts, sm.resetsmssent,
					(sm.resetexpires > NOW()) AS reset_valid,
					(sm.resetsmssent > NOW() - INTERVAL 10 MINUTE) AS reset_sms_valid,
					(sm.resetsmssent > NOW() - INTERVAL 60 SECOND) AS reset_sms_cooldown,
					p.first_name, p.last_name, p.email, p.mobile_tel,
					GROUP_CONCAT(cr.congregationid) as congregation_restrictions, GROUP_CONCAT(gr.groupid) as group_restrictions
				FROM staff_member sm
					JOIN _person p ON sm.id = p.id
					LEFT JOIN account_congregation_restriction cr ON cr.personid = sm.id
					LEFT JOIN account_group_restriction gr ON gr.personid = sm.id
				WHERE sm.username = '.$db->quote($username).'
					AND sm.active = 1
				GROUP BY p.id';
		$row = $db->queryRow($sql);
		if (empty($row)) return NULL;
		$row['congregation_restrictions'] = empty($row['congregation_restrictions']) ? Array() : explode(',', $row['congregation_restrictions']);
		$row['group_restrictions'] = empty($row['group_restrictions']) ? Array() : explode(',', $row['group_restrictions']);
		return $row;
	}

	/**
	 * Arm and email a reset without contacting the SMS gateway.
	 *
	 * Every request requires an SMS code before the password form is usable, even
	 * for an account that does not use 2FA at login. The request itself sends only
	 * email, so a username alone can never trigger an SMS.
	 * See docs/docs/developer/reference/password-reset.mdx.
	 *
	 * @return void
	 */
	private function _processResetRequest()
	{
		$username = trim((string)$_POST['forgot_password_username']);
		if (!request_origin_is_proven_current()) {
			log_unproven_login_origin('staff');
			add_message('Your browser did not confirm this request came from this site. Please try again.', 'error');
			return;
		}

		$db = $GLOBALS['db'];
		$user = $this->_findResetUser($username);
		if (empty($user)) {
			password_verify($username, jethro_password_hash($username));
		} else {
			$token = generate_random_string(32);
			$tokenHash = jethro_password_hash($token);
			$armed = $db->exec('UPDATE staff_member
								SET resethash = '.$db->quote($tokenHash).',
									resetexpires = NOW() + INTERVAL 24 HOUR,
									resetsmshash = NULL, resetsmssent = NULL,
									resetsmsattempts = 0
								WHERE id = '.(int)$user['id'].'
									AND (resethash IS NULL OR resetexpires IS NULL OR resetexpires <= NOW() + INTERVAL 23 HOUR)');
			if (!$armed) {
				$this->_2faLog($user['username'].' re-requested a password reset within an hour; not re-sending');
			} else if (!$this->_sendResetEmail($user, $token)) {
				$db->exec('UPDATE staff_member
							SET resethash = NULL, resetexpires = NULL,
								resetsmshash = NULL, resetsmssent = NULL, resetsmsattempts = 0
							WHERE id = '.(int)$user['id'].' AND resethash = '.$db->quote($tokenHash));
				error_log('PASSWORD RESET FAILED (staff): username="'.log_safe_string($username).'" id='.$user['id'].' reset email could not be sent remote='.array_get($_SERVER, 'REMOTE_ADDR', '-'));
			} else {
				error_log('PASSWORD RESET REQUESTED (staff): username="'.log_safe_string($username).'" id='.$user['id'].' remote='.array_get($_SERVER, 'REMOTE_ADDR', '-'));
			}
		}

		add_message('If that username matches a user account, an email has been sent to the address on that account with a link for choosing a new password. The link expires after 24 hours.', 'success');
		session_write_close();
		header('Location: '.build_url(Array('*' => NULL)), true, 303);
		exit;
	}

	/**
	 * Why a password-reset SMS code must not (or cannot) be sent right now, or null
	 * when it can. Includes the SMS_2FA_BLOCK_MESSAGES kill switch, which login 2FA
	 * honours and the reset must not bypass.
	 * @return string|null
	 */
	private function _resetSmsBlocked()
	{
		if (ifdef('SMS_2FA_BLOCK_MESSAGES', ifdef('2FA_BLOCK_MESSAGES', false))) {
			return 'SMS_2FA_BLOCK_MESSAGES is in effect';
		}
		return $this->_2faBlockedReason();
	}

	/**
	 * Email the password-reset link to the address on a staff account.
	 * From-address convention mirrors _notifySysadmins().
	 * @param array $user
	 * @param string $token
	 * @return boolean TRUE on success
	 */
	private function _sendResetEmail($user, $token)
	{
		require_once 'include/emailer.class.php';
		// A malformed stored address would otherwise throw from setTo() (outside
		// Emailer::send()'s catch) and turn the generic response into an error page.
		if (!Emailer::validateAddress((string)$user['email'])) return FALSE;
		// BASE_URL_ABSOLUTE honours an absolute BASE_URL from conf.php, so a forged
		// Host header cannot point the emailed link (and its token) at another host.
		$url = BASE_URL_ABSOLUTE.'/?reset_password='.rawurlencode($token).'&username='.rawurlencode($user['username']);
		$body = "Hi %s,

A password reset has been requested for the '%s' user account at %s.

To choose a new password, follow this link within 24 hours:
%s

After opening the link, request an SMS verification code to unlock the new-password form.

If you didn't request this, you can ignore this email - your password will not change.";
		$text = sprintf($body, $user['first_name'], $user['username'], SYSTEM_NAME, $url);
		$html = sprintf(
			nl2br($body),
			ents($user['first_name']),
			ents($user['username']),
			ents(SYSTEM_NAME),
			'<a href="'.ents($url).'">'.ents($url).'</a>'
		);

		$from_address = $this->_systemEmailFromAddress();
		if (!strlen($from_address)) return FALSE;

		$message = Emailer::newMessage()
			->setSubject('Password reset for '.SYSTEM_NAME)
			->setFrom(array($from_address => SYSTEM_NAME.' Jethro System'))
			->setTo(array($user['email'] => $user['first_name'].' '.$user['last_name']))
			->text($text)
			->html($html);
		return Emailer::send($message);
	}

	/**
	 * Resolve a From address for system-generated emails: MEMBER_REGO_EMAIL_FROM_ADDRESS
	 * when configured, else the first sysadmin's address (same convention as _notifySysadmins()).
	 * @return string	Empty when nothing usable is configured.
	 */
	private function _systemEmailFromAddress()
	{
		$from_address = ifdef('MEMBER_REGO_EMAIL_FROM_ADDRESS', '');
		if (!strlen($from_address)) {
			// No configured sender: fall back to the first sysadmin's address.
			$from_address = (string)$GLOBALS['db']->queryOne('SELECT email
					FROM _person p JOIN staff_member sm ON sm.id = p.id
					WHERE email <> "" AND sm.active AND sm.permissions = '.PERM_SYSADMIN.'
					ORDER BY p.id LIMIT 1');
		}
		return $from_address;
	}

	/**
	 * Process the forgot-username form: email the active staff username(s)
	 * registered to a given email address.
	 *
	 * Responds identically whether or not the address matches an account, so the
	 * form reveals nothing to a probe. Several accounts may share an address
	 * (_person.email is not unique); all their usernames are listed. Each account
	 * is reminded at most once an hour (staff_member.usernamereminded), so a
	 * looping requester cannot mail-bomb an address.
	 * @return void
	 */
	private function _processForgotUsername()
	{
		$email = trim((string)$_POST['forgot_username_email']);
		if (!request_origin_is_proven_current()) {
			// Not a browser POST from a Jethro page (typically a scanner): refuse.
			// See docs/docs/developer/reference/request-origin-protection.mdx
			log_unproven_login_origin('staff');
			add_message('Your browser did not confirm this request came from this site. Please try again.', 'error');
			return;
		}

		require_once 'include/emailer.class.php';
		if (Emailer::validateAddress($email)) {
			$db = $GLOBALS['db'];
			$rows = $db->queryAll('SELECT sm.id, sm.username, p.first_name, p.email
					FROM staff_member sm
					JOIN _person p ON sm.id = p.id
					JOIN person_status ps ON ps.id = p.status
					WHERE p.email = '.$db->quote($email).'
						AND sm.active = 1 AND (NOT ps.is_archived)
					ORDER BY sm.username');
			$byAddress = Array();
			foreach ($rows as $row) {
				// The column collation folds accents (e.g. exämple.org = example.org), so a
				// look-alike address on a domain the requester owns can match. Count only
				// exact (ASCII case-insensitive) matches, and mail the stored address.
				$address = trim((string)$row['email']);
				if (strcasecmp($address, $email) !== 0) continue;
				// One conditional UPDATE per account, so concurrent requests cannot
				// all get past the hourly limit.
				$claimed = $db->exec('UPDATE staff_member
									SET usernamereminded = NOW()
									WHERE id = '.(int)$row['id'].'
										AND (usernamereminded IS NULL OR usernamereminded <= NOW() - INTERVAL 1 HOUR)');
				if ($claimed) $byAddress[$address][] = $row;
			}
			foreach ($byAddress as $address => $addressRows) {
				// Log the outcome, not the attempt: the hourly slot has already been
				// claimed above, so a failed send is why this address goes unmailed
				// until the hour is up.
				if ($this->_sendUsernameEmail($address, $addressRows)) {
					error_log('USERNAME REMINDER SENT (staff): email="'.log_safe_string($address).'" count='.count($addressRows).' remote='.array_get($_SERVER, 'REMOTE_ADDR', '-'));
				} else {
					error_log('USERNAME REMINDER FAILED (staff): email="'.log_safe_string($address).'" count='.count($addressRows).' remote='.array_get($_SERVER, 'REMOTE_ADDR', '-'));
				}
			}
		}

		add_message('If that email address matches a user account, an email has been sent listing the username(s) on the account.', 'success');
		session_write_close();
		header('Location: '.build_url(Array('*' => NULL)), true, 303); // 303 See Other: clean GET
		exit;
	}

	/**
	 * Email the username(s) registered to an address as HTML with a plain-text
	 * alternative. From-address convention mirrors _notifySysadmins().
	 * @see docs/docs/developer/reference/password-reset.mdx
	 * @param string $email
	 * @param list<array<string, mixed>> $rows Account rows with username and first_name.
	 * @return boolean TRUE on success
	 */
	private function _sendUsernameEmail($email, $rows)
	{
		$usernames = Array();
		$htmlUsernames = Array();
		foreach ($rows as $row) {
			$usernames[] = $row['username'];
			$htmlUsernames[] = '<b>'.ents($row['username']).'</b>';
		}
		$greeting = (count($rows) == 1) ? 'Hi '.$rows[0]['first_name'].',' : 'Hi,';
		$body = "%s

Your username to log in to %s is: %s

If you didn't request this reminder, you can ignore this email - no passwords have been changed.";
		$text = sprintf($body, $greeting, BASE_URL_ABSOLUTE, implode(', ', $usernames));
		$html = sprintf(
			nl2br($body),
			ents($greeting),
			'<a href="'.ents(BASE_URL_ABSOLUTE).'">'.ents(BASE_URL_ABSOLUTE).'</a>',
			implode(', ', $htmlUsernames)
		);

		$from_address = $this->_systemEmailFromAddress();
		if (!strlen($from_address)) return FALSE;

		$message = Emailer::newMessage()
			->setSubject('Username reminder for '.SYSTEM_NAME)
			->setFrom(array($from_address => SYSTEM_NAME.' Jethro System'))
			->setTo($email)
			->text($text)
			->html($html);
		return Emailer::send($message);
	}

	/**
	 * Accept an emailed token and bind a reset session without sending SMS.
	 * @return void
	 */
	private function _validateResetToken()
	{
		$token = (string)$_GET['reset_password'];
		$username = trim((string)array_get($_GET, 'username', ''));
		$row = strlen($username) ? $this->_findResetUser($username) : null;
		// The support override accepted at login must never verify a reset token.
		if (empty($row) || empty($row['reset_valid']) || empty($row['resethash'])
			|| !password_verify($token, (string)$row['resethash'])) {
			$this->_error = 'That password reset link is not valid. It may have expired or already been used. You can request a new one below.';
			return;
		}

		unset($_SESSION['2fa_verified'], $_SESSION['2fa'], $_SESSION['user_requiring_password_upgrade']);
		$_SESSION['password_reset'] = Array(
			'userid' => (int)$row['id'],
			'resethash' => $row['resethash'],
			'username' => $row['username'],
			'first_name' => $row['first_name'],
		);
		if ($this->_loadPasswordResetUser() === null) return;
		session_regenerate_id(true);
		session_write_close();
		header('Location: '.build_url(Array('*' => NULL)), true, 303);
		exit;
	}

	/**
	 * SQL identity of the active request bound to this session.
	 * Call only after _loadPasswordResetUser() has validated the session.
	 * @return string
	 */
	private function _passwordResetWhere(): string
	{
		$state = $_SESSION['password_reset'];
		return 'id = '.(int)$state['userid'].'
				AND active = 1 AND resethash = '.$GLOBALS['db']->quote($state['resethash']).'
				AND resetexpires > NOW()';
	}

	/**
	 * Revalidate request identity and expiry for every reset stage.
	 * See reference/password-reset.mdx.
	 *
	 * @param bool $post Require the normal pre-login POST origin proof.
	 * @return array<string, mixed>|null Current account and reset state.
	 */
	private function _loadPasswordResetUser(bool $post = false): ?array
	{
		if ($post && !request_origin_is_proven_current()) {
			log_unproven_login_origin('staff');
			$this->_error = 'Your browser did not confirm this request came from this site. Please try again.';
			return null;
		}
		$state = $_SESSION['password_reset'] ?? null;
		$row = $state !== null ? $this->_findResetUser($state['username']) : null;
		if (empty($row) || empty($row['reset_valid']) || empty($row['resethash'])
			|| (int)$row['id'] !== (int)$state['userid']
			|| !hash_equals((string)$state['resethash'], (string)$row['resethash'])) {
			unset($_SESSION['password_reset']);
			$this->_error = 'Your password reset has expired or already been used. Please request a new one.';
			return null;
		}
		return $row;
	}

	/**
	 * Whether this session verified the current, unexpired SMS challenge.
	 * @param array<string, mixed> $row Current account/reset row.
	 * @return bool
	 */
	private function _resetSmsVerified(array $row): bool
	{
		return !empty($row['reset_sms_valid']) && !empty($row['resetsmshash'])
			&& hash_equals((string)$row['resetsmshash'], (string)($_SESSION['password_reset']['sms_verified'] ?? ''));
	}

	/**
	 * Reserve one SMS send across all sessions before contacting the gateway.
	 * A resend rotates the challenge, never its shared code-attempt budget.
	 * @return void
	 */
	private function _processResetSmsSend()
	{
		$row = $this->_loadPasswordResetUser(true);
		if ($row === null || $this->_resetSmsVerified($row)) return;
		$db = $GLOBALS['db'];
		$where = $this->_passwordResetWhere();
		$code = generate_random_string(6, range(0, 9));
		$codeHash = jethro_password_hash($code);
		$reserved = $db->exec('UPDATE staff_member
			SET resetsmshash = '.$db->quote($codeHash).', resetsmssent = NOW()
			WHERE '.$where.' AND resetsmsattempts < 2
				AND (resetsmssent IS NULL OR resetsmssent <= NOW() - INTERVAL 60 SECOND)');
		if (!$reserved) {
			$this->_error = 'A code was recently requested, or no verification attempts remain. Wait at least 60 seconds before requesting another code.';
			return;
		}
		unset($_SESSION['password_reset']['sms_verified']);
		require_once __DIR__.'/jethro_sms.php';
		$problem = !strlen((string)$row['mobile_tel'])
			? 'there is no mobile number on your account'
			: $this->_resetSmsBlocked();
		if ($problem === null && !$this->_send2FAMessage($code.' is your code to reset your password on '.SYSTEM_NAME, $row)) {
			$problem = 'the SMS gateway could not send the code';
		}
		if ($problem !== null) {
			// Keep the cooldown even on failure. A delayed failure must not clear
			// a newer challenge or a replacement reset request.
			$db->exec('UPDATE staff_member SET resetsmshash = NULL
				WHERE '.$where.' AND resetsmshash = '.$db->quote($codeHash));
			$this->_2faLog('ERROR: '.$row['username'].' could not be sent a password reset code: '.$problem);
			$this->_error = 'Cannot send a verification code because '.$problem.'. Contact your system administrator, or try again after 60 seconds.';
			return;
		}
		$this->_2faLog($row['username'].' was sent a password reset code');
		add_message('A verification code has been sent to the mobile number on your account. It expires after 10 minutes.', 'success');
		session_write_close();
		header('Location: '.build_url(Array('*' => NULL)), true, 303);
		exit;
	}

	/**
	 * Verify only the reset's SMS factor, never authenticate the account.
	 *
	 * A row lock serializes the two checks: an earlier wrong attempt cannot
	 * scrap a concurrently reserved correct final attempt. Resends and password
	 * writes also lock this row, and the challenge is matched in every update.
	 * @return void
	 */
	private function _processResetSmsVerification()
	{
		$user = $this->_loadPasswordResetUser(true);
		if ($user === null || $this->_resetSmsVerified($user)) return;
		$db = $GLOBALS['db'];
		$where = $this->_passwordResetWhere();
		$challenge = (string)$user['resetsmshash'];
		$match = $where.' AND resetsmshash = '.$db->quote($challenge).'
						AND resetsmssent > NOW() - INTERVAL 10 MINUTE';
		$db->beginTransaction();
		try {
			$row = $db->queryRow('SELECT resetsmsattempts FROM staff_member WHERE '.$match.' FOR UPDATE');
			if (empty($challenge) || empty($row)) {
				$db->rollBack();
				$this->_error = 'That code has expired or been replaced. Request a new code.';
				return;
			}
			$reserved = $db->exec('UPDATE staff_member SET resetsmsattempts = resetsmsattempts + 1
				WHERE '.$match.' AND resetsmsattempts < 2');
			if (!$reserved) {
				$db->rollBack();
				$this->_error = 'Too many code attempts. Please request a new password reset when this request expires.';
				return;
			}
			$code = trim((string)array_get($_POST, 'reset_sms_code', ''));
			$correct = strlen($code) > 0 && password_verify($code, $challenge);
			$exhausted = !$correct && (int)$row['resetsmsattempts'] + 1 >= 2;
			if ($exhausted) {
				$db->exec('UPDATE staff_member
					SET resethash = NULL, resetexpires = NULL,
						resetsmshash = NULL, resetsmssent = NULL, resetsmsattempts = 0
					WHERE '.$match);
			}
			$db->commit();
		} catch (\Throwable $e) {
			$db->rollBack();
			throw $e;
		}
		if (!$correct) {
			if ($exhausted) {
				unset($_SESSION['password_reset']);
				error_log('PASSWORD RESET ABANDONED (staff): wrong SMS codes for id='.$user['id'].' remote='.array_get($_SERVER, 'REMOTE_ADDR', '-'));
				$this->_error = 'Too many incorrect codes. Please request a new password reset.';
			} else {
				$this->_error = 'Incorrect code. Please try again.';
			}
			return;
		}
		session_regenerate_id(true);
		$_SESSION['password_reset']['sms_verified'] = $challenge;
		session_write_close();
		header('Location: '.build_url(Array('*' => NULL)), true, 303);
		exit;
	}

	/**
	 * Save a new password only after this reset session verified the SMS challenge.
	 * The final write rechecks request, challenge and expiry atomically.
	 * SMS verification can satisfy one password-authenticated login for five
	 * minutes, without setting a durable trust cookie.
	 * @return void
	 */
	private function _processResetCompletion()
	{
		$row = $this->_loadPasswordResetUser(true);
		if ($row === null) return;
		if (!$this->_resetSmsVerified($row)) {
			$this->_error = 'Verify the SMS code before choosing a new password.';
			return;
		}
		$hashed = Staff_Member::processPasswordField('new_');
		if (!$hashed) return;
		$db = $GLOBALS['db'];
		$where = $this->_passwordResetWhere().' AND resetsmshash = '.$db->quote($row['resetsmshash']).'
						AND resetsmssent > NOW() - INTERVAL 10 MINUTE';
		$updated = $db->exec('UPDATE staff_member
			SET password = '.$db->quote($hashed).',
				resethash = NULL, resetexpires = NULL,
				resetsmshash = NULL, resetsmssent = NULL, resetsmsattempts = 0
			WHERE '.$where);
		unset($_SESSION['password_reset'], $_SESSION['2fa_verified']);
		if (!$updated) {
			$this->_error = 'Your password reset has expired or been replaced. Please request a new one.';
			return;
		}
		session_regenerate_id(true);
		$_SESSION['2fa_verified'] = ['userid' => (int)$row['id'], 'expires' => time() + 300];
		error_log('PASSWORD RESET COMPLETED (staff): username="'.log_safe_string($row['username']).'" id='.$row['id'].' remote='.array_get($_SERVER, 'REMOTE_ADDR', '-'));
		add_message('Your password has been updated. You can now log in with your new password.', 'success');
		session_write_close();
		header('Location: '.build_url(Array('*' => NULL)), true, 303);
		exit;
	}

	/**
	 * Pick the sender a 2FA code is sent as, and say why there isn't one.
	 *
	 * 2FA sends before login, so the acting user's mobile is unknown. Order:
	 *   1. 2FA_SENDER_ID, a literal sender ID or number;
	 *   2. else a literal SMS_SENDER (SMS_SENDER=_USER_MOBILE_ can't resolve yet);
	 *   3. else the first sender ID the provider reports as ACMA-registered:
	 *      from 1 July 2026 an unregistered alphanumeric ID is shown to
	 *      recipients as 'Unverified'.
	 * With no usable sender the code cannot be sent, and 2FA would lock every
	 * affected user out, so the login is refused with this reason instead.
	 *
	 * Pure, for unit tests. $senderIds is null when the provider couldn't be read.
	 *
	 * @param \Sms\SenderID[]|null $senderIds
	 * @return array{0: ?\Sms\SmsSender, 1: ?string} the sender, or null and why not
	 */
	public static function resolve2FASender(string $configured, string $smsSender, ?array $senderIds): array
	{
		$configured = trim($configured);
		if ($configured !== '') {
			return array(\Sms\senderFromString($configured), null);
		}
		$smsSender = trim($smsSender);
		if ($smsSender !== '' && $smsSender !== '_USER_MOBILE_') {
			return array(\Sms\senderFromString($smsSender), null);
		}
		foreach ($senderIds ?? array() as $senderId) {
			if ($senderId instanceof \Sms\SenderID && $senderId->acmaApproved === true) {
				return array($senderId, null);
			}
		}
		if ($senderIds === null) {
			return array(null, 'no sender ID could be read from the SMS provider');
		}
		if ($smsSender === '_USER_MOBILE_') {
			return array(null, 'SMS_SENDER is _USER_MOBILE_, which cannot be resolved before login, and the SMS provider reports no ACMA-registered sender ID');
		}
		return array(null, 'neither 2FA_SENDER_ID nor SMS_SENDER is set, and the SMS provider reports no ACMA-registered sender ID');
	}

	/**
	 * {@see resolve2FASender()} with the settings and provider sender IDs.
	 *
	 * The provider is only asked when neither setting names a literal sender,
	 * and its sender-ID list is cached, so repeated 2FA logins are cheap.
	 *
	 * @return array{0: ?\Sms\SmsSender, 1: ?string}
	 */
	private function _twoFASender(): array
	{
		require_once __DIR__ . '/jethro_sms.php';
		$configured = (string) ifdef('2FA_SENDER_ID', '');
		$smsSender = (string) ifdef('SMS_SENDER', '');
		$senderIds = null;
		if (trim($configured) === '' && (trim($smsSender) === '' || trim($smsSender) === '_USER_MOBILE_')) {
			$senderIds = array();
			$providerResult = Jethro\Sms\getSmsProvider();
			if ($providerResult->isFailure()) {
				$senderIds = null;
			} else {
				$idsResult = $providerResult->getValue()->getSenderIds();
				if ($idsResult->isFailure()) {
					$senderIds = null;
				} else {
					$senderIds = $idsResult->getValue();
				}
			}
		}
		return self::resolve2FASender($configured, $smsSender, $senderIds);
	}

	/**
	 * Why a 2FA code cannot be sent right now, or null when it can.
	 *
	 * @return string|null
	 */
	private function _2faBlockedReason()
	{
		if (Jethro\Sms\legacyTfaGatewayConstantsDefined()) {
			return 'conf.php still defines 2FA_SMS_* settings for a dedicated 2FA SMS gateway, which Jethro no longer supports (2FA codes go through the main SMS gateway)';
		}
		if (!Jethro\Sms\isConfigured()) {
			return 'no SMS gateway is configured';
		}
		list($sender, $problem) = $this->_twoFASender();
		if ($sender === null) {
			return $problem . '; set 2FA_SENDER_ID to an ACMA-registered sender ID, or register one with your SMS provider';
		}
		return null;
	}

	/**
	 * {@see _2faBlockedReason()}, for the system configuration page's warning.
	 *
	 * Null when 2FA is switched off (2FA_ENABLED = false), so a page that asks
	 * unconditionally doesn't complain on installs that don't use 2FA. Callers
	 * still decide whether anyone requires it (2FA_REQUIRED_PERMS).
	 *
	 * @return string|null null when a 2FA code can be sent
	 */
	public function twoFactorBlockedReason(): ?string
	{
		if (defined('2FA_ENABLED') && constant('2FA_ENABLED') == false) {
			return null;
		}
		return $this->_2faBlockedReason();
	}

	public function handle2FAMobileTelChange($person, $old_mobile)
	{
		$staff_member = new Staff_Member($person->id);
		if (!$staff_member) return;
		if ($staff_member->requires2FA()) {
			// Send a notification to the old number.
			$msg = 'The mobile number for user account "'.$staff_member->getValue('username').'" was just changed to '.$person->getValue('mobile_tel').'. If this is unexpected, please contact your system administrator ASAP.';
			$this->_send2FAMessage($msg, Array('mobile_tel' => $old_mobile));
		}
	}

	/**
	 * Initialise the 2FA process
	 * @param array $user_details  The user who has just entered their password correctly
	 * @return void
	 */
	private function _init2FA($user_details)
	{
		if ($this->_2faBlockedReason !== null) {
			add_message(
				'Two-factor authentication is required for your account, but Jethro cannot send you a code: '
				. $this->_2faBlockedReason
				. ". Ask your system administrator to fix the SMS settings, or to disable 2FA with define('2FA_ENABLED', false) in conf.php.",
				'error'
			);
			return;
		}

		$_SESSION['2fa'] = Array();
		$_SESSION['2fa']['pending_user'] = $user_details;
		$_SESSION['2fa']['code'] = generate_random_string(6, range(0, 9));
		$_SESSION['2fa']['key'] = generate_random_string(32);
		$_SESSION['2fa']['wrong_code_count'] = 0;
        $_SESSION['2fa']['expiry'] = time() + 600; // 10 minutes

		if (!strlen($user_details['mobile_tel'])) {
			$this->_2faLog($_SESSION['2fa']['pending_user']['username']." can't log in because they have blank mobile number. Sysadmins have been notified");
			add_message("2-factor auth could not be completed because your contact details are incomplete - no mobile number has been set.  Please ask a system administrator to add one to your record.", 'error');
			$this->_notifySysadmins("The user ".$_SESSION['2fa']['pending_user']['username']." was unable to log in, because they have no mobile number for 2-factor authentication.");
			$this->_reset2FA();
			return;
		}
		if (ifdef('SMS_2FA_BLOCK_MESSAGES', ifdef('2FA_BLOCK_MESSAGES', false))) {
			$this->_2faLog($_SESSION['2fa']['pending_user']['username']." WOULD BE sent code ".$_SESSION['2fa']['code']." at ".$_SESSION['2fa']['pending_user']['mobile_tel'].' but SMS_2FA_BLOCK_MESSAGES in effect');
			return;
		}

		$msg = $_SESSION['2fa']['code'].' '._('is your code to log in to').' '.SYSTEM_NAME;
		if (!$this->_send2FAMessage($msg, $_SESSION['2fa']['pending_user'])) {
			add_message("System error during 2-factor auth. Please contact your system administrator.", 'error');
			$this->_notifySysadmins("The user ".$_SESSION['2fa']['pending_user']['username']." was unable to log in, because Jethro could not send the 2-factor auth code. The SMS gateway may be down, or mis-configured.");
			$this->_reset2FA();
			return;
		}
		// Never log the code: anyone who can read the log could complete the login.
		$this->_2faLog($_SESSION['2fa']['pending_user']['username']." was sent a code at ".$_SESSION['2fa']['pending_user']['mobile_tel']);
	}

	/**
	 * Send the 2FA code by SMS to the 2fa-pending user.
	 * @param string $msg
	 * @param array	$recipient	person record data
	 * @return boolean TRUE if sent successfully.
	 */
	private function _send2FAMessage($msg, $recipient)
	{

		require_once __DIR__ . '/jethro_sms.php';
		// getSenderFromRequest() cannot be used here: the user is not logged in yet,
		// so the request carries no sender param and getCurrentUserMobileNumber() is null.
		list($sender, $problem) = $this->_twoFASender();
		if ($sender === null) {
			$this->_2faLog('Cannot send 2FA: ' . $problem);
			return false;
		}
		$smsRecipients = Jethro\Sms\getRecipientsFromPersonRecords([$recipient['id'] ?? 0 => $recipient]);
		$result = Jethro\Sms\sendSms((string)$msg, $smsRecipients, $sender, logToDb: false);

		if ($result->isFailure()) {
			$this->_2faLog("ERROR: SMS send failure: ".$result->getError());
			return FALSE;
		}

		$batch = $result->getValue();
		$summary = \Sms\sendSummary($batch->deliveries, $smsRecipients);

		if ($summary instanceof \Sms\Failed) {
			$this->_2faLog("ERROR: SMS send failure: ".$summary->error);
			return FALSE;
		}

		if ($summary instanceof \Sms\AllSent || $summary instanceof \Sms\PartialSuccess) {
			return TRUE;
		}

		$this->_2faLog("ERROR: SMS send failure: ".print_r($result, true));
		return FALSE;
	}

	/**
	 * Process the 'trust this device for X days' checkbox, if relevant.
	 */
	private function _process2FATrust()
	{
		$trust_days = ifdef('2FA_TRUST_DAYS', 30);
		$db = $GLOBALS['db'];
		if (!empty($_REQUEST['2fa_trust'])) {
			$trust_token = generate_random_string(64);
			$SQL = 'INSERT INTO 2fa_trust (userid, token, expiry)
					VALUES ('.(int)$_SESSION['2fa']['pending_user']['id'].',
							'.$db->quote($trust_token).',
							NOW() + INTERVAL '.(int)$trust_days.' DAY)';
			$res = $db->exec($SQL);
			if (!$res) $this->_2faLog("ERROR: Failed saving trust record to DB");

			$expiry = strtotime('+'.$trust_days.' days');
				$res = setcookie('Jethro2FATrust', $trust_token, [
					'expires'  => $expiry,
					'httponly' => true,
					'samesite' => 'Lax',
				]);
			if (!$res) trigger_error("Could not save trust cookie");


			$this->_2faLog("Saved trust to expire in $trust_days days on ".date('c', $expiry));
		}
	}

	/**
	 * Clear state of the 2FA system
	 */
	private function _reset2FA()
	{
		unset($_SESSION['2fa']);
	}

	/**
	 * Write to the 2FA log
	 * @param string $message
	 */
	private function _2faLog($message)
	{
		$logfile = ifdef('SMS_2FA_LOGFILE', ifdef('2FA_LOGFILE'));
		if (!$logfile) return;

		if (strtolower(ifdef('SMS_2FA_LOG_LEVEL', ifdef('2FA_LOG_LEVEL', 'full'))) == 'quiet') {
			if (0 !== strpos($message, 'ERROR')) return; // in 'quiet' mode, only log messages starting with ERROR.
		}

		$message = date('c').' '.$message;
		$res = @error_log($message."\n", 3, $logfile);
		if (!$res) trigger_error("Failed writing to 2fa logfile", E_USER_WARNING);
	}

	/**
	 * Send an email to SysAdmins (used if something goes wrong in the 2FA process)
	 * @param string $message
	 * @return void
	 */
	private function _notifySysAdmins($message)
	{
		$SQL = 'SELECT email
				FROM _person p
				JOIN person_status ps ON ps.id = p.status
				JOIN staff_member sm on p.id = sm.id
				WHERE email <> "" AND sm.active AND (NOT ps.is_archived) AND sm.permissions = '.PERM_SYSADMIN;
		$emails = $GLOBALS['db']->queryCol($SQL);
		if (empty($emails)) return;

		$text = "Hi, \n\nThis is an automated message to System Administrators, sent from the Jethro system at ".baseurl_absolute().".\n\n";
		$text .= $message;

		$message = Emailer::newMessage()
		  ->setSubject("Jethro SysAdmin notification for ".SYSTEM_NAME)
		  ->setTo($emails)
		  ->text($text);

		$from_address = ifdef('MEMBER_REGO_EMAIL_FROM_ADDRESS', '');
		if (strlen($from_address)) {
			$message->setFrom(array($from_address => SYSTEM_NAME.' Jethro System'));
		} else {
			$message->setFrom(array(reset($emails) => SYSTEM_NAME.' Jethro System'));

		}

		try {
			$res = Emailer::send($message);
			if (!$res) {
				$this->_2faLog("ERROR: Failed sending 2FA-failure notification to SysAdmins");
			}
		} catch (Exception $e) {
			$this->_2faLog("ERROR: Failed sending 2FA-failure notification to SysAdmins: ".$e->getMessage());
		}

	}

	public function hasUsers()
	{
		$SQL = 'SELECT count(*) FROM staff_member WHERE 1';
		try {
			return ($GLOBALS['db']->queryOne($SQL) > 0);
		} catch (Exception $ex) {
			return FALSE;
		}
	}
	
	/**
	 * Return true if the supplied password is correct for the current user
	 * @param string $password
	 * @return bool
	 */
	public function reverifyCurrentUser($password)
	{
		if (!empty($GLOBALS['JETHRO_INSTALLING'])) return TRUE;
		$res = $this->_findUser($this->getCurrentUser('username'), $password);
		return ($res) && ($res['id'] == $this->getCurrentUser('id'));
	}

	/**
	 * Check password strength
	 * @param string $val  Password to check
	 * @return   FALSE if no errors, otherwise a string error message.
	 */
	public static function getPasswordStrengthErrors($val)
	{
		$min_length = max(8, ifdef('PASSWORD_MIN_LENGTH', 8));
		if (strlen($val) < $min_length) {
			return 'Passwords must be at least '.$min_length.' characters';
		}
		if (!preg_match('/[0-9]+/', $val) || !preg_match('/[^0-9]+/', $val)) {
			return 'Passwords must contain letters and numbers';
		}
		return FALSE;
	}


}//end class
