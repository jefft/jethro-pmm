<?php

/**
 * Unit tests for the CLI presenters.
 *
 * Pins:
 *   - TextPresenter output byte-for-byte (the pre-refactor CLI text), so
 *     scripts and muscle memory keep working;
 *   - JsonPresenter envelope and per-action payload shapes (`--json`);
 *   - note handling (informational, exit 0) and failure envelopes;
 *   - credential masking in `info`.
 */

namespace Test\Sms\CliPresenters;

use function \Test\{test, assert_eq, assert_true, assert_contains};
use \Sms\{PhoneNumber, SenderID, OptOutEntry, RegistrationStep, FormField, SmsStatus, SmsDelivery};
use \Sms\Cli\{CliOutcome, TextPresenter, JsonPresenter};

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function capture(callable $fn): string
{
	ob_start();
	try {
		$fn();
	} finally {
		$out = (string) ob_get_clean();
	}
	return $out;
}

function renderText(CliOutcome $outcome): string
{
	return capture(fn () => (new TextPresenter())->render($outcome));
}

/** @return array<string, mixed> */
function renderJson(CliOutcome $outcome): array
{
	$raw = capture(fn () => (new JsonPresenter())->render($outcome));
	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		throw new \RuntimeException('JsonPresenter emitted invalid JSON: ' . $raw);
	}
	return $decoded;
}

function delivery(string $status = 'SENT'): SmsDelivery
{
	return new SmsDelivery(
		recipient: new PhoneNumber('61400000001'),
		status: \constant('Sms\\SmsStatus::' . $status),
		remoteId: 'abc123',
		sendTimestamp: 1700000000,
		deliveryTimestamp: 1700000100,
		message: 'Hello',
	);
}

// ===========================================================================
// TextPresenter — output parity
// ===========================================================================

test('text: sender IDs, empty and populated', function () {
	assert_eq(
		renderText(CliOutcome::success('senderids', [])),
		"No registered sender IDs found.\n",
	);

	$out = renderText(CliOutcome::success('senderids', [
		new SenderID('MYCHURCH', true),
		new SenderID('NEWID', false),
		new SenderID('UNKNOWN', null),
	]));
	assert_eq($out, "Sender IDs:\n"
		. "  MYCHURCH (approved)\n"
		. "  NEWID (not approved — hidden from senders)\n"
		. "  UNKNOWN\n");
});

test('text: sender numbers and balance', function () {
	assert_eq(renderText(CliOutcome::success('sendernums', [])), "No registered sender numbers found.\n");
	assert_eq(
		renderText(CliOutcome::success('sendernums', [new PhoneNumber('61400000001'), new PhoneNumber('61400000002')])),
		"Registered sender numbers:\n  61400000001\n  61400000002\n",
	);
	assert_eq(renderText(CliOutcome::success('balance', 1372)), "Account balance: 1372\n");
});

test('text: opt-outs', function () {
	assert_eq(renderText(CliOutcome::success('optouts', [])), "No opted-out numbers found.\n");

	$out = renderText(CliOutcome::success('optouts', [
		new OptOutEntry(new PhoneNumber('61400000001'), optedOutAt: 1700000000, name: 'Alex'),
		new OptOutEntry(new PhoneNumber('61400000002')),
	]));
	assert_contains($out, "Opted-out numbers (2):\n");
	assert_contains($out, "  61400000001  (Alex)  opted out " . date('Y-m-d H:i:s', 1700000000) . "\n");
	assert_contains($out, "  61400000002\n");
});

test('text: recent deliveries', function () {
	assert_eq(
		renderText(CliOutcome::success('list', ['deliveries' => [], 'since' => null])),
		"No deliveries found in the last 24 hours.\n",
	);
	assert_eq(
		renderText(CliOutcome::success('list', ['deliveries' => [], 'since' => 1700000000])),
		"No deliveries found since " . date('Y-m-d H:i:s', 1700000000) . ".\n",
	);

	$d = delivery();
	$out = renderText(CliOutcome::success('list', ['deliveries' => [$d], 'since' => null]));
	assert_eq($out, "Recent deliveries (last 24 hours) — 1 result(s):\n\n  " . \Sms\formatDeliveryLine($d) . "\n");
});

test('text: send summaries', function () {
	$recipients = [new PhoneNumber('61400000001'), new PhoneNumber('61400000002')];
	$allSent = new \Sms\AllSent($recipients, 'r1');
	assert_eq(
		renderText(CliOutcome::success('sms', ['summary' => $allSent, 'deliveries' => []])),
		"Sent successfully to 2 recipient(s).\n",
	);

	$partial = new \Sms\PartialSuccess(
		successes: [$recipients[0]],
		failures: [$recipients[1]],
		remoteId: 'r2',
	);
	assert_eq(
		renderText(CliOutcome::success('sms', ['summary' => $partial, 'deliveries' => []])),
		"Sent to 1 recipient(s), failed for 1.\n",
	);
});

test('text: delivery status block', function () {
	$out = renderText(CliOutcome::success('smsinfo', delivery()));
	assert_contains($out, "Status:     ");
	assert_contains($out, " (#" . SmsStatus::SENT->value . ")\n");
	assert_contains($out, "Sent:       " . date('Y-m-d H:i:s', 1700000000) . "\n");
	assert_contains($out, "Delivered:  " . date('Y-m-d H:i:s', 1700000100) . "\n");
});

test('text: registration step and field schema', function () {
	$step = new RegistrationStep(message: 'Verification link sent.', instructions: 'Click it.');
	assert_eq(
		renderText(CliOutcome::success('register-sender-number', ['step' => $step])),
		"Verification link sent.\n\nClick it.\n",
	);

	$withFields = new RegistrationStep(fields: [
		new FormField('otp', 'One-time code', 'text', required: true),
		new FormField('hidden_thing', 'Hidden', 'hidden'),
	]);
	$out = renderText(CliOutcome::success('register-sender-number', ['step' => $withFields]));
	assert_contains($out, "\nComplete verification with 'validate-sender-number' providing these fields:\n");
	assert_contains($out, "  --otp=<value>  One-time code (required)\n");
	assert_true(!str_contains($out, 'hidden_thing'), 'hidden fields are not listed');

	$validate = renderText(CliOutcome::success('validate-sender-number', ['step' => $withFields, 'mode' => 'schema']));
	assert_contains($validate, "\nRequired fields:\n  --otp=<value>  One-time code (required)\n");

	$validateEmpty = renderText(CliOutcome::success('validate-sender-number', [
		'step' => new RegistrationStep(),
		'mode' => 'submitted',
	]));
	assert_eq($validateEmpty, "\nNo validation fields required.\n");

	$schema = renderText(CliOutcome::success('register-sender-id', ['step' => $withFields, 'mode' => 'schema']));
	assert_contains($schema, "Sender ID registration requires the following fields:\n\n");
	assert_contains($schema, "  --otp=One-time code (required)\n");
});

test('text: delete/remove confirmations and notes', function () {
	assert_eq(
		renderText(CliOutcome::success('delete-sender-number', ['number' => '61400000001'])),
		"Done. The number is no longer registered with the provider.\n",
	);
	assert_eq(renderText(CliOutcome::success('remove-optout', ['number' => '61400000001'])), "Done.\n");
	assert_eq(
		renderText(CliOutcome::success('register-sender-number', null, 'This API provider lacks the ability to register Sender Numbers.')),
		"This API provider lacks the ability to register Sender Numbers.\n",
	);
});

test('text: info table', function () {
	$out = renderText(CliOutcome::success('info', [
		'provider' => 'Test (v1)',
		'extra'    => ['Configured' => 'Yes'],
		'constants' => [
			['key' => 'SMS_A', 'required' => 'required', 'defined' => true, 'value' => 'secret'],
			['key' => 'SMS_LONGER_NAME', 'required' => 'optional', 'defined' => false, 'value' => null],
		],
	]));
	assert_contains($out, "Provider: Test (v1)\nConfigured: Yes\n\n");
	assert_contains($out, sprintf("  %-15s  %-10s  %s\n", 'SMS_A', 'required', "'secret'"));
	assert_contains($out, sprintf("  %-15s  %-10s  %s\n", 'SMS_LONGER_NAME', 'optional', '<not defined>'));
});

// ===========================================================================
// JsonPresenter
// ===========================================================================

test('json: envelope for success, failure and note', function () {
	assert_eq(
		renderJson(CliOutcome::success('balance', 1372)),
		['ok' => true, 'action' => 'balance', 'data' => ['balance' => 1372]],
	);

	$failure = renderJson(CliOutcome::failure('balance', 'Unable to connect'));
	assert_eq($failure, ['ok' => false, 'action' => 'balance', 'error' => 'Unable to connect']);

	$note = renderJson(CliOutcome::success('optouts', null, 'This API provider does not support listing opt-outs.'));
	assert_eq($note['ok'], true);
	assert_eq($note['note'], 'This API provider does not support listing opt-outs.');
	assert_eq($note['data'], null);
});

test('json: sender IDs and numbers', function () {
	assert_eq(
		renderJson(CliOutcome::success('senderids', [new SenderID('MYCHURCH', true), new SenderID('X', null)]))['data'],
		[
			['value' => 'MYCHURCH', 'acmaApproved' => true],
			['value' => 'X', 'acmaApproved' => null],
		],
	);
	assert_eq(
		renderJson(CliOutcome::success('sendernums', [new PhoneNumber('61400000001')]))['data'],
		[['number' => '61400000001']],
	);
});

test('json: opt-outs and deliveries', function () {
	$optouts = renderJson(CliOutcome::success('optouts', [
		new OptOutEntry(new PhoneNumber('61400000001'), optedOutAt: 1700000000, name: 'Alex'),
	]))['data'];
	assert_eq($optouts, [['number' => '61400000001', 'name' => 'Alex', 'optedOutAt' => 1700000000]]);

	$list = renderJson(CliOutcome::success('list', ['deliveries' => [delivery()], 'since' => 1700000000]))['data'];
	assert_eq($list['since'], 1700000000);
	assert_eq(count($list['deliveries']), 1);
	assert_eq(
		array_keys($list['deliveries'][0]),
		['recipient', 'status', 'statusText', 'remoteId', 'sendTimestamp', 'deliveryTimestamp', 'message', 'statusDetail'],
	);
	assert_eq($list['deliveries'][0]['recipient'], '61400000001');
	assert_eq($list['deliveries'][0]['remoteId'], 'abc123');
});

test('json: send summary and registration step', function () {
	$recipients = [new PhoneNumber('61400000001')];
	$data = renderJson(CliOutcome::success('sms', [
		'summary' => new \Sms\AllSent($recipients, 'r1'),
		'deliveries' => [delivery()],
	]))['data'];
	assert_eq($data['summary'], ['type' => 'all_sent', 'recipients' => 1, 'remoteId' => 'r1']);
	assert_eq(count($data['deliveries']), 1);

	$partial = renderJson(CliOutcome::success('sms', [
		'summary' => new \Sms\PartialSuccess(successes: $recipients, failures: $recipients, remoteId: null),
		'deliveries' => [],
	]))['data'];
	assert_eq($partial['summary']['type'], 'partial');
	assert_eq($partial['summary']['sent'], 1);
	assert_eq($partial['summary']['failed'], 1);

	$step = renderJson(CliOutcome::success('register-sender-number', [
		'step' => new RegistrationStep(
			message: 'Verification link sent.',
			number: '61400000001',
			fields: [new FormField('otp', 'Code', 'text', required: true)],
		),
	]))['data'];
	assert_eq($step['message'], 'Verification link sent.');
	assert_eq($step['number'], '61400000001');
	assert_eq($step['complete'], false);
	assert_eq($step['fields'][0]['name'], 'otp');
	assert_eq($step['fields'][0]['required'], true);
});

test('json: info masks credential constants only', function () {
	$data = renderJson(CliOutcome::success('info', [
		'provider' => 'Test (v1)',
		'extra'    => ['Configured' => 'Yes'],
		'constants' => [
			['key' => 'SMS_5CENTSMS_APIKEY', 'required' => 'required', 'defined' => true, 'value' => 'super-secret'],
			['key' => 'SMS_5CENTSMS_URL', 'required' => 'optional', 'defined' => true, 'value' => 'https://api.example/v5'],
			['key' => 'SMS_VERBOSE', 'required' => 'optional', 'defined' => false, 'value' => null],
		],
	]))['data'];

	assert_eq($data['provider'], 'Test (v1)');
	assert_eq($data['constants'][0]['value'], '****');
	assert_eq($data['constants'][1]['value'], 'https://api.example/v5');
	assert_eq($data['constants'][2]['value'], null);
});

test('json: delete and remove confirmations', function () {
	assert_eq(
		renderJson(CliOutcome::success('delete-sender-number', ['number' => '61400000001']))['data'],
		['number' => '61400000001', 'deleted' => true],
	);
	assert_eq(
		renderJson(CliOutcome::success('remove-optout', ['number' => '61400000001']))['data'],
		['number' => '61400000001', 'removed' => true],
	);
});
