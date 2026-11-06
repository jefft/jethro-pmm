<?php
require_once JETHRO_ROOT.'/include/general.php';
require_once JETHRO_ROOT.'/include/abstract_user_system.class.php';
class Member_User_System extends Abstract_User_System
{
	private $_error;

	public function __construct()
	{
		if (!strlen(ifdef('MEMBER_REGO_EMAIL_FROM_ADDRESS'))
				|| !strlen(ifdef('MEMBER_REGO_EMAIL_FROM_NAME'))
				|| !strlen(ifdef('MEMBER_REGO_EMAIL_SUBJECT'))
		) {
			?>
			<p>Setup incomplete:  A system administrator needs to finish configuring the members area on this system via the System Configuration page.</p>
			<?php
			exit;
		}
	}

	public function run() {
		if (!empty($_REQUEST['logout'])) {
			$this->_clearAuthMember();
		} else if (empty($_SESSION['member']) && !empty($_REQUEST['login-request']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
			$this->handleLoginRequest();
		} else if (!empty($_REQUEST['password-request']) && !empty($_REQUEST['email'])) {
			$this->handleAccountRequest();
		} else if (!empty($_REQUEST['verify'])) {
			$this->processEmailVerification();
		} else if (!empty($_REQUEST['set-password'])) {
			$this->processSetPassword();
		}

		if (!empty($_SESSION['member'])) {
			if (defined('SESSION_TIMEOUT_MINS') && constant('SESSION_TIMEOUT_MINS')) {
				if ((time() - $_SESSION['last_activity_time']) / 60 > SESSION_TIMEOUT_MINS) {
					// They have been idle too long
					$this->_clearAuthMember();
					$this->printLogin();
				}
			}
			if (defined('SESSION_MAXLENGTH_MINS') && constant('SESSION_MAXLENGTH_MINS')) {
				if ((time() - $_SESSION['login_time']) / 60 > SESSION_MAXLENGTH_MINS) {
					// They have been logged in too long
					$this->_clearAuthMember();
					$this->printLogin();
				}

			}
			$_SESSION['last_activity_time'] = time();
			// Member identity only: staff-account restrictions must not apply in the members area
			// (see docs/docs/developer/reference/db-session-identity.md)
			$GLOBALS['db']->setCurrentMemberID((int)$_SESSION['member']['id']);

			$this->_loadPermissionLevels();
			return;

		} else {
			$this->printLogin();
		}

	}

	private function handleLoginRequest()
	{
			// process the login form
			if (!request_origin_is_proven_current()) {
				// Not a browser POST from a Jethro page (typically a scanner): refuse
				// before the password check. See docs/docs/developer/reference/request-origin-protection.mdx
				log_unproven_login_origin('members');
				$this->_error = 'Your browser did not confirm this login came from this site. Please try again.';
				return;
			}
			$user_details = $this->_findAuthMember($_POST['email'], $_POST['password']);
			if (is_null($user_details)) {
				$this->_error = 'Incorrect email address or password';
				return;
			} else if ($user_details === FALSE) {
				$this->_error = 'Sorry, your account has been archived';
			} else {
				// Log the member in
				$this->_setAuthMember($user_details);
				// The login form was POSTed; redirect so the page the browser keeps in its history is a
				// clean GET. Otherwise reloading or restoring the tab re-sends the email and password.
				session_write_close();
				header('Location: '.build_url(Array()), true, 303); // 303 See Other: the follow-up request is always a GET
				exit;
			}
	}

	public function sendActivationEmail($person)
	{
		$hash = generate_random_string(32);
		$SQL = 'UPDATE _person
				SET resethash='.$GLOBALS['db']->quote($hash).',
				resetexpires = NOW() + INTERVAL 24 HOUR
				WHERE id = '.(int)$person['id'];
		$res = $GLOBALS['db']->exec($SQL);

		$url = baseurl_absolute().'/members/?email='.rawurlencode($person['email']).'&verify='.rawurlencode($hash);

		$body = "Hi %s,

To activate your %s account, please %s

If you didn't request an account, you can just ignore this email";

		$text = sprintf($body, $person['first_name'], SYSTEM_NAME, 'go to '.$url);
		$html = sprintf(nl2br($body), $person['first_name'], SYSTEM_NAME, '<a href="'.$url.'">click here</a>.');

		$message = Emailer::newMessage()
		  ->setSubject(MEMBER_REGO_EMAIL_SUBJECT)
		  ->setFrom(array(MEMBER_REGO_EMAIL_FROM_ADDRESS => MEMBER_REGO_EMAIL_FROM_NAME))
		  ->setTo(array($person['email'] => $person['first_name'].' '.$person['last_name']))
		  ->text($text)
		  ->html($html);

		$res = Emailer::send($message);

		return $res;
	}

	private function handleAccountRequest()
	{
			$person = $this->findCandidateMember($_REQUEST['email']);
			require_once 'include/emailer.class.php';
			$failureEmail = MEMBER_REGO_FAILURE_EMAIL;

			if (is_array($person)) {
				// Send them an email
				$res = $this->sendActivationEmail($person);

				if (TRUE == $res) {
					require_once 'templates/account_request_received.template.php';
					exit;
				} else {
					$this->_error = 'Could not send to the specified address.  Your email server may be experiencing problems.';
					return;
				}

			} else if (!Emailer::validateAddress($_REQUEST['email'])) {
				$this->_error = 'You have entered an invalid email address.  Please check the address and try again.';

			} else if (($person == -1) && !empty($failureEmail)) {
				// This email address is in use by two or more persons from *different families*.
				// Therefore this address cannot be used for member access.

				$message = Emailer::newMessage()
				  ->setSubject("Member Account request from multi-family email")
				  ->setFrom(array(MEMBER_REGO_EMAIL_FROM_ADDRESS => SYSTEM_NAME.' Jethro System'))
				  ->setTo(MEMBER_REGO_FAILURE_EMAIL)
				  ->text("Hi, \n\nThis is an automated message from the Jethro system at ".baseurl_absolute().".\n\n"
						  ."Somebody has used the form at ".baseurl_absolute()."/members to request member-access to this Jethro system. \n\n"
						  ."The email address they specified was ".$_REQUEST['email']." but this address belongs to SEVERAL persons from DIFFERENT families.  It therefore can't be used for member access.\n\n"
						  ."Please look up this email address in Jethro and contact the relevant persons to help them solve this problem.\n\n");

				$res = Emailer::send($message);

				// Show the user the generic "thanks" page - because we do not want
				// to tell strangers whether an email is or isn't known.
				require_once 'templates/account_request_received.template.php';
				exit;

			} else if (!empty($failureEmail)) {
				// This email address doesn't match any person record.
				// Send the administrator an email

				$message = Emailer::newMessage()
				  ->setSubject("Member Account request from unknown email")
				  ->setFrom(array(MEMBER_REGO_EMAIL_FROM_ADDRESS => SYSTEM_NAME.' Jethro System'))
				  ->setTo(MEMBER_REGO_FAILURE_EMAIL)
				  ->text("Hi, \n\nThis is an automated message from the Jethro system at ".baseurl_absolute().".\n\n"
						  ."Somebody has used the form at ".baseurl_absolute()."/members to request member-access to this Jethro system. \n\n"
						  ."The email address they specified was ".$_REQUEST['email']." but there is no current person record in the Jethro system with that address. (There could be an archived record).\n\n"
						  ."If you believe this person is a church member, please add their email address to their person record and then ask them to try registering again.\n\n");

				$res = Emailer::send($message);

				// Show the user the generic "thanks" page - because we do not want
				// to tell strangers whether an email is or isn't known.
				require_once 'templates/account_request_received.template.php';
				exit;
			} else {
				// Show the user the generic "thanks" page - because we do not want
				// to tell strangers whether an email is or isn't known.
				// (even though there is no failure email to send)
				require_once 'templates/account_request_received.template.php';
				exit;
			}
	}

	private function processEmailVerification() {
		if ($person = $this->_findPendingMember($_REQUEST['email'], $_REQUEST['verify'])) {
			$this->_setAuthMember($person);
			require_once('templates/set_password.template.php');
			exit;
		} else {
			$this->_error = 'The account request is not valid.  You may have used an out-of-date link.  Please try registering again.';
		}
	}

	private function processSetPassword() {
		$db = $GLOBALS['db'];
		$val = $_REQUEST['password1'];
		if ($val != $_REQUEST['password2']) {
			$this->_error = 'Password and password confirmation do not match.  Try again.';
			require_once('templates/set_password.template.php');
			exit;
		} else if (strlen($val) < MEMBER_PASSWORD_MIN_LENGTH) {
			$this->_error = 'Password is too short - must be at least '.MEMBER_PASSWORD_MIN_LENGTH.' characters; Password not saved.';
			require_once('templates/set_password.template.php');
			exit;
		} else if (!preg_match('/[0-9]+/', $val) || !preg_match('/[^0-9]+/', $val)) {
			$this->_error = 'Password is too simple - it must contain letters and numbers; Password not saved.';
			require_once('templates/set_password.template.php');
			exit;
		} else {
			$sql = 'UPDATE _person '
					. 'SET `member_password` = '.$db->quote(jethro_password_hash($val)).', '
					. 'resethash = NULL, '
					. 'resetexpires = NULL '
					. 'WHERE id = '.(int)$_SESSION['member']['id'];
			$res = $db->exec($sql);

			if (!empty($_REQUEST['isreset'])) {
				add_message('Your password has been successfully changed.');
			} else {
				add_message('Welcome!  Your account is complete and you are now logged in.');
			}
		}
	}


	public function printLogin()
	{
		require TEMPLATE_DIR.'/login_form.template.php';
		exit;

	}//end printLogin()

	/**
	 * Get details of the currently-authorised church member
	 * @param string $field	Particular field to return; null=return all fields
	 * @return mixed
	 */
	public function getCurrentMember($field='')
	{
		if (empty($_SESSION['member'])) {
			return NULL;
		} else {
			if (empty($field)) {
				return $_SESSION['member'];
			} else {
				return array_get($_SESSION['member'], $field, '');
			}
		}

	}

	/**
	 * Get details of the currently-authorised church member
	 * @see Abstract_User_System::getCurrentPerson()
	 * @param string $field	Particular field to return; null=return all fields
	 * @return mixed
	 */
	public function getCurrentPerson($field='')
	{
		return $this->getCurrentMember($field);
	}

	/**
	 * Set the session as having the specified person logged in
	 * @param array $member_details
	 */
	private function _setAuthMember($member_details)
	{
		// Recreate session ID when logging in
		session_regenerate_id();
		$_SESSION = Array();
		$_SESSION['member'] = $member_details;
		$_SESSION['login_time'] = time();
		$_SESSION['last_activity_time'] = time();
	}

	/**
	 * Set the session as not having any member logged in
	 */
	private function _clearAuthMember() {
		unset($_SESSION['member_edit_verified'], $_SESSION['member_edit_challenge']);
		$_SESSION['member'] = NULL;
		$_SESSION['login_time'] = NULL;
		$_SESSION['last_activity_time'] = NULL;
	}

	/**
	 * Find a person record to which we could attach a member account
	 * If the email address belongs to several persons in the one family, it returns the first member
	 *   preferring adult males.
	 * If the email address belongs to several persons in different families, returns -1
	 *   because such addresses cannot be used for member-login.
	 * If the email address does not belong to any person record, returns null.
	 * @param string $email	Email address for the account
	 * @return mixed.
	 */
	public function findCandidateMember($email)
	{
		$db =& $GLOBALS['db'];
		$sql = 'SELECT COUNT(DISTINCT familyid) '
				. 'FROM _person p '
				. 'JOIN person_status ps ON ps.id = p.status '
				. 'WHERE email = '.$db->quote($email).''
				. 'AND (NOT ps.is_archived)';
		$familyCount = $db->queryOne($sql);

		if ($familyCount > 1) return -1;


		$sql = 'SELECT p.*
				FROM _person p
				JOIN person_status ps ON ps.id = p.status
				JOIN age_bracket ab ON ab.id = p.age_bracketid
				WHERE p.email  = '.$db->quote($email).'
				AND (NOT ps.is_archived)
				ORDER BY (IF(p.member_password IS NOT NULL, 0, 1)), ab.`rank` ASC, p.gender DESC';
		$res = $db->queryRow($sql);

		return $res;
	}

	/**
	 * Find a person record that has the specified email and account-creation hash
	 * @param string $email
	 * @param string $hash
	 * @return array
	 */
	private function _findPendingMember($email, $hash) {
		$db =& $GLOBALS['db'];
		$sql = 'SELECT p.*
				FROM _person p
				WHERE p.email  = '.$db->quote($email).'
				AND resethash = '.$db->quote($hash).'
				AND resetexpires > NOW()';
		$res = $db->queryRow($sql);
		return $res;
	}


	/**
	 * Find a person record that matches the given email and password
	 * (Matches member-area AND control-centre passwords).
	 * @param string $email		Find a person with this record
	 * @param string $password	Find a person with this member_password
	 * @return mixed. Array of matching person details, or FALSE if only an archived person was found, or NULL if no persons match the username/password 
	 */
	private function _findAuthMember($email, $password)
	{
		$db =& $GLOBALS['db'];
		$sql = 'SELECT p.*, sm.password, ps.is_archived as status_archived
				FROM _person p
				LEFT JOIN staff_member sm ON sm.id = p.id
				JOIN person_status ps ON ps.id = p.status
				WHERE p.email  = '.$db->quote($email).'
					AND ((member_password IS NOT NULL) OR (sm.password IS NOT NULL))';
		$res = $db->queryAll($sql);
		$found_archived = FALSE;
		foreach ($res as $row) {
			if ($row['status_archived']) {
				$found_archived = TRUE;
				continue;
			}
			if (jethro_password_verify($password, $row['member_password'])) {
				unset($row['member_password']);
				unset($row['history']);
				return $row;
			}
			// Auth using the control centre password if available
			if (strlen($row['password']) && jethro_password_verify($password, $row['password'])) {
				unset($row['member_password']);
				unset($row['history']);
				return $row;
			}
		}
		if ($found_archived) return FALSE;
		return NULL;
	}

	public function handle2FAMobileTelChange($person, $old_mobile)
	{
		$staff_member = new Staff_Member($person->id);
		if (!$staff_member) return;
		if ($staff_member->requires2FA()) {
			throw new \RuntimeException("Attempt to change 2FA user's mobile number via the members interface");
		}
	}

	//-- Member-area edit SMS step-up ------------------------------------------

	/**
	 * Live person row for the signed-in member, read from _person at call time
	 * (not the login-time session snapshot). Null when the account is archived.
	 * @return array|null
	 */
	private function _loadLiveMember()
	{
		$memberId = (int)$_SESSION['member']['id'];
		if (!$memberId) return NULL;
		$db = $GLOBALS['db'];
		return $db->queryRow('SELECT p.*, ps.is_archived
				FROM _person p
				JOIN person_status ps ON ps.id = p.status
				WHERE p.id = '.$memberId);
	}

	/**
	 * Why a member-edit verification code cannot be sent right now, or null.
	 * Unlike the staff login preflight this is unconditional: the member-area
	 * edit gate is mandatory and staff 2FA settings (2FA_ENABLED etc.) do not
	 * exempt members. SMS_2FA_BLOCK_MESSAGES still blocks, for demo installs.
	 * @return string|null
	 */
	private function _memberEditSmsBlocked()
	{
		if (Jethro\Sms\legacyTfaGatewayConstantsDefined()) {
			return 'conf.php still defines 2FA_SMS_* settings for a dedicated 2FA SMS gateway, which Jethro no longer supports';
		}
		if (!Jethro\Sms\isConfigured()) {
			return 'no SMS gateway is configured';
		}
		list($sender, $problem) = $this->_twoFASender();
		if ($sender === null) {
			return $problem;
		}
		return NULL;
	}

	/**
	 * Send a member-edit verification code to the member's CURRENT stored
	 * mobile. Explicit POST only (never automatic on page view); recipient is
	 * always the live database row, never request data.
	 *
	 * Challenge state lives in _person: membereditsmshash/sent/attempts, raw
	 * SQL like the staff reset fields. A database-backed 60-second cooldown
	 * and 10-minute validity are shared across sessions; five attempts per
	 * challenge. The code is never logged; a failed send clears only its own
	 * hash and keeps the cooldown.
	 * @return void
	 */
	private function _processMemberEditSmsSend()
	{
		if (!request_origin_is_proven_current()) return; // scanner POST: silently ignore
		$row = $this->_loadLiveMember();
		if ($row === NULL || !empty($row['is_archived'])) return;
		$db = $GLOBALS['db'];
		$where = 'id = '.(int)$row['id'];
		$code = generate_random_string(6, range(0, 9));
		$codeHash = jethro_password_hash($code);
		$reserved = $db->exec('UPDATE _person
			SET membereditsmshash = '.$db->quote($codeHash).', membereditsmssent = NOW(),
				membereditsmsattempts = 0
			WHERE '.$where.'
				AND (membereditsmssent IS NULL OR membereditsmssent <= NOW() - INTERVAL 60 SECOND)');
		if (!$reserved) {
			$this->_error = 'A code was recently requested. Wait at least 60 seconds before requesting another code.';
			return;
		}
		// A resend replaces the challenge: invalidate any proof bound to the old one.
		unset($_SESSION['member_edit_verified']);
		require_once JETHRO_ROOT.'/include/jethro_sms.php';
		$problem = !strlen((string)$row['mobile_tel'])
			? 'there is no mobile number on your account'
			: $this->_memberEditSmsBlocked();
		if ($problem === NULL && !$this->_send2FAMessage(
				$code.' is your code to edit your details on '.SYSTEM_NAME, $row)) {
			$problem = 'the SMS gateway could not send the code';
		}
		if ($problem !== NULL) {
			// Keep the cooldown even on failure. A delayed failure must not
			// clear a newer challenge.
			$db->exec('UPDATE _person SET membereditsmshash = NULL
				WHERE '.$where.' AND membereditsmshash = '.$db->quote($codeHash));
			$this->_error = 'Cannot send a verification code because '.$problem.'. Contact your system administrator, or try again after 60 seconds.';
			return;
		}
		// Bind the pending challenge to the bindings it was sent against:
		// verification compares these against the live database, so a mobile
		// or family change requires a new send.
		$_SESSION['member_edit_challenge'] = Array(
			'personid' => (int)$row['id'],
			'familyid' => (int)$row['familyid'],
			'mobile_tel' => (string)$row['mobile_tel'],
			'hash' => $codeHash,
		);
		add_message('A verification code has been sent to the mobile number on your account. It expires after 10 minutes.', 'success');
	}

	/**
	 * Verify a member-edit SMS code without authenticating a login. Grants a
	 * five-minute edit authority bound to member, family, mobile and the exact
	 * challenge hash; rechecked against the live database on every protected
	 * request. Row lock serializes attempts like the staff reset flow.
	 * @return void
	 */
	private function _processMemberEditSmsVerification()
	{
		if (!request_origin_is_proven_current()) return;
		$challenge = $_SESSION['member_edit_challenge'] ?? NULL;
		$row = $this->_loadLiveMember();
		if ($challenge === NULL || $row === NULL || !empty($row['is_archived'])) {
			$this->_error = 'No verification code is pending. Open Edit and request a new code.';
			return;
		}
		// The code only counts for the bindings it was sent against.
		if ((int)$challenge['personid'] !== (int)$row['id']
				|| (int)$challenge['familyid'] !== (int)$row['familyid']
				|| $challenge['mobile_tel'] !== (string)$row['mobile_tel']) {
			$this->_error = 'Your account details have changed since the code was sent. Request a new code.';
			return;
		}
		$db = $GLOBALS['db'];
		$hash = (string)$challenge['hash'];
		$match = 'id = '.(int)$row['id'].' AND membereditsmshash = '.$db->quote($hash).'
				AND membereditsmssent > NOW() - INTERVAL 10 MINUTE';
		$db->beginTransaction();
		try {
			$found = $db->queryOne('SELECT membereditsmsattempts FROM _person WHERE '.$match.' FOR UPDATE');
			if (empty($hash) || $found === NULL) {
				$db->rollBack();
				$this->_error = 'That code has expired or been replaced. Request a new code.';
				return;
			}
			$reserved = $db->exec('UPDATE _person SET membereditsmsattempts = membereditsmsattempts + 1
				WHERE '.$match.' AND membereditsmsattempts < 5');
			if (!$reserved) {
				$db->rollBack();
				$this->_error = 'Too many code attempts. Please request a new code.';
				return;
			}
			$code = trim((string)array_get($_POST, 'member_edit_sms_code', ''));
			$correct = strlen($code) > 0 && password_verify($code, $hash);
			$exhausted = !$correct && ((int)$found + 1 >= 5);
			if ($exhausted) {
				$db->exec('UPDATE _person
					SET membereditsmshash = NULL, membereditsmssent = NULL, membereditsmsattempts = 0
					WHERE '.$match);
			}
			$db->commit();
		} catch (\Throwable $e) {
			$db->rollBack();
			throw $e;
		}
		if (!$correct) {
			if ($exhausted) {
				unset($_SESSION['member_edit_challenge']);
				error_log('MEMBER EDIT VERIFICATION ABANDONED: wrong SMS codes for id='.$row['id'].' remote='.array_get($_SERVER, 'REMOTE_ADDR', '-'));
				$this->_error = 'Too many incorrect codes. Please request a new code.';
			} else {
				$this->_error = 'Incorrect code. Please try again.';
			}
			return;
		}
		session_regenerate_id(true);
		$_SESSION['member_edit_verified'] = Array(
			'personid' => (int)$row['id'],
			'familyid' => (int)$row['familyid'],
			'mobile_tel' => (string)$row['mobile_tel'],
			'hash' => $hash,
			'expires' => time() + 300,
		);
	}

	/**
	 * Whether the current session holds valid member-edit authority, rechecked
	 * against the live database: expiry, archival, family/mobile change or a
	 * newer challenge each void it.
	 * @return bool
	 */
	public function hasVerifiedMemberEdit()
	{
		$proof = $_SESSION['member_edit_verified'] ?? NULL;
		if (!is_array($proof) || $proof['expires'] < time()) return FALSE;
		$row = $this->_loadLiveMember();
		if ($row === NULL || !empty($row['is_archived'])) return FALSE;
		return (int)$proof['personid'] === (int)$row['id']
			&& (int)$proof['familyid'] === (int)$row['familyid']
			&& $proof['mobile_tel'] === (string)$row['mobile_tel']
			&& $proof['hash'] === (string)$row['membereditsmshash'];
	}

	/**
	 * True when the current request may proceed into the member edit view:
	 * already verified, or a send/verify POST has just been handled. The edit
	 * view must recheck hasVerifiedMemberEdit() before rendering or saving.
	 * @return bool
	 */
	public function requireVerifiedMemberEdit()
	{
		if (!empty($_SESSION['member'])) {
			foreach (Array('member_edit_sms_send', 'member_edit_sms_verify') as $action) {
				if (!empty($_POST[$action])) {
					if ($action === 'member_edit_sms_send') {
						$this->_processMemberEditSmsSend();
					} else {
						$this->_processMemberEditSmsVerification();
					}
				}
			}
		}
		return $this->hasVerifiedMemberEdit();
	}

	/**
	 * Render the SMS gate shown instead of the edit form before verification:
	 * explicit Send and Verify forms (never an automatic send), masked
	 * recipient, and the administrator-help fallback. No contact values beyond
	 * the mask are exposed.
	 * @return void
	 */
	public function printMemberEditVerification()
	{
		$row = $this->_loadLiveMember();
		if ($row === NULL || !empty($row['is_archived'])) return;
		$challenge = $_SESSION['member_edit_challenge'] ?? NULL;
		$mobileMasked = $challenge['mobile_tel'] ?? (string)$row['mobile_tel'];
		$digits = preg_replace('/\D/', '', $mobileMasked);
		$masked = strlen($digits) >= 2
			? '•••• ••• '.substr($digits, -2)
			: 'no mobile number on your account';
		require TEMPLATE_DIR.'/edit_verification.template.php';
	}

	/**
	 * Consume the edit authority after a successful save; the next edit
	 * requires a fresh verification.
	 * @return void
	 */
	public function consumeMemberEditVerification()
	{
		unset($_SESSION['member_edit_verified']);
	}

}
