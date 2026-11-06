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
			<h3>Forgot your username?</h3>
			<?php
			dump_messages();
			if (!empty($this->_error)) {
				echo '<div class="alert alert-error">'.$this->_error.'</div>';
			}
			?>
			<p>Enter the email address on your user account and we will email you your username(s).</p>
			<div class="control-group">
				<label class="control-label" for="forgot_username_email">Email address</label>
				<div class="controls">
					<input type="text" autofocus="autofocus" name="forgot_username_email" id="forgot_username_email" autocomplete="email" />
				</div>
			</div>
			<div class="control-group">
				<div class="controls">
					<input type="submit" value="Send username reminder" class="btn" />
				</div>
			</div>
			<hr />
			<a class="clickable" href="<?php echo build_url(Array('*' => NULL)); ?>">Back to login</a><br />
			<a class="clickable" href="<?php echo build_url(Array('*' => NULL, 'forgot_password' => 1)); ?>">Know your username but forgot your password?</a>
		</div>
	</form>
</body>
</html>
