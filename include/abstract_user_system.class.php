<?php
abstract class Abstract_User_System
{
	protected $_permission_levels = Array();

	/**
	 * Append a message to the 2FA audit log (SMS_2FA_LOGFILE / 2FA_LOGFILE).
	 * Shared by staff login/reset 2FA and the member-area edit step-up.
	 * Callers must never include OTPs in $message.
	 * @param string $message
	 */
	protected function _2faLog($message)
	{
		$logfile = ifdef('SMS_2FA_LOGFILE', ifdef('2FA_LOGFILE'));
		if (!$logfile) return;

		if (strtolower(ifdef('SMS_2FA_LOG_LEVEL', ifdef('2FA_LOG_LEVEL', 'full'))) == 'quiet') {
			if (0 !== strpos($message, 'ERROR')) return; // in 'quiet' mode, only log messages starting with ERROR.
		}

		$message = date('c').' '.$message;
		$res = @error_log($message."\n", 3, $logfile);
		if (!$res) trigger_error("Failed writing to 2fa logfile ".$logfile, E_USER_WARNING);
	}

	/**
	 * Pick the sender a 2FA code is sent as, and say why there isn't one.
	 * {@see User_System::resolve2FASender()} for the precedence rules.
	 * @return array{0: ?\Sms\SmsSender, 1: ?string} the sender, or null and why not
	 */
	protected function _twoFASender(): array
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
		return User_System::resolve2FASender($configured, $smsSender, $senderIds);
	}

	/**
	 * Send the 2FA code by SMS to the given person record.
	 * @param string $msg	MUST NOT contain more OTPs than necessary; it is sent verbatim
	 * @param array	$recipient	person record data
	 * @return boolean TRUE if sent successfully.
	 */
	protected function _send2FAMessage($msg, $recipient)
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

		// Result payloads can echo provider errors containing submitted content:
		// report the failure class, not the whole object.
		$this->_2faLog("ERROR: SMS send failed with an unexpected provider result");
		return FALSE;
	}


	/**
	 * Get details of the currently-authorised person
	 * (may be via a user account (user_system) or a member account (member_user_system).
	 * Call this method when you don't care how they've logged in.
	 * @param string $field	Particular field to return; null=return all fields
	 * @return mixed
	 */

	abstract public function getCurrentPerson();

	public function getCurrentUser($field='')
	{
		return NULL;
	}

	public function getCurrentMember($field='')
	{
		return NULL;
	}

	public function getCurrentRestrictions($type=NULL)
	{
		return Array();
	}

	protected function _loadPermissionLevels()
	{
		if (!empty($this->_permission_levels)) return;
		include 'permission_levels.php';
		$enabled_features = explode(',', strtoupper(ifdef('ENABLED_FEATURES', '')));
		foreach ($PERM_LEVELS as $i => $detail) {
			list($define_symbol, $desc, $feature_code) = $detail;
			define('PERM_'.$define_symbol, $i);
			if (empty($feature_code) || in_array($feature_code, $enabled_features)) {
				$this->_permission_levels[$i] = $desc;
			}
		}
	}

	public function getPermissionLevels()
	{
		$this->_loadPermissionLevels();
		return $this->_permission_levels;
	}

	public function handle2FAMobileTelChange($person, $old_mobile)
	{
		throw new \RuntimeException("Essential function not implemented");
		exit;
	}

}
