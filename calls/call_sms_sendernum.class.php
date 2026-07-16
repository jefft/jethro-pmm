<?php

/**
 * AJAX endpoint for registering and validating a sender phone number
 * with the SMS provider.
 *
 * Actions:
 *   ?call=sms_sendernum&action=register&number=...&label=...
 *     Initiates sender number registration via the provider.
 *     Returns an HTML snippet showing validation UI for the next step
 *     (e.g. an OTP input for Cellcast, or a message about a verification
 *     link for 5CentSMS v5).  The caller injects this HTML into the page.
 *
 *   ?call=sms_sendernum&action=validate&number=...&label=...&otp=...
 *     Submits validation fields to the provider to complete sender number
 *     verification.  Returns an HTML snippet (success or error).
 *
 * Requires PERM_SENDSMS.
 */

class Call_SMS_SenderNum extends Call
{
	function run(): void
	{
		if (!$GLOBALS['user_system']->havePerm(PERM_SENDSMS)) {
			echo json_encode(['error' => 'Permission denied']);
			return;
		}

		require_once JETHRO_ROOT . '/include/jethro_sms.php';

		$action = $_REQUEST['action'] ?? '';

		if ($action === 'register') {
			$this->handleRegister();
			return;
		}

		if ($action === 'validate') {
			$this->handleValidate();
			return;
		}

		echo json_encode(['error' => 'Unknown action']);
	}

	/**
	 * Initiate sender number registration (Phase 1 — register + schema).
	 */
	private function handleRegister(): void
	{
		$number = $_REQUEST['number'] ?? '';
		$label  = $_REQUEST['label']  ?? '';

		if ($number === '') {
			echo '<div id="sms-register-' . ents($label) . '" class="sms-register-status" style="margin-top:4px;font-size:0.9em;color:#c00">'
				. ents(_('Phone number is required')) . '</div>';
			return;
		}

		if ($label === '') {
			echo '<div id="sms-register-' . ents($label) . '" class="sms-register-status" style="margin-top:4px;font-size:0.9em;color:#c00">'
				. ents(_('Label is required')) . '</div>';
			return;
		}

		$providerResult = \Jethro\Sms\getSmsProvider();
		if ($providerResult->isFailure()) {
			echo '<div id="sms-register-' . ents($label) . '" class="sms-register-status" style="margin-top:4px;font-size:0.9em;color:#c00">'
				. ents(_('SMS not configured')) . '</div>';
			return;
		}

		$provider = $providerResult->getValue();

		if (!$provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_NUMBER)) {
			echo '<div id="sms-register-' . ents($label) . '" class="sms-register-status" style="margin-top:4px;font-size:0.9em;color:#c00">'
				. ents(_('Not supported by this provider')) . '</div>';
			return;
		}

		$contact = new \Sms\ContactPhoneNumber(new \Sms\PhoneNumber($number), $label);
		$result = $provider->registerSenderNumber($contact, null);

		if ($result->isFailure()) {
			echo '<div id="sms-register-' . ents($label) . '" class="sms-register-status" style="margin-top:4px;font-size:0.9em;color:#c00">'
				. ents($result->getError()) . '</div>';
			return;
		}

			$step = $result->getValue();
        $html = $this->renderRegisterResult($step, $number, $label);
        echo '<div id="sms-register-' . ents($label) . '" class="sms-register-status" style="margin-top:4px;font-size:0.9em">'
            . $html . '</div>';
	}

	/**
	 * Complete sender number validation (Phase 2 — submit).
	 */
	private function handleValidate(): void
	{
		$number = $_REQUEST['number'] ?? '';
		$label  = $_REQUEST['label']  ?? '';

		$providerResult = \Jethro\Sms\getSmsProvider();
		if ($providerResult->isFailure()) {
			echo '<div id="sms-register-' . ents($label) . '" class="sms-register-status" style="margin-top:4px;font-size:0.9em;color:#c00">'
				. ents(_('SMS not configured')) . '</div>';
			return;
		}

		$provider = $providerResult->getValue();

		if (!$provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_NUMBER)) {
			echo '<div id="sms-register-' . ents($label) . '" class="sms-register-status" style="margin-top:4px;font-size:0.9em;color:#c00">'
				. ents(_('Not supported by this provider')) . '</div>';
			return;
		}

		$params = [];
		foreach ($_REQUEST as $k => $v) {
			if ($k !== 'action' && $k !== 'call' && $k !== 'number' && $k !== 'label') {
				$params[$k] = $v;
			}
		}

		$contact = new \Sms\ContactPhoneNumber(new \Sms\PhoneNumber($number), $label);
		$result = $provider->registerSenderNumber($contact, $params);

		if ($result->isFailure()) {
			echo '<div id="sms-register-' . ents($label) . '" class="sms-register-status" style="margin-top:4px;font-size:0.9em;color:#c00">'
				. ents($result->getError()) . '</div>';
			return;
		}

		$step = $result->getValue();
        $html = \Jethro\Sms\renderRegistrationStepHtml($step);
        echo '<div id="sms-register-' . ents($label) . '" class="sms-register-status" style="margin-top:4px;font-size:0.9em">'
            . $html . '</div>';
	}

	/**
	 * Render the registerSenderNumber Phase 1 result as HTML.
	 *
	 * @param \Sms\RegistrationStep $step    Result from registerSenderNumber Phase 1
	 * @param string $number  Phone number (for hidden field)
	 * @param string $label   Human-readable label (for hidden field)
	 */
	private function renderRegisterResult(\Sms\RegistrationStep $step, string $number, string $label): string
	{
		$html = '';

		if ($step->message !== '') {
			$html .= '<p><em>' . ents($step->message) . '</em></p>';
		}

		// Already registered — no further action needed
		if ($step->registered && $step->isComplete()) {
			return $html;
		}

		if ($step->fields !== []) {
			$html .= '<div class="sms-register-otp" style="display:block">';
			$html .= '<input type="hidden" name="action" value="validate">';
			$html .= '<input type="hidden" name="number" value="' . ents($number) . '">';
			$html .= '<input type="hidden" name="label" value="' . ents($label) . '">';
			$otpInputs = '';
			foreach ($step->fields as $f) {
				if ($f->type === 'hidden') continue;
				$otpInputs .= '&' . ents($f->name) . '=\' + $otp';
				$html .= '<input type="text" class="sms-otp-input"'
					. ' name="' . ents($f->name) . '"'
					. ' data-bind:otp'
					. ' placeholder="' . ents($f->label) . '"'
					. ' style="width:12em">';
			}
			$html .= ' <button type="button" class="sms-otp-submit btn"'
				. ' data-on:click="@post(\'?call=sms_sendernum&action=validate&number=' . ents($number) . '&label=' . ents($label) . $otpInputs . '\')"'
				. '>Verify</button>';
			$html .= '<div class="sms-register-error" style="display:none;color:red;margin-top:4px"></div>';
			$html .= '</div>';
		}

		return $html;
	}
}
