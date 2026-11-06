<h3>Verify your identity</h3>
<p>Editing your details requires a verification code, sent by SMS to the mobile number on your account (<?php echo ents($masked); ?>).</p>
<?php
if (isset($this->_error) && strlen($this->_error)) {
	?>
	<div class="alert alert-error"><?php echo ents($this->_error); ?></div>
	<?php
}
?>
<form method="post" action="<?php echo build_url(Array('view' => '_edit_me')); ?>">
	<input type="hidden" name="member_edit_sms_send" value="1" />
	<button type="submit" class="btn">Send code</button>
</form>
<hr>
<?php if ($challenge !== NULL) { ?>
<form method="post" class="well" action="<?php echo build_url(Array('view' => '_edit_me')); ?>">
	<div class="control-group">
		<label for="member-edit-sms-code">Verification code</label>
		<input type="text" id="member-edit-sms-code" name="member_edit_sms_code"
			inputmode="numeric" autocomplete="one-time-code"
			pattern="\d{6}" maxlength="6" required="required" />
	</div>
	<input type="hidden" name="member_edit_sms_verify" value="1" />
	<button type="submit" class="btn">Verify code</button>
</form>
<?php } ?>
<p><i>If your account has no mobile number, or you no longer have access to it, please contact
<?php if ($helpEmail = ifdef('MEMBER_REGO_HELP_EMAIL', '')) { ?>
	<a href="mailto:<?php echo ents($helpEmail); ?>"><?php echo ents($helpEmail); ?></a>
<?php } else { ?>
	your system administrator
<?php } ?>
for help.</i></p>
