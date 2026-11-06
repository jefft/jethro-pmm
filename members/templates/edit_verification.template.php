<h3>Verify your identity</h3>
<p>Editing your details requires a verification code, sent by SMS to the mobile number on your account (<?php echo ents($masked); ?>).</p>
<?php
if (isset($this->_error) && strlen($this->_error)) {
	?>
	<div class="alert alert-error"><?php echo ents($this->_error); ?></div>
	<?php
}
?>
<form method="post" class="well" action="<?php echo build_url(Array('view' => '_edit_me')); ?>">
	<input type="hidden" name="member_edit_sms_send" value="1" />
	<button type="submit" class="btn">Send code</button>
</form>
<?php if ($challenge !== NULL) { ?>
<form method="post" class="well" action="<?php echo build_url(Array('view' => '_edit_me')); ?>">
	<label for="member-edit-sms-code">Verification code</label>
	<input type="text" id="member-edit-sms-code" name="member_edit_sms_code"
		inputmode="numeric" autocomplete="one-time-code"
		pattern="\d{6}" maxlength="6" required="required" />
	<input type="hidden" name="member_edit_sms_verify" value="1" />
	<button type="submit" class="btn btn-primary">Verify code</button>
</form>
<?php } ?>
<p><i>If you no longer have access to the mobile number on your account, please contact your system administrator.</i></p>
