<?php
/**
 * @var array<string, mixed> $reset_user Current validated account/reset row.
 * @var array{userid: int, resethash: string, username: string, first_name: string, sms_verified?: string} $reset_state
 * @var bool $reset_sms_verified
 */
$sms_gate = !empty($reset_user['resetsmsrequired']) && !$reset_sms_verified;
$code_sent = !empty($reset_user['resetsmshash']) && !empty($reset_user['reset_sms_valid']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<?php include 'head.template.php' ?>
</head>
<body id="login">
	<form method="post" action="<?php echo ents(build_url(Array('*' => NULL))); ?>" id="login-box" class="well">
		<?php
		require_once 'include/size_detector.class.php';
		SizeDetector::printFormFields();
		?>
		<div id="login-header">
			<h1><span>Jethro PMM </span> <?php echo ents(SYSTEM_NAME); ?></h1>
		</div>
		<div id="login-body" class="form-horizontal">
			<h3><?php echo $sms_gate ? 'Verify your mobile' : 'Choose a new password'; ?></h3>
			<?php
			dump_messages();
			if (!empty($this->_error)) {
				echo '<div class="alert alert-error" role="alert">'.ents($this->_error).'</div>';
			}
			?>
			<p>Hi <?php echo ents($reset_state['first_name']); ?>, you are resetting the password for user account <b><?php echo ents($reset_state['username']); ?></b>.</p>
			<?php if ($sms_gate) { ?>
				<p>Step 1 of 2: verify the mobile number on your account before choosing a new password.</p>
				<?php if ($code_sent) { ?>
				<div class="control-group">
					<label class="control-label" for="reset_sms_code">SMS code</label>
					<div class="controls">
						<input type="text" name="reset_sms_code" id="reset_sms_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required aria-describedby="reset_sms_help" />
						<p class="help-inline" id="reset_sms_help">Enter the six-digit code sent to your mobile. Codes expire after 10 minutes.</p>
					</div>
				</div>
				<div class="control-group">
					<input type="hidden" name="reset_sms_verify" value="1" />
					<input type="submit" class="btn" value="Verify code" />
					<button type="submit" class="btn" name="reset_sms_send" value="1" formnovalidate>Resend code</button>
				</div>
				<?php } else { ?>
				<p>Request a verification code by SMS. You can request a code at most once every 60 seconds.</p>
				<button type="submit" class="btn" name="reset_sms_send" value="1">Send code</button>
				<?php } ?>
			<?php } else { ?>
			<?php if (!empty($reset_user['resetsmsrequired'])) { ?>
				<p>Step 2 of 2: your mobile has been verified. Choose your new password.</p>
			<?php } ?>
			<?php
			$sm = new Staff_Member();
			$sm->printFieldInterface('password', 'new_');
			?>
			<div class="control-group text-right">
				<input type="hidden" name="reset_password_submitted" value="1" />
				<input type="submit" class="btn" value="Set new password" />
			</div>
			<?php } ?>
			<hr />
			<a class="clickable" href="<?php echo build_url(Array('*' => NULL, 'reset_cancel' => 1)); ?>">Cancel and return to login</a>
		</div>
	</form>
</body>
</html>
