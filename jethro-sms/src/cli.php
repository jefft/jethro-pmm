<?php

/**
 * CLI core for the jethro-sms engine — the package's end product.
 *
 * All argument parsing, action dispatch and output rendering lives here,
 * parameterised by a {@see CliEnvironment} so different hosts can supply
 * their own provider chain and recipient semantics:
 *
 *  - bin/jethro-sms (standalone): pure chain built from SMS_* constants,
 *    phone-number recipients only, no DB logging.
 *  - Jethro's scripts/sms.php (wrapper): full bridge chain (DB logging,
 *    token expansion), person-ID recipients, export-smslog extra action.
 *
 * Extracted from Jethro's scripts/sms.php — see docs/extraction.md §5.
 */

namespace Sms\Cli;
use Sms\PhoneNumber;
use Sms\SenderID;


/**
 * CLI entry point: dispatch $argv[1] to a handler and exit.
 */
function main(array $argv, CliEnvironment $env): never
{
	$args = $argv;
	array_shift($args); // Remove script path

	if (count($args) < 1) {
		printUsage($env);
		exit(1);
	}

	if (isset($env->extraActions[$args[0]])) {
		($env->extraActions[$args[0]])($args);
		exit(0);
	}

	$known = array_merge(
		['sms', 'senderids', 'sendernums', 'balance', 'smsinfo', 'cancel',
		 'register-sender-number', 'validate-sender-number', 'register-sender-id',
		 'optouts', 'remove-optout', 'info', 'list'],
		array_keys($env->extraActions),
	);

	match ($args[0]) {
		'sms'                    => handleSms($env, $args),
		'senderids'              => handleSenderIds($env),
		'sendernums'             => handleSenderNums($env),
		'balance'                => handleBalance($env),
		'smsinfo'                => handleSmsInfo($env, $args),
		'cancel'                 => handleCancel($env, $args),
		'register-sender-number' => handleRegisterSenderNumber($env, $args),
		'validate-sender-number' => handleValidateSenderNumber($env, $args),
		'register-sender-id'     => handleRegisterSenderId($env, $args),
		'optouts'                => handleOptOuts($env),
		'remove-optout'          => handleRemoveOptOut($env, $args),
		'info'                   => handleInfo($env),
		'list'                   => handleList($env, $args),
		default                  => exitWithError("Unknown action '{$args[0]}'. Must be one of: " . implode(', ', $known) . ".\n"),
	};
	exit(0);
}

// ===========================================================================
// Shared helpers
// ===========================================================================

/**
 * Parse --key=value pairs from an argument array, populating $opts and collecting
 * positional arguments into $positional.
 *
 * @param string[]                $args        Argument list ($argv with script + action already stripped)
 * @param array<string, string[]> &$opts       Out: key => value[] map (supports repeated --to, etc.)
 * @param string[]                &$positional Out: positional arguments (not starting with --)
 */
function parseArgs(array $args, array &$opts, array &$positional): void
{
	foreach ($args as $arg) {
		if (str_starts_with($arg, '--')) {
			$eq = strpos($arg, '=');
			if ($eq !== false) {
				$key = substr($arg, 2, $eq - 2);
				$val = substr($arg, $eq + 1);
				$opts[$key][] = $val;
			}
		} else {
			$positional[] = $arg;
		}
	}
}

/**
 * Get the configured SMS provider from the host factory or exit with an error.
 *
 * When the failure is "not configured" (see \Sms\resolveRawProviderClass()),
 * append plain-text per-provider constant help.
 */
function requireProvider(CliEnvironment $env, bool $logToDb = true): \Sms\SmsProvider
{
	$providerResult = ($env->providerFactory)($logToDb);
	if ($providerResult->isFailure()) {
		$error = $providerResult->getError();
		if (\is_array($error) && !empty($error['notConfigured'])) {
			exitWithError(($error['message'] ?? 'SMS not configured.') . "\n" . renderCandidateHelpText());
		}
		exitWithError(\is_array($error) ? ($error['message'] ?? 'SMS not configured.') : $error);
	}
	return $providerResult->getValue();
}

/**
 * Plain-text version of the "not configured" provider/constant help
 * (the Jethro bridge renders the same data as HTML for the web UI).
 */
function renderCandidateHelpText(): string
{
	$out = "Set SMS_PROVIDER or define the required constants for one of these providers:\n";
	foreach (\Sms\providerCandidates() as [$candidate, $label]) {
		if ($candidate::usagePreference() < 0) {
			continue;
		}
		$out .= "\n  $label\n";
		foreach ($candidate::getConstants() as [$key, $required, $purpose]) {
			$defined = (string) ifdef($key, '') !== '';
			$out .= sprintf("    [%s] %-28s %-8s  %s\n", $defined ? 'set' : ' - ', $key, $required, $purpose);
		}
	}
	return $out;
}

/**
 * Print error to stderr and exit.
 */
function exitWithError(string $msg): never
{
	fwrite(STDERR, "Error: $msg\n");
	exit(1);
}

/**
 * Resolve a raw sender string to a SmsSender (PhoneNumber or SenderID).
 */
function resolveSender(string $raw): \Sms\SmsSender
{
	if (preg_match('/^\d+$/', $raw)) {
		return new PhoneNumber($raw);
	}
	return new SenderID($raw);
}

/**
 * Resolve one --to argument to a recipient, or null to skip it (a warning
 * has already been printed). Delegates to the host resolver when present;
 * standalone accepts phone numbers only.
 */
function resolveRecipient(CliEnvironment $env, string $toArg): ?\Sms\SmsRecipient
{
	if ($env->recipientResolver !== null) {
		return ($env->recipientResolver)($toArg);
	}
	if (ctype_digit($toArg) && strlen($toArg) <= 6) {
		exitWithError("Recipient '$toArg' looks like a Jethro person ID — person-ID recipients require the Jethro wrapper (scripts/sms.php); pass a full mobile number instead.");
	}
	return new PhoneNumber($toArg);
}

// ===========================================================================
// Handlers
// ===========================================================================

function handleSms(CliEnvironment $env, array $args): void
{
	// Skip action, parse remaining
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$from = $opts['from'][0] ?? null;
	$to   = $opts['to'] ?? [];
	$sendAtRaw = $opts['sendat'][0] ?? null;
	$sendAt = $sendAtRaw !== null ? (int) $sendAtRaw : null;
	$messageText = implode(' ', $pos);

	if ($from === null) {
		exitWithError("--from=<sender> is required for 'sms' action.\n");
	}
	if ($to === []) {
		exitWithError("--to=<recipient> is required for 'sms' action.\n");
	}
	if ($messageText === '') {
		exitWithError("<message> is required for 'sms' action.\n");
	}

	$sender = resolveSender($from);

	$recipients = [];
	foreach ($to as $toArg) {
		$r = resolveRecipient($env, $toArg);
		if ($r !== null) {
			$recipients[] = $r;
		}
	}

	if ($recipients === []) {
		exitWithError('No valid recipients.');
	}

	echo 'Sending SMS to ' . count($recipients) . " recipient(s)...\n";

	// Host-resolved (person) recipients get DB logging; raw phone numbers don't.
	$hasPersonIds = false;
	foreach ($recipients as $r) {
		if (!$r instanceof PhoneNumber) {
			$hasPersonIds = true;
			break;
		}
	}
	$provider = requireProvider($env, logToDb: $hasPersonIds);

	$result = $provider->send(
		entries: [['message' => $messageText, 'recipients' => $recipients]],
		sender: $sender,
		sendAt: $sendAt,
	);

	if ($result->isFailure()) {
		exitWithError($result->getError());
	}

	/** @var \Sms\SmsDelivery[] $results */
	$results = $result->getValue()->deliveries;
	$summary = \Sms\sendSummary($results, $recipients);
	if ($summary instanceof \Sms\Failed) {
		exitWithError($summary->error);
	}

	match (true) {
		$summary instanceof \Sms\AllSent => printf(
			"Sent successfully to %d recipient(s).\n",
			count($summary->recipients),
		),
		$summary instanceof \Sms\PartialSuccess => printf(
			"Sent to %d recipient(s), failed for %d.\n",
			count($summary->successes),
			count($summary->failures),
		),
		default => printf("Unexpected send result type: %s\n", $summary::class),
	};

	exit(0);
}

function handleSenderIds(CliEnvironment $env): void
{
	$provider = requireProvider($env);

	$result = $provider->getSenderIds(getAll: true);
	if ($result->isFailure()) {
		exitWithError('Failed to fetch sender IDs: ' . $result->getError());
	}

	$senderIds = $result->getValue();

	if ($senderIds === []) {
		echo "No registered sender IDs found.\n";
	} else {
		echo "Sender IDs:\n";
		foreach ($senderIds as $senderId) {
			echo '  ' . $senderId->value . "\n";
		}
	}
	exit(0);
}

function handleSenderNums(CliEnvironment $env): void
{
	$provider = requireProvider($env);

	$result = $provider->getSenderNumbers();
	if ($result->isFailure()) {
		exitWithError('Failed to fetch sender numbers: ' . $result->getError());
	}

	$numbers = $result->getValue();

	if ($numbers === []) {
		echo "No registered sender numbers found.\n";
	} else {
		echo "Registered sender numbers:\n";
		foreach ($numbers as $number) {
			echo '  ' . $number->value . "\n";
		}
	}
	exit(0);
}

function handleBalance(CliEnvironment $env): void
{
	$provider = requireProvider($env);

	$result = $provider->getBalance();
	if ($result->isFailure()) {
		exitWithError('Could not retrieve account balance: ' . $result->getError());
	}

	echo 'Account balance: ' . $result->getValue() . "\n";
	exit(0);
}

function handleInfo(CliEnvironment $env): void
{
	$provider = requireProvider($env);

	// Walk decorator chain to the inner concrete provider for static metadata methods
	$inner = $provider;
	while ($inner instanceof \Sms\DecoratingSmsProvider) {
		$inner = $inner->getInner();
	}

	echo 'Provider: ' . $provider->getDescription() . "\n";
	if ($env->extraInfoLines !== null) {
		foreach (($env->extraInfoLines)() as $label => $value) {
			echo "$label: $value\n";
		}
	}
	echo "\n";

	$constants = $inner::getConstants();
	if ($constants === []) {
		echo "No constants defined by this provider.\n";
	} else {
		$maxLen = max(array_map('strlen', array_column($constants, 0)));
		foreach ($constants as [$key, $required]) {
			$value = defined($key) ? var_export(constant($key), true) : '<not defined>';
			printf("  %-{$maxLen}s  %-10s  %s\n", $key, $required, $value);
		}
	}
	exit(0);
}

function handleSmsInfo(CliEnvironment $env, array $args): void
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$id = $opts['id'][0] ?? null;
	if ($id === null) {
		exitWithError("--id=<remote_ref> is required for 'smsinfo' action.\n");
	}

	$provider = requireProvider($env);

	// Construct a minimal delivery for the provider to query
	$delivery = new \Sms\SmsDelivery(
		recipient: new \Sms\PhoneNumber('0000000000'),
		status: \Sms\SmsStatus::SENDING,
		remoteId: $id,
	);

	$result = $provider->updateDelivery($delivery);
	if ($result->isFailure()) {
		exitWithError('Could not retrieve SMS info: ' . $result->getError());
	}

	/** @var \Sms\SmsDelivery $updated */
	$updated = $result->getValue();

	echo "Status:     " . $updated->statusText() . " (#" . $updated->status()->value . ")\n";
	if ($updated->sendTimestamp() !== null) {
		echo "Sent:       " . date('Y-m-d H:i:s', $updated->sendTimestamp()) . "\n";
	}
	if ($updated->deliveryTimestamp() !== null) {
		echo "Delivered:  " . date('Y-m-d H:i:s', $updated->deliveryTimestamp()) . "\n";
	}
	exit(0);
}

function handleCancel(CliEnvironment $env, array $args): void
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$id = $opts['id'][0] ?? null;
	if ($id === null) {
		exitWithError("--id=<remote_ref> is required for 'cancel' action.\n");
	}

	echo "Cancelling SMS $id...\n";

	// Wrap the single synthetic delivery (fake recipient — DB-less debug tool)
	// in a batch so the cancel hook can accept it.
	$delivery = new \Sms\SmsDelivery(
		recipient: new \Sms\PhoneNumber('0000000000'),
		status: \Sms\SmsStatus::SCHEDULED,
		remoteId: $id,
	);
	$batch = new \Sms\SmsDeliveryBatch(null, [$delivery]);

	$result = $env->cancel !== null
		? ($env->cancel)($batch)
		: requireProvider($env, logToDb: false)->cancel($batch);
	if ($result->isFailure()) {
		exitWithError('Could not cancel SMS: ' . $result->getError());
	}

	/** @var \Sms\SmsDeliveryBatch $cancelledBatch */
	$cancelledBatch = $result->getValue();
	$cancelled = $cancelledBatch->deliveries[0];

	echo "Status:     " . $cancelled->statusText() . " (#" . $cancelled->status()->value . ")\n";
	exit(0);
}

function handleRegisterSenderNumber(CliEnvironment $env, array $args): void
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$phoneNumber = $opts['phonenumber'][0] ?? null;
	$label       = $opts['label'][0] ?? null;

	$provider = requireProvider($env, logToDb: true);

	if (!$provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_NUMBER)) {
		echo "This API provider lacks the ability to register Sender Numbers.\n";
		exit(0);
	}

	if ($phoneNumber === null) {
		exitWithError("--phonenumber=<number> is required for 'register-sender-number' action.\n");
	}

	$contact = new \Sms\ContactPhoneNumber(new \Sms\PhoneNumber($phoneNumber), $label ?? 'CLI registration');

	echo "Registering phone number {$contact->phoneNumber->value} as a sender...\n";

	$result = $provider->registerSenderNumber($contact, null);

	if ($result->isFailure()) {
		exitWithError('Registration failed: ' . $result->getError());
	}

	$step = $result->getValue();
	echo $step->message . "\n";

	// Show validation fields if needed
	if ($step->fields !== []) {
		echo "\nComplete verification with 'validate-sender-number' providing these fields:\n";
		foreach ($step->fields as $f) {
			if ($f->type !== 'hidden') {
				echo '  --' . $f->name . '=<value>  ' . $f->label . ($f->required ? ' (required)' : '') . "\n";
			}
		}
	}

	exit(0);
}

/**
 * Validate (complete verification of) a registered sender phone number.
 *
 * Submits the provider-specific validation fields (e.g. OTP code for
 * Cellcast) to complete the sender number registration.
 */
function handleValidateSenderNumber(CliEnvironment $env, array $args): void
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$phoneNumber = $opts['phonenumber'][0] ?? null;
	if ($phoneNumber === null) {
		exitWithError("--phonenumber=<number> is required for 'validate-sender-number' action.\n");
	}

	$provider = requireProvider($env, logToDb: true);

	if (!$provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_NUMBER)) {
		echo "This API provider lacks the ability to register Sender Numbers.\n";
		exit(0);
	}

	$contact = new \Sms\ContactPhoneNumber(new \Sms\PhoneNumber($phoneNumber), $opts['label'][0] ?? 'CLI registration');

	// Collect validation params (exclude phonenumber and label — they're in $contact)
	$params = [];
	foreach ($opts as $key => $values) {
		if ($key !== 'phonenumber' && $key !== 'label') {
			$params[$key] = $values[0];
		}
	}

	if ($params === []) {
		// No params — show the schema
		$result = $provider->registerSenderNumber($contact, null);
		if ($result->isFailure()) {
			exitWithError($result->getError());
		}
		$step = $result->getValue();

		if ($step->fields !== []) {
			echo "\nRequired fields:\n";
			foreach ($step->fields as $f) {
				if ($f->type !== 'hidden') {
					echo '  --' . $f->name . '=<value>  ' . $f->label . ($f->required ? ' (required)' : '') . "\n";
				}
			}
		} else {
			echo "\nNo validation fields required.\n";
		}
		exit(0);
	}

	// Submit validation
	$result = $provider->registerSenderNumber($contact, $params);

	if ($result->isFailure()) {
		exitWithError('Validation failed: ' . $result->getError());
	}

	$step = $result->getValue();
	echo renderRegistrationStepText($step);

	exit(0);
}

function handleRegisterSenderId(CliEnvironment $env, array $args): void
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$provider = requireProvider($env, logToDb: true);

	if (!$provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_ID)) {
		echo "This API provider lacks the ability to register Sender IDs.\n";
		exit(0);
	}

	$senderIdStr = $opts['senderid'][0] ?? '';
	if ($senderIdStr === '') {
		exitWithError('--senderid is required');
	}
	$senderId = new \Sms\SenderID($senderIdStr);

	// Phase 1: Get the field schema (may create the sender ID upstream)
	$schemaResult = $provider->registerSenderId($senderId, null);
	if ($schemaResult->isFailure()) {
		exitWithError($schemaResult->getError());
	}
	$schema = $schemaResult->getValue();
	$fields = $schema->fields;

	// Collect known field params from opts (excluding senderid, already consumed)
	$knownNames = array_map(fn($f) => $f->name, $fields);
	$params = [];
	foreach ($knownNames as $name) {
		if ($name === 'senderid') continue;
		if (isset($opts[$name])) {
			$params[$name] = $opts[$name][0];
		}
	}

	// No validation params → print help with required fields
	if ($params === []) {
		echo "Sender ID registration requires the following fields:\n\n";
		foreach ($fields as $f) {
			$req = $f->required ? ' (required)' : ' (optional)';
			$val = $f->value !== null ? ' [default: ' . $f->value . ']' : '';
			echo "  --{$f->name}={$f->label}{$req}{$val}\n";
			if ($f->description !== null && $f->description !== '') {
				echo '    ' . $f->description . "\n";
			}
			if ($f->type === 'select' && $f->options !== null) {
				echo '    Options: ' . implode(', ', $f->options) . "\n";
			}
			if ($f->type === 'checkbox') {
				echo "    Pass --{$f->name}=1 to enable\n";
			}
		}
		exit(0);
	}

	echo "Registering sender ID...\n";
	$result = $provider->registerSenderId($senderId, $params);

	if ($result->isFailure()) {
		exitWithError($result->getError());
	}

	$step = $result->getValue();
	echo renderRegistrationStepText($step);
	exit(0);
}

/**
 * Render a sender registration result (registerSenderId()/registerSenderNumber()
 * phase 2) as plain text for the CLI.
 *
 * Mirrors the web renderer Jethro\Sms\renderRegistrationStepHtml(): the
 * providers return structured data (message, instructions, contact, form) and
 * each presentation layer renders it.
 */
function renderRegistrationStepText(\Sms\RegistrationStep $step): string
{
	$out = '';

	if ($step->message !== '') {
		$out .= $step->message . "\n";
	}

	if ($step->instructions !== '') {
		$out .= "\n" . $step->instructions . "\n";
	}

	if ($step->contact !== '') {
		$out .= '  ' . $step->contact . "\n";
	}

	if ($step->form !== []) {
		$width = 0;
		foreach ($step->form as $row) {
			$width = max($width, strlen((string) ($row['label'] ?? '')));
		}
		$out .= "\n";
		foreach ($step->form as $row) {
			$out .= '  ' . str_pad((string) ($row['label'] ?? '') . ':', $width + 2)
				. (string) ($row['value'] ?? '') . "\n";
		}
	}

	return $out !== '' ? $out : "Done.\n";
}

// ===========================================================================
// Opt-out handlers
// ===========================================================================

function handleOptOuts(CliEnvironment $env): void
{
	$provider = requireProvider($env, logToDb: false);

	if (!$provider->hasCapability(\Sms\SmsCapability::LIST_OPT_OUTS)) {
		echo "This API provider does not support listing opt-outs.\n";
		exit(0);
	}

	$result = $provider->listOptOuts();
	if ($result->isFailure()) {
		exitWithError('Failed to list opt-outs: ' . $result->getError());
	}

	$entries = $result->getValue();

	if ($entries === []) {
		echo "No opted-out numbers found.\n";
	} else {
		echo "Opted-out numbers (" . count($entries) . "):\n";
		foreach ($entries as $entry) {
			$line = '  ' . $entry->number->value;
			if ($entry->name !== null) {
				$line .= '  (' . $entry->name . ')';
			}
			if ($entry->optedOutAt !== null) {
				$line .= '  opted out ' . date('Y-m-d H:i:s', $entry->optedOutAt);
			}
			echo $line . "\n";
		}
	}
	exit(0);
}

function handleRemoveOptOut(CliEnvironment $env, array $args): void
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$number = $opts['number'][0] ?? null;
	if ($number === null) {
		exitWithError("--number=<phone_number> is required for 'remove-optout' action.\n");
	}

	$provider = requireProvider($env, logToDb: false);

	if (!$provider->hasCapability(\Sms\SmsCapability::REMOVE_OPT_OUT)) {
		echo "This API provider does not support removing opt-outs.\n";
		exit(0);
	}

	$phoneNumber = new \Sms\PhoneNumber($number);
	echo "Removing {$phoneNumber->value} from opt-out list...\n";

	$result = $provider->removeOptOut($phoneNumber);
	if ($result->isFailure()) {
		exitWithError('Failed to remove opt-out: ' . $result->getError());
	}

	echo "Done.\n";
	exit(0);
}


function handleList(CliEnvironment $env, array $args): void
{
	$opts = $pos = [];
	parseArgs(array_slice($args, 1), $opts, $pos);

	$sinceRaw = $opts['since'][0] ?? null;
	$since = $sinceRaw !== null ? (int) $sinceRaw : null;

	$provider = requireProvider($env, logToDb: false);

	$result = $provider->listRecentDeliveries($since);
	if ($result->isFailure()) {
		exitWithError('Failed to list recent deliveries: ' . $result->getError());
	}

	/** @var \Sms\SmsDelivery[] $deliveries */
	$deliveries = $result->getValue();

	if ($deliveries === []) {
		$range = $since !== null ? ' since ' . date('Y-m-d H:i:s', $since) : ' in the last 24 hours';
		echo "No deliveries found{$range}.\n";
	} else {
		$range = $since !== null ? ' since ' . date('Y-m-d H:i:s', $since) : ' (last 24 hours)';
		echo "Recent deliveries{$range} — " . count($deliveries) . " result(s):\n\n";
		foreach ($deliveries as $d) {
			echo '  ' . \Sms\formatDeliveryLine($d) . "\n";
		}
	}
	exit(0);
}

// ===========================================================================
// Usage
// ===========================================================================

function printUsage(CliEnvironment $env): void
{
	$prog = $env->usageEnvPrefix . $env->usageProgram;

	$senderNumberNote = '';
	$senderIdBlock    = '';
	$provider         = null;
	try {
		$providerResult = ($env->providerFactory)(false);
		if ($providerResult->isSuccess()) {
			$provider = $providerResult->getValue();

			if ($provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_ID)) {
				$schemaResult = $provider->registerSenderId();
				$step = $schemaResult->isSuccess() ? $schemaResult->getValue() : null;
				$fields = $step !== null ? $step->fields : [];
				if ($fields !== []) {
					$lines = [];
					foreach ($fields as $f) {
						$req = $f->required ? ' (required)' : ' (optional)';
						$lines[] = "                        --{$f->name}=...{$req}";
					}
					$senderIdBlock = "\n                           Pass with no extra args to see required fields,\n                           then pass the fields below to submit the registration:\n" . implode("\n", $lines);
				}
			} else {
				$senderIdBlock = "\n                           This API provider lacks the ability to register Sender IDs.";
			}

			if (!$provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_NUMBER)) {
				$senderNumberNote = "\n                           This API provider lacks the ability to register Sender Numbers.";
			}
		}
	} catch (\Throwable) {
		// Provider not available — leave help sections empty
	}

	$senderNumberArgs = $senderNumberNote === ''
		? "\n                           Required: --phonenumber=<number>\n                           Optional: --label=<name>  (human-readable label)"
		: '';

	$validateNumberNote = '';
	if ($senderNumberNote === '' && $provider !== null) {
		// Show provider-specific validation fields
		$schemaResult = $provider->registerSenderNumber();
		$step   = $schemaResult->isSuccess() ? $schemaResult->getValue() : null;
		$fields = $step?->fields ?? [];
		$message = $step?->message ?? null;
		if ($message !== null && $message !== '') {
			$validateNumberNote = "\n                           " . str_replace("\n", "\n                           ", $message);
		}
		if ($fields !== []) {
			$lines = [];
			foreach ($fields as $f) {
				if ($f->type !== 'hidden') {
					$req = $f->required ? ' (required)' : '';
					$lines[] = "                        --{$f->name}=...{$req}";
				}
			}
			$validateNumberNote .= "\n                           Required fields:\n" . implode("\n", $lines);
		}
	} elseif ($senderNumberNote !== '') {
		$validateNumberNote = "\n                           This API provider lacks the ability to register Sender Numbers.";
	}

	$extraActionLines = '';
	foreach ($env->extraActions as $name => $_) {
		$extraActionLines .= "\n  $name\n";
	}

	echo <<<USAGE
Usage: {$prog} <action> [options] [message]

Actions:
  sms         Send an SMS message
              Required: --from=<sender> --to=<recipient> <message>
              Optional: --sendat=<unix_timestamp>  (deferred delivery,
                  e.g. --sendat=\$(date -d '+2 hours' +%s))
              --to can be specified multiple times

  senderids   List registered sender IDs

  sendernums  List registered sender numbers (phone numbers)

  balance     Check the current account balance

  info        Show provider description and current constant values

  smsinfo     Get delivery status for a previously sent SMS
              Required: --id=<remote_ref>

  cancel      Cancel a previously sent (scheduled/deferred) SMS
              Required: --id=<remote_ref>


  list        List recent deliveries (batch status query)
              Optional: --since=<unix_timestamp>  (default: last 24 hours,
                  e.g. --since=\$(date -d '-2 days' +%s))

  optouts     List opted-out / unsubscribed phone numbers

  remove-optout  Remove a phone number from the opt-out list
                 Required: --number=<phone_number>

  register-sender-number   Register a phone number for use as a sender{$senderNumberNote}{$senderNumberArgs}

  validate-sender-number   Complete verification of a registered sender number{$validateNumberNote}

  register-sender-id       Register a sender ID (business identity){$senderIdBlock}
{$extraActionLines}
Examples:
  {$prog} sms --from=Jethro --to=0491570159 "Meeting at 7pm"
  {$prog} sms --from=Jethro --to=0491570159 --to=0491570158 "Reminder"
  {$prog} sms --from=Jethro --to=0491570159 --sendat=1717000000 "Deferred message"
  {$prog} senderids
  {$prog} sendernums
  {$prog} optouts
  {$prog} remove-optout --number=61414972051
  {$prog} info
  {$prog} smsinfo --id=6a1439267bbc1d12b20627ea
  {$prog} list
  {$prog} list --since=\$(date -d '-2 days' +%s)
  {$prog} cancel --id=6a17f61098ec8240f4060994

USAGE;
}
