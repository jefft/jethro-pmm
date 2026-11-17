<!DOCTYPE html>
<html lang="en">
<head>
	<?php include 'head.template.php' ?>
</head>
<body id="login">
	<form method="post" id="login-box" class="well">
		<?php
		require_once 'include/size_detector.class.php';
		SizeDetector::printFormFields();
		?>
		<div id="login-header">
			<h1><span>Jethro PMM </span> <?php echo ents(SYSTEM_NAME); ?></h1>
		</div>
		<div id="login-body" class="form-horizontal">
			<h3>Forgot your password?</h3>
			<?php
			dump_messages();
			if (!empty($this->_error)) {
				echo '<div class="alert alert-error">'.$this->_error.'</div>';
			}
			?>
			<p>Enter your username and we will email a reset link to the address on your account. Choosing a new password also requires a code sent by SMS to the mobile number on the account.</p>
			<div class="control-group">
				<label class="control-label" for="forgot_password_username">Username</label>
				<div class="controls">
					<input type="text" autofocus="autofocus" name="forgot_password_username" id="forgot_password_username" autocomplete="username" />
				</div>
			</div>
			<div class="control-group">
				<div class="controls">
					<input type="submit" value="Send reset link" class="btn" />
				</div>
			</div>
			<hr />
			<a class="clickable" href="<?php echo build_url(Array('*' => NULL)); ?>">Back to login</a><br />
			<a class="clickable" href="<?php echo build_url(Array('*' => NULL, 'forgot_username' => 1)); ?>">Forgot your username instead?</a>
		</div>
	</form>
</body>
</html>
