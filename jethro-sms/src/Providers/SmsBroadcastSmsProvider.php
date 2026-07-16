<?php

/**
 * SmsBroadcastSmsProvider — SMS provider for the smsbroadcast.com.au API.
 * https://smsbroadcast.com.au/wp-content/uploads/2023/01/Advanced-HTTP-API.pdf
 *
 * Uses URL-encoded POST requests to https://www.smsbroadcast.com.au/api-adv.php
 * with username/password authentication.  The API is line-based:
 *
 *   OK:<number>:<ref>    — per-recipient success
 *   BAD:<number>:<reason> — per-recipient failure
 *   ERROR:<reason>       — overall request error
 *
 * This file is a pure provider (no Jethro globals).  It is included from
 * include/jethro_sms.php.
 *
 * PHP constants read by fromConstants():
 *   SMS_SMSBROADCAST_USERNAME       — API username (required)
 *   SMS_SMSBROADCAST_PASSWORD       — API password (required)
 *   SMS_SMSBROADCAST_URL            — API endpoint URL (default https://www.smsbroadcast.com.au/api-adv.php)
 *   SMS_TESTMODE                    — bool; when true, uses a mock HTTP client that returns 'OK'
 *   SMS_VERBOSE                     — bool; logs request/response to PHP error log
 *   SMS_SENDER_OPTIONS                   — comma-separated sender options, may include _USER_MOBILE_ (overrides API discovery)
 *   SMS_BALANCE                     — manual balance override (skips API balance call)
 *
 * Sender override is handled by OverridingSmsProvider via SMS_SENDER.
 *
 * Test mode: when testMode is true, the httpClient is wrapped in a FakeHttpClient
 * which returns HttpResponse('OK') for send requests.  parseSendResponse() handles
 * 'OK' as "all recipients succeeded".  getBalance() and other methods are unaffected.
 *
 * When $tfa=true, 2FA_SMS_SMSBROADCAST_* constants are tried first for each field,
 * falling back to the standard SMS_SMSBROADCAST_* constant if the 2FA version is unset.
 *
 * To activate, set the SMS_PROVIDER constant in conf.php:
 *
 *   define('SMS_SMSBROADCAST_USERNAME', 'myuser');
 *   define('SMS_SMSBROADCAST_PASSWORD', 'mypass');
 *   define('SMS_PROVIDER', \Sms\SmsBroadcastSmsProvider::class);
 *
 * Alternatively, when SMS_PROVIDER is not defined, the presence of
 * SMS_SMSBROADCAST_USERNAME / SMS_SMSBROADCAST_PASSWORD triggers auto-detection.
 */

namespace Sms\Providers;
use Sms\ContactPhoneNumber;
use Sms\FakeHttpClient;
use Sms\HttpClient;
use Sms\HttpRequest;
use Sms\HttpResponse;
use Sms\LoggingHttpClient;
use Sms\NativeHttpClient;
use Sms\OverridingSmsProvider;
use Sms\PhoneNumber;
use Sms\SenderID;
use Sms\SmsCache;
use Sms\SmsCapability;
use Sms\SmsDelivery;
use Sms\SmsDeliveryBatch;
use Sms\SmsProvider;
use Sms\SmsRecipient;
use Sms\SmsSender;
use Sms\SmsStatus;


class SmsBroadcastSmsProvider implements SmsProvider
{
	private const CANONICAL_URL = 'https://www.smsbroadcast.com.au/api-adv.php';
	public function __construct(
		/** API username for authentication. */
		private string $username,
		/** API password for authentication. */
		private string $password,
		/** API endpoint URL. */
		private string $url = self::CANONICAL_URL,
		/** When true, forces an internal mock HttpClient (returns 'OK'). */
		private bool $testMode = false,
		/** When true the HTTP request and response are written to the PHP error log. */
		private bool $verbose = false,
		/** Comma-separated Sender IDs. When set, overrides API-discovered Sender IDs. */
		private string $senderIds = '',
		/** Manual balance override. When set, skips the API balance call. */
		private string $balance = '',
		/** Custom HTTP client (injected for testing). */
		private ?HttpClient $httpClient = null,
		private ?SmsCache $cache = null,
	) {}

	/**
	 * Create a SmsBroadcast provider from PHP constants.
	 *
	 * Reads SMS_SMSBROADCAST_* constants from conf.php.  When $tfa is true,
	 * 2FA_* prefixed constants are tried first for each field (2FA_SMS_SMSBROADCAST_USERNAME,
	 * 2FA_SMS_SMSBROADCAST_PASSWORD, 2FA_SMS_SMSBROADCAST_URL),
	 * falling back to the standard constant if the 2FA version is unset.
	 *
	 * @param bool $tfa  When true, reads 2FA_* constants first.
	 * @throws \RuntimeException when SMS_SMSBROADCAST_USERNAME or SMS_SMSBROADCAST_PASSWORD is missing
	 */
	public static function  fromConstants(bool $tfa = false): static
	{
		$username = $password = $url = '';

		if ($tfa) {
			$username = (string) ifdef('2FA_SMS_SMSBROADCAST_USERNAME', '');
			$password = (string) ifdef('2FA_SMS_SMSBROADCAST_PASSWORD', '');
			$url = (string) ifdef('2FA_SMS_SMSBROADCAST_URL', '');
		}

		if ($username === '') { $username = (string) ifdef('SMS_SMSBROADCAST_USERNAME', ''); }
		if ($password === '') { $password = (string) ifdef('SMS_SMSBROADCAST_PASSWORD', ''); }
		if ($url === '') { $url = rtrim((string) ifdef('SMS_SMSBROADCAST_URL', self::CANONICAL_URL), '/'); }

		if ($username === '' || $password === '') {
			throw new \RuntimeException(
				'Missing SMS configuration: '
				. implode(', ', array_filter([
					$username === '' ? 'SMS_SMSBROADCAST_USERNAME' : '',
					$password === '' ? 'SMS_SMSBROADCAST_PASSWORD' : '',
				]))
			);
		}

		return new static(
			username: $username,
			password: $password,
			url: $url,
			httpClient: static::createHttpClient(
				filter_var(ifdef('SMS_TESTMODE', false), FILTER_VALIDATE_BOOLEAN),
				filter_var(ifdef('SMS_VERBOSE', false), FILTER_VALIDATE_BOOLEAN),
			),
			senderIds: (string) ifdef('SMS_SENDER_OPTIONS', ''),
			balance: (string) ifdef('SMS_BALANCE', ''),
		);
	}

	public function withCache(SmsCache $cache): static
	{
		$clone = clone $this;
		$clone->cache = $cache;
		return $clone;
	}

	/**
	 * Create the HTTP client for SmsBroadcastSmsProvider.
	 *
	 * @param bool $testMode  When true, returns a fake HTTP client that returns 'OK'.
	 */
	public static function createHttpClient(bool $testMode, bool $verbose): HttpClient
	{
		if (!$testMode) {
			$client = new NativeHttpClient();
		} else {
			$client = new SmsBroadcastFakeHttpClient(new NativeHttpClient());
		}
		if ($verbose) {
			$client = new LoggingHttpClient($client);
		}
		return $client;
	}

	/**
	 * Get the current SMS account balance.
	 *
	 * SMS Broadcast's Advanced API does not expose a direct HTTP GET endpoint
	 * for balance queries.  Instead the balance is obtained by:
	 *
	 * 1. Config override (SMS_BALANCE constant) — trumps everything else.
	 * 2. Cache hit (session-scoped, populated after the first successful
	 *    send by parsing the balance line the API prepends to every send
	 *    response).
	 * 3. Fallback HTTP POST request to the balance action endpoint
	 *    (username/password/action=balance), which returns "OK:<credits>"
	 *    or "ERROR:<reason>".
	 *
	 * In a future version of Jethro, the balance could be updated via an
	 * HTTP callback (webhook) from SMS Broadcast instead of polling:
	 * https://support.smsbroadcast.com.au/hc/en-us/articles/4412014961423-Advanced-API-Documentation
	 */
	public function getBalance(): \Result
	{
		// 1. Config override (SMS_BALANCE constant)
		$hardcoded = trim($this->balance);
		if ($hardcoded !== '') {
			return \Result::success((int) $hardcoded);
		}

		// 2. Cache hit
		$cached = $this->cache?->get('sms_balance');
		if ($cached !== null) {
			return \Result::success((int) $cached);
		}

		// 3. Fetch from API
		$body = 'username=' . rawurlencode($this->username)
			  . '&password=' . rawurlencode($this->password)
			  . '&action=balance';

		$request = new HttpRequest(
			url: $this->url,
			method: 'POST',
			headers: "Content-Type: application/x-www-form-urlencoded\r\n"
				   . 'Content-Length: ' . \strlen($body) . "\r\n",
			body: $body,
			timeout: 5,
		);

		$response = $this->httpClient->send($request);

		if ($response->isFailure()) {
			return \Result::failure('Balance request failed: ' . $response->getError());
		}

		$balanceResult = $this->parseBalanceResponse($response->getValue());
		if ($balanceResult->isSuccess()) {
			$this->cache?->set('sms_balance', $balanceResult->getValue(), 300);
		}
		return $balanceResult;
	}

	public function isOperational(): \Result
	{
		$cached = $this->cache?->get('sms_operational');
		if ($cached !== null) {
			return \Result::success((bool) $cached);
		}

		$result = $this->getBalance();

		if ($result->isSuccess()) {
			$this->cache?->set('sms_operational', true, 300);
			return \Result::success(true);
		}

		$this->cache?->set('sms_operational', false, 60);
		return \Result::success(false);
	}

	/**
	 * Parse the SmsBroadcast balance response.
	 *
	 * Format: OK:<credits> or ERROR:<reason>
	 *
	 * @return \Result<int, string>
	 */
	private function parseBalanceResponse(HttpResponse $response): \Result
	{
		$body = trim($response->body);
		$parts = explode(':', $body, 2);

		if (\count($parts) < 2) {
			return \Result::failure('Unexpected balance response format. Raw: ' . $body);
		}

		if ($parts[0] === 'OK') {
			return \Result::success((int) $parts[1]);
		}

		if ($parts[0] === 'ERROR') {
			return \Result::failure($parts[1] . ' — raw: ' . $body);
		}

		return \Result::failure('Unknown balance response. Raw: ' . $body);
	}

	public function getSenderIds(bool $getAll = false): \Result
	{
		return \Result::success([]);
	}
	public function updateDelivery(\Sms\SmsDelivery $delivery): \Result
	{
		return \Result::failure('SMS delivery status is not available for this provider.');
	}

	/** Not supported by SmsBroadcast. */
	public function cancel(\Sms\SmsDeliveryBatch $batch): \Result
	{
		return \Result::failure('Cancelling SMS is not available for this provider.');
	}

	/** @return array<array{string, string, string}> */
	public static function getConstants(): array
	{
		return [
			['SMS_SMSBROADCAST_PASSWORD', 'required', 'API password'],
			['SMS_SMSBROADCAST_URL', 'optional', 'API endpoint URL (default: https://www.smsbroadcast.com.au/api-adv.php)'],
			['SMS_SENDER_OPTIONS', 'optional', 'Comma-separated sender options, may include _USER_MOBILE_ (overrides API discovery)'],
			['SMS_TESTMODE', 'optional', 'Dry-run without delivering'],
			['SMS_VERBOSE', 'optional', 'Log HTTP to error log'],
		];
	}

	public function getKey(): string
	{
		return 'smsbroadcast';
	}

	public static function usagePreference(): int
	{
        // SMS Broadcast has nothing much to recommend it vs. the competition. It works if configured,
        // but don't list it in configuration help
		return -1;
	}

	public function getDescription(): string
	{
		$desc = 'SMS Broadcast';
		if ($this->url !== self::CANONICAL_URL) {
			$desc .= ' (via ' . $this->url . ')';
		}
		return $desc;
	}

	/** @inheritDoc */
	public function getSenderNumbers(): \Result
	{
		return \Result::success([]);
	}

	/** @inheritDoc */
	public function verifySenderNumber(PhoneNumber $number): \Result
	{
		return \Result::success(true);
	}

	/** @inheritDoc */
	public function registerSenderNumber(?\Sms\ContactPhoneNumber $contact = null, ?array $validationParams = null): \Result
	{
		return \Result::failure('Not supported');
	}

	/** @inheritDoc */
	public function registerSenderId(?\Sms\SenderID $senderId = null, ?array $validationParams = null): \Result
	{
		return \Result::failure('Not supported');
	}

	public function hasCapability(\Sms\SmsCapability $cap): bool
	{
		return \in_array($cap, [
			\Sms\SmsCapability::GET_BALANCE,

			\Sms\SmsCapability::DEFERRED_SEND,
		], true);
	}

	public const SEGMENT_COST_MILLICENTS = 7000;

	public function getSegmentCost(): int
	{
		return static::SEGMENT_COST_MILLICENTS;
	}

	public function getDeferredSendMaxDelay(): ?int
	{
		return null; // SMS Broadcast does not specify a max delay for scheduled sends
	}

	public function listRecentDeliveries(?int $since = null): \Result
	{
		return \Result::success([]);
	}

	/** @inheritDoc */
	public function listOptOuts(): \Result
	{
		return \Result::failure('Not supported');
	}

	/** @inheritDoc */
	public function removeOptOut(\Sms\PhoneNumber $number): \Result
	{
		return \Result::failure('Not supported');
	}

	/**
	 * Send an SMS via the SmsBroadcast API.
	 *
	 * POSTs a URL-encoded body to the endpoint with username, password, to, from,
	 * message, and a random ref.  The to field is a comma-separated list of
	 * recipient numbers.
	 *
	 * @param string         $message    The message text
	 * @param SmsRecipient[] $recipients
	 * @param SmsSender      $sender     Sender number or ID
	 * @return \Result<SmsDeliveryBatch, string>
	 */
	public function send(
		array $entries,
		SmsSender $sender,
		?int $sendAt = null,
		bool $preview = false,
	): \Result{
		$allDeliveries = [];
		$senderNumber = (string) $sender;

		foreach ($entries as $entry) {
			$message = $entry['message'];
			$recipients = $entry['recipients'];

			if ($preview) {
				foreach ($recipients as $r) {
					$allDeliveries[] = new SmsDelivery(
						recipient: $r->getPhoneNumber(),
						status: SmsStatus::QUEUED,
						message: $message,
					);
				}
				continue;
			}

		$recipientNumbers = array_map(static fn (SmsRecipient $p) => $p->getPhoneNumber()->value, $recipients);

		$ref = bin2hex(random_bytes(6));

		$body = 'username=' . rawurlencode($this->username)
			  . '&password=' . rawurlencode($this->password)
			  . '&to=' . rawurlencode(implode(',', $recipientNumbers))
			  . '&from=' . rawurlencode($senderNumber)
			  . '&message=' . rawurlencode($message)
			  . '&ref=' . rawurlencode($ref);

		if ($sendAt !== null) {
			$delayMinutes = (int) ceil(($sendAt - time()) / 60);
			if ($delayMinutes > 0) {
				$body .= '&delay=' . $delayMinutes;
			}
		}

		$request = new HttpRequest(
			url: $this->url,
			method: 'POST',
			headers: "Content-Type: application/x-www-form-urlencoded\r\n"
				   . 'Content-Length: ' . \strlen($body) . "\r\n",
			body: $body,
		);

		$response = $this->httpClient->send($request);

		if ($response->isFailure()) {
			return \Result::failure($response->getError());
		}

		// Sending consumes credits — invalidate cached balance
		$this->cache?->delete('sms_balance');

			foreach ($this->parseSendResponse($response->getValue(), $recipientNumbers) as $d) {
				$allDeliveries[] = $d->with(message: $message);
			}
		}

		return \Result::success(new SmsDeliveryBatch(null, $allDeliveries));
	}

	/**
	 * Parse the line-based SmsBroadcast send response.
	 *
	 * Each line is one of:
	 *   OK:<number>:<ref>     — success (destination matched to our request)
	 *   BAD:<number>:<reason> — per-recipient failure
	 *   ERROR:<reason>        — overall error; all recipients marked failed
	 *
	 * Lines referencing numbers not in our original recipient list are ignored.
	 * Original recipients not appearing in any response line are marked as
	 * failures with "Recipient not found in API response".
	 *
	 * @param string[] $recipients  Phone numbers as raw digit strings
	 */
	/** @return SmsDelivery[] */
	private function parseSendResponse(HttpResponse $response, array $recipients): array
	{
		$body = trim($response->body);
		$lines = array_values(array_filter(
			explode("\n", $body),
			static fn (string $l) => trim($l) !== '',
		));

		if ($lines === []) {
			return [];
		}


		$results = [];
		$recipientMap = array_flip($recipients);

		// SMS Broadcast normalises Australian numbers to international format
		// (e.g. 0491570854 → 61402511927) in its response.  Build a reverse
		// lookup so we can match international destinations back to the original
		// local-format numbers used in the request.
		$internationalMap = [];
		foreach ($recipients as $r) {
			if (\str_starts_with($r, '0')) {
				$internationalMap['61' . substr($r, 1)] = $r;
			}
		}

		foreach ($lines as $line) {
			$line = trim($line);
			$parts = explode(':', $line, 3);

			if ($parts[0] === 'ERROR') {
				// Overall error — mark all recipients as failed,
				// using the raw ERROR line as each recipient's response.
				foreach ($recipients as $dest) {
					$results[] = new SmsBroadcastSmsDelivery(
						recipient: new PhoneNumber($dest),
						rawLine: $line,
					);
				}
				return $results;
			}

			if ($parts[0] === 'OK' && \count($parts) >= 3) {
				$dest = trim($parts[1]);
				// Map international-format numbers back to the original local format
				$original = $internationalMap[$dest] ?? $dest;
				if (isset($recipientMap[$original])) {
					$results[] = new SmsBroadcastSmsDelivery(
						recipient: new PhoneNumber($original),
						rawLine: $line,
					);
				}
			} elseif ($parts[0] === 'BAD' && \count($parts) >= 3) {
				$dest = trim($parts[1]);
				$original = $internationalMap[$dest] ?? $dest;
				if (isset($recipientMap[$original])) {
					$results[] = new SmsBroadcastSmsDelivery(
						recipient: new PhoneNumber($original),
						rawLine: $line,
					);
				}
			}
		}

		// Mark any recipients not present in the response as failures.
		// (SmsDelivery's properties are private, so array_column() can't read
		// them — collect via the accessor. The deliveries above were built
		// with the original local-format numbers, matching $recipients.)
		$seenDestinations = array_map(
			static fn (SmsDelivery $d) => $d->recipient()->value,
			$results,
		);
		foreach ($recipients as $dest) {
			if (!\in_array($dest, $seenDestinations, true)) {
				$results[] = new SmsDelivery(
					recipient: new PhoneNumber($dest),
					status: SmsStatus::FAILED,
				);
			}
		}

		return $results;
	}

}
