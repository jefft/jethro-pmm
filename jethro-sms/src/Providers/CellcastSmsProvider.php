<?php

declare(strict_types=1);

namespace Sms\Providers;
use Sms\ContactPhoneNumber;
use Sms\FormField;
use Sms\HttpClient;
use Sms\HttpRequest;
use Sms\LoggingHttpClient;
use Sms\NativeHttpClient;
use Sms\OptOutEntry;
use Sms\PhoneNumber;
use Sms\RegistrationStep;
use Sms\SenderID;
use Sms\SmsCache;
use Sms\SmsCapability;
use Sms\SmsDelivery;
use Sms\SmsDeliveryBatch;
use Sms\SmsProvider;
use Sms\SmsRecipient;
use Sms\SmsSender;
use Sms\SmsStatus;


/**
 * SMS provider for the cellcast.com API.
 *
 * Uses JSON POST/GET requests with Bearer-token authentication.
 *
 * @see SmsProvider
 * @see CellcastSmsDelivery
 */

class CellcastSmsProvider implements SmsProvider
{
	private const CANONICAL_URL = 'https://api.cellcast.com';

	public function __construct(
		/** API bearer token for authentication. */
		private string $apiToken,
		/** API base URL. */
		private string $url = self::CANONICAL_URL,
		/** Comma-separated Sender IDs. When set, overrides API-discovered Sender IDs. */
		private string $senderIds = '',
		/** Manual balance override. When set, skips the API balance call. */
		private string $balance = '',
		/** Local dialling prefix to strip when internationalising numbers (default '0' for AU). */
		private string $localPrefix = '0',
		/** Country code to prepend when internationalising numbers (default '61' for AU). */
		private string $internationalPrefix = '61',
		private ?HttpClient $httpClient = null,
		private ?SmsCache $cache = null,
) {}

	/**
	 * Create a Cellcast provider from PHP constants.
	 *
	 * Reads SMS_CELLCAST_* constants from conf.php.  When $tfa is true,
	 * 2FA_* prefixed constants are tried first for the API token
	 * (2FA_SMS_CELLCAST_APIKEY), falling back to the standard constant
	 * if the 2FA version is unset.
	 *
	 * @param bool $tfa  When true, reads 2FA_* constants first.
	 * @throws \RuntimeException when SMS_CELLCAST_APIKEY is missing
	 */
	public static function fromConstants(bool $tfa = false): static
	{
		$apiToken = $url = '';

		if ($tfa) {
			$apiToken = (string) ifdef('2FA_SMS_CELLCAST_APIKEY', '');
		}

		if ($apiToken === '') {
			$apiToken = (string) ifdef('SMS_CELLCAST_APIKEY', '');
		}
		if ($url === '') {
			$url = rtrim((string) ifdef('SMS_CELLCAST_URL', self::CANONICAL_URL), '/');
		}

		if ($apiToken === '') {
			throw new \RuntimeException('Missing SMS configuration: SMS_CELLCAST_APIKEY');
		}

		return new static(
			apiToken: $apiToken,
			url: $url,
			senderIds: (string) ifdef('SMS_SENDER_OPTIONS', ''),
			balance: (string) ifdef('SMS_BALANCE', ''),
			localPrefix: (string)ifdef('SMS_LOCAL_PREFIX', '0'),
			internationalPrefix: (string)ifdef('SMS_INTERNATIONAL_PREFIX', '61'),
			httpClient: static::createHttpClient(
				filter_var(ifdef('SMS_TESTMODE', false), FILTER_VALIDATE_BOOLEAN),
				filter_var(ifdef('SMS_VERBOSE', false), FILTER_VALIDATE_BOOLEAN),
			),
		);
	}

	public function withCache(SmsCache $cache): static
	{
		$clone = clone $this;
		$clone->cache = $cache;
		return $clone;
	}

	/**
	 * Create the HTTP client for CellcastSmsProvider.
	 * @param bool $testMode
	 */
	public static function createHttpClient(bool $testMode, bool $verbose): HttpClient
	{
		if ($testMode) {
			$client = new CellcastFakeHttpClient(new NativeHttpClient());
		} else {
			$client = new NativeHttpClient();
		}
		if ($verbose) {
			$client = new LoggingHttpClient($client);
		}
		return $client;
	}

	// -----------------------------------------------------------------------
	// HTTP helpers
	// -----------------------------------------------------------------------

	/**
	 * Build the common Authorization header.
	 */
	private function authHeader(): string
	{
		return "Authorization: Bearer {$this->apiToken}\r\n";
	}

	/**
	 * Send an HTTP request and return the parsed JSON body on success.
	 *
	 * @return \Result<array, string>
	 */
	private function request(string $method, string $path, ?array $body = null): \Result
	{
		$headers = $this->authHeader();
		$bodyStr = '';

		if ($body !== null) {
			$bodyStr = json_encode($body);
			$headers .= "Content-Type: application/json\r\n";
			$headers .= 'Content-Length: ' . \strlen($bodyStr) . "\r\n";
		}

		$httpRequest = new HttpRequest(
			url: $this->url . $path,
			method: $method,
			headers: $headers,
			body: $bodyStr,
			timeout: 30,
		);

		$response = $this->httpClient->send($httpRequest);

		if ($response->isFailure()) {
			return \Result::failure($response->getError());
		}

		return $this->parseJsonBody($response->getValue()->body);
	}

	/**
	 * Parse the JSON body of any Cellcast API response.
	 *
	 * Generic decoder used by all endpoints — not tied to a specific API shape.
	 */
	private function parseJsonBody(string $rawBody): \Result
	{
		$data = json_decode($rawBody, true);

		if (!\is_array($data)) {
			return \Result::failure('Invalid JSON response from Cellcast API. Raw: ' . $rawBody);
		}

		return \Result::success($data);
	}

	/**
	 * Resolve the effective sender: config override trumps caller-supplied.
	 */
	private function resolveSender(SmsSender $sender): string
	{
		$raw = (string) $sender;
		// If it looks like a phone number, internationalise it
		if (preg_match('/^\d+$/', $raw)) {
			return self::toInternational(new PhoneNumber($raw));
		}
		return $raw;
	}

	/**
	 * Normalise a phone number to international format for Cellcast.
	 *
	 * Strips the local prefix (default '0' for AU) and prepends the
	 * international prefix (default '61' for AU). Does NOT prepend '+'.
	 */
	private function toInternational(PhoneNumber $number): string
	{
		$v = $number->value;
		if ($this->localPrefix !== '' && \str_starts_with($v, $this->localPrefix)) {
			$v = $this->internationalPrefix . substr($v, \strlen($this->localPrefix));
		}
		return $v;
	}

	// -----------------------------------------------------------------------
	// SmsProvider implementation
	// -----------------------------------------------------------------------

	/** @return \Result<SmsDeliveryBatch, string> */
	public function send(
		array $entries,
		SmsSender $sender,
		?int $sendAt = null,
		bool $preview = false,
	): \Result {
		$allDeliveries = [];

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

		$body = [
			'message' => $message,
			'contacts' => array_map(
				fn (SmsRecipient $r) => self::toInternational($r->getPhoneNumber()),
				$recipients,
			),
			'sender' => $this->resolveSender($sender),
			'countryCode' => (int) $this->internationalPrefix,
		];

		if ($sendAt !== null) {
			$body['scheduleAt'] = gmdate('Y-m-d H:i:s', $sendAt);
		}

		$result = $this->request('POST', '/api/v1/gateway', $body);

		if ($result->isFailure()) {
			return $result;
		}

		// Invalidate cached balance
		$this->cache?->delete('sms_balance');

		$data = $result->getValue();

			if (($data['status'] ?? true) === false) {
				return \Result::failure(self::extractEnvelopeError($data));
			}

			foreach ($this->parseSendResponse($data, $recipients) as $d) {
				$allDeliveries[] = $d->with(message: $message);
			}
		}

		return \Result::success(new SmsDeliveryBatch(null, $allDeliveries));
	}

	/**
	 * Extract a human-readable error from a Cellcast API response with
	 * `status: false`.  Prefers the per-field messages in `error` (e.g.
	 * {"sender": "Your sender id is not registered."}), falling back to
	 * the top-level `message` field.
	 */
	private static function extractEnvelopeError(array $data): string
	{
		if (isset($data['error']) && \is_array($data['error']) && $data['error'] !== []) {
			return implode(' ', array_map('strval', $data['error']));
		}
		if (isset($data['message']) && $data['message'] !== '') {
			return (string) $data['message'];
		}
		return 'Unknown error — raw: ' . json_encode($data);
	}

	/**
	 * Parse the Cellcast send response into per-recipient SmsDelivery objects.
	 *
	 * The response data.queueResponse[] contains one entry per successfully
	 * queued recipient.  Recipients in our list that don't appear in
	 * queueResponse are marked as FAILED.
	 *
	 * @see docs/docs/developer/reference/sms/_cellcast/gateway/POST.md
	 *
	 * @param array $data  Parsed JSON response
	 * @param SmsRecipient[] $recipients
	 * @return SmsDelivery[]
	 */
	private function parseSendResponse(array $data, array $recipients): array
	{
		if (empty($data['data']) || !\is_array($data['data'])) {
			return [];
		}

		$responseData = $data['data'];
		$queueItems = $responseData['queueResponse'] ?? [];

		// Build destination → queueItem map
		$queuedMap = [];
		foreach ($queueItems as $item) {
			$number = isset($item['Number']) ? (string) $item['Number'] : '';
			if ($number !== '') {
				$queuedMap[$number] = $item;
			}
		}

		$results = [];
		foreach ($recipients as $recip) {
			$number = $recip->getPhoneNumber();
			$intl = self::toInternational($number);

			$item = $queuedMap[$intl] ?? null;
			if ($item !== null) {
				$results[] = new CellcastSmsDelivery($number, $item);
			} else {
				$results[] = new SmsDelivery(
					recipient: $number,
					status: SmsStatus::FAILED,
				);
			}
		}

		return $results;
	}

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
		// Cellcast account endpoint uses a different envelope:
		//   { meta: { code, status }, data: { sms_balance, ... } }
		$result = $this->request('GET', '/api/v1/apiClient/account');

		if ($result->isFailure()) {
			return $result;
		}

		return $this->parseBalanceResponse($result->getValue());
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
	 * Parse the Cellcast account/balance response.
	 *
	 * @see docs/docs/developer/reference/sms/_cellcast/account/GET.md
	 *
	 * @return \Result<int, string>
	 */
	private function parseBalanceResponse(array $data): \Result
	{
		// Cellcast account endpoint uses { meta, data } envelope
		$balanceData = $data['data'] ?? null;

		if (!\is_array($balanceData)) {
			return \Result::failure('No data field in balance response. Raw: ' . json_encode($data));
		}

		if (!isset($balanceData['sms_balance'])) {
			return \Result::failure('No sms_balance field in account response. Raw: ' . json_encode($data));
		}

		$balance = (int) (float) $balanceData['sms_balance'];

		// Cache the result
		$this->cache?->set('sms_balance', $balance, 300);

		return \Result::success($balance);
	}

	/**
	 * Fetch the raw /api/v1/customNumber response items, with session caching.
	 *
	 * Both {@see getSenderIds()} and {@see getSenderNumbers()} hit the same
	 * upstream endpoint, so they share a single cache key ('sms_senderids').
	 *
	 * @return \Result<array<array{number: string, name: string}>, string>
	 */
	private function fetchSenderItems(): \Result
	{
		$cached = $this->cache?->get('sms_senderids');
		if ($cached !== null && \is_array($cached)) {
			return \Result::success($cached);
		}

		$result = $this->request('GET', '/api/v1/customNumber');
		if ($result->isFailure()) {
			return $result;
		}

		$data = $result->getValue();

		// Cellcast auth/server errors return {"code": N, "message": "..."}
		// without a "data" key.  Distinguish from legitimate empty responses.
		if (isset($data['code']) && !isset($data['data'])) {
			$msg = $data['message'] ?? 'Unknown error';
			return \Result::failure("Cellcast API error (code {$data['code']}): {$msg}");
		}

		$items = isset($data['data']) && \is_array($data['data']) ? $data['data'] : [];

		$this->cache?->set('sms_senderids', $items, 1800);

		return \Result::success($items);
	}

	public function getSenderIds(bool $getAll = false): \Result
	{
		// Cellcast returns both phone numbers and alphanumeric sender IDs
		// from the same /api/v1/customNumber endpoint. Phone numbers go
		// through getSenderNumbers(); alphanumeric entries are sender IDs.
		return $this->fetchSenderItems()->map(function (array $items) {
			$ids = [];
			foreach ($items as $item) {
				$number = isset($item['number']) ? (string) $item['number'] : '';
				if ($number === '') continue;
				// Skip entries that are valid phone numbers — those are
				// sender numbers, not alphanumeric sender IDs.
				if (preg_match('/^\+?\d+$/', $number)) continue;
				$ids[] = new \Sms\SenderID($number);
			}
			return $ids;
		});
	}

	public function updateDelivery(SmsDelivery $delivery): \Result
	{
		$remoteId = $delivery->remoteId();
		if ($remoteId === null) {
			return \Result::failure('No remote ID on delivery');
		}

		// Cellcast delivery endpoint is v2: /api/v2/report/message/:messageId
		$result = $this->request('GET', '/api/v2/report/message/' . urlencode($remoteId));

		if ($result->isFailure()) {
			return $result;
		}

		return $this->parseDeliveryResponse($delivery->recipient(), $result->getValue());
	}

	/**
	 * Parse the Cellcast delivery status response.
	 *
	 * @see docs/docs/developer/reference/sms/_cellcast/report/message/{id}/GET.md
	 *
	 * @return \Result<SmsDelivery, string>
	 */
	private function parseDeliveryResponse(PhoneNumber $recipient, array $data): \Result
	{
		// Check for API-level error envelope: Cellcast returns { status: false, message: "...", error: {...} }
		// for failed lookups (invalid message IDs, auth errors, etc.).
		if (($data['status'] ?? null) === false) {
			$msg = isset($data['message']) && is_string($data['message'])
				? $data['message']
				: (isset($data['error']['message']) && is_string($data['error']['message'])
					? $data['error']['message']
					: 'Unknown delivery lookup error');
			return \Result::failure($msg . '. Raw: ' . json_encode($data));
		}

		$deliveryData = $data['data'] ?? null;

		if (!\is_array($deliveryData)) {
			return \Result::failure('No data field in delivery response. Raw: ' . json_encode($data));
		}

		$statusStr = isset($deliveryData['status']) ? (string) $deliveryData['status'] : '';
		$status = match ($statusStr) {
			'queued'    => SmsStatus::QUEUED,
			'scheduled' => SmsStatus::SCHEDULED,
			'sent'      => SmsStatus::SENT,
			'delivered' => SmsStatus::DELIVERED,
			'failed', 'blocked', 'rejected', 'expired' => SmsStatus::FAILED,
			'canceled'  => SmsStatus::CANCELLED,
			default     => SmsStatus::UNKNOWN,
		};

		$sendTs = null;
		if (!empty($deliveryData['send_time'])) {
			$sendTs = strtotime((string) $deliveryData['send_time']) ?: null;
		}

		// Only set a delivery timestamp when the handset confirmed delivery.
		// updatedAt is "when the record last changed", not a delivery confirmation.
		$delTs = null;
		if ($status === SmsStatus::DELIVERED) {
			$updatedAt = !empty($deliveryData['updatedAt']) ? (string) $deliveryData['updatedAt'] : '';
			if ($updatedAt !== '') {
				$delTs = strtotime($updatedAt) ?: null;
			}
		}

		return \Result::success(new SmsDelivery(
			recipient: $recipient,
			status: $status,
			remoteId: isset($deliveryData['_id']) ? (string) $deliveryData['_id'] : null,
			deliveryTimestamp: $delTs,
			sendTimestamp: $sendTs,
		));
	}

	/**
	 * Cancel previously sent (scheduled/deferred) SMS messages.
	 *
	 * Iterates each delivery in the batch; per-message gateway failures leave
	 * that delivery's status unchanged, with the upstream/transport message in
	 * statusDetail().  The batch-level result is always success unless there
	 * is a batch-level impossibility.
	 *
	 * @return \Result<\Sms\SmsDeliveryBatch, string>
	 */
	public function cancel(\Sms\SmsDeliveryBatch $batch): \Result
	{
		$results = [];
		foreach ($batch->deliveries as $delivery) {
			$results[] = $this->cancelOneDelivery($delivery);
		}
		return \Result::success(new \Sms\SmsDeliveryBatch($batch->batchId, $results));
	}

	/**
	 * Cancel a single delivery via POST to the Cellcast cancel endpoint.
	 *
	 * On failure — transport error, or an HTTP-200 envelope with status:false
	 * (e.g. "message not found") — returns the delivery unchanged except for
	 * statusDetail(), which carries the upstream or transport message.
	 */
	private function cancelOneDelivery(SmsDelivery $delivery): SmsDelivery
	{
		$remoteId = $delivery->remoteId();
		if ($remoteId === null) {
			return $delivery->with(statusDetail: 'no remote message ID to cancel');
		}

		$result = $this->request('POST', '/api/v1/gateway/cancelScheduleQuickMessage', [
			'messageId' => $remoteId,
			'type' => 'sms',
		]);

		if ($result->isFailure()) {
			return $delivery->with(statusDetail: $result->getError());
		}

		$data = $result->getValue();
		if (($data['status'] ?? false) !== true) {
			return $delivery->with(statusDetail: self::extractEnvelopeError($data));
		}

		return $delivery->with(status: SmsStatus::CANCELLED);
	}

	/**
	 * Check whether a specific phone number is approved as a sender.
	 *
	 * Calls getSenderNumbers() and checks whether the given number
	 * is among the registered custom numbers.
	 *
	 * Primary comparison uses toInternational(), which relies on
	 * SMS_LOCAL_PREFIX/SMS_INTERNATIONAL_PREFIX being configured correctly.
	 * Falls back to a trailing-digits match (numbersMatch()) so verification
	 * still works when those constants are blank/misconfigured for this
	 * provider — Cellcast always returns numbers in international format,
	 * regardless of how Jethro stores them locally.
	 *
	 * @param PhoneNumber $number  The phone number to check
	 * @return \Result<bool, string>
	 */
	public function verifySenderNumber(PhoneNumber $number): \Result
	{
		$numbersResult = $this->getSenderNumbers();
		if ($numbersResult->isFailure()) {
			return \Result::failure('Could not fetch sender numbers: ' . $numbersResult->getError());
		}

		$intl = $this->toInternational($number);
		$found = false;
		foreach ($numbersResult->getValue() as $registered) {
			if ($registered->value === $intl || self::numbersMatch($registered->value, $number->value)) {
				$found = true;
				break;
			}
		}

		return \Result::success($found);
	}

	/**
	 * Loosely compare two normalised (digits-only) phone numbers, tolerant of
	 * a missing/mismatched country-code or trunk prefix — e.g. '0402511927'
	 * (local) vs '61402511927' (international) refer to the same number, but
	 * differ in how many leading digits represent the prefix rather than the
	 * national subscriber number.
	 *
	 * Compares the last 9 digits of each (the length of an AU mobile national
	 * number, e.g. '402511927'), which is invariant across local/international
	 * representations. Requires both numbers to be at least 9 digits to avoid
	 * spurious matches on short input.
	 */
	private static function numbersMatch(string $a, string $b): bool
	{
		if ($a === $b) {
			return true;
		}
		if (\strlen($a) < 9 || \strlen($b) < 9) {
			return false;
		}
		return \substr($a, -9) === \substr($b, -9);
	}


	/**
	 * Get all registered sender phone numbers from Cellcast.
	 *
	 * Shares {@see fetchSenderItems()} (session-cached under 'sms_senderids')
	 * with {@see getSenderIds()} — both read from the same upstream endpoint.
	 *
	 * @return \Result<PhoneNumber[], string>
	 */
	public function getSenderNumbers(): \Result
	{
		return $this->fetchSenderItems()->map(function (array $items) {
			$numbers = [];
			foreach ($items as $item) {
				if (isset($item['number']) && \is_string($item['number']) && $item['number'] !== '') {
					$number = $item['number'];
					// Skip alphanumeric sender IDs — getSenderIds() handles those
					if (!preg_match('/^\+?\d+$/', $number)) continue;
					$numbers[] = new PhoneNumber($number);
				}
			}
			return $numbers;
		});
	}

	/**
	 * Register a phone number as a sender with Cellcast.
	 *
	 * Discovery (no args): returns the OTP field schema.
	 * Registration ($contact set, params null): calls POST
	 *   /api/v1/customNumber/add.  Returns 'registered': true if the
	 *   number is immediately usable (already known or created without
	 *   OTP), or 'registered': false with an OTP field if verification
	 *   is required.
	 * Verification ($contact set, params set): calls POST
	 *   /api/v1/customNumber/verifyCustomNumber with the OTP code.
	 *
	 * @inheritDoc
	 */
	public function registerSenderNumber(?ContactPhoneNumber $contact = null, ?array $validationParams = null): \Result
	{
		if ($validationParams === null) {
			// Bare schema — no contact, no side effects (UI help)
			if ($contact === null) {
				return \Result::success(new RegistrationStep(
					fields: [
						new FormField('otp', 'Verification code', 'text', required: true),
					],
				));
			}

			// Phase 1: Register the number upstream
			$result = $this->request('POST', '/api/v1/customNumber/add', [
				'name' => $contact->name,
				'number' => self::toInternational($contact->phoneNumber),
			]);

			if ($result->isFailure()) {
				return $result;
			}

			$data = $result->getValue();
			$msg = isset($data['message']) ? (string) $data['message'] : '';

			if (empty($data['status'])) {
				return \Result::failure($msg !== '' ? $msg : 'Unknown error — raw: ' . json_encode($data));
			}

			// "Number already exist in system" — already registered
			if (stripos($msg, 'already exist') !== false) {
				$this->cache?->set('sms_sender_registered_' . $contact->phoneNumber->value, true);
				return \Result::success(new RegistrationStep(registered: true, message: $msg));
			}

			// "Custom number created" — no OTP required, immediately registered
			if (stripos($msg, 'created') !== false) {
				$this->cache?->set('sms_sender_registered_' . $contact->phoneNumber->value, true);
				return \Result::success(new RegistrationStep(registered: true, message: $msg));
			}

			// "OTP sent to your number successfully" — OTP required
			return \Result::success(new RegistrationStep(
				registered: false,
				message: $msg,
				fields: [
					new FormField('otp', 'Verification code', 'text', required: true),
				],
			));
		}

		// Phase 2: Submit OTP for verification
		$otp = $validationParams['otp'] ?? '';
		if ($otp === '') {
			return \Result::failure('OTP code is required.');
		}

		$number = $contact->phoneNumber;
		$result = $this->request('POST', '/api/v1/customNumber/verifyCustomNumber', [
			'name' => $contact->name,
			'number' => self::toInternational($number),
			'otp' => $otp,
		]);

		if ($result->isFailure()) {
			return \Result::failure($result->getError());
		}

		$data = $result->getValue();

		if (!empty($data['status'])) {
			$this->cache?->set('sms_sender_registered_' . $number->value, true);
			return \Result::success(new RegistrationStep(
				registered: true,
				message: 'Number verified successfully.',
				number: $number->value,
			));
		}

		$errorMsg = isset($data['message']) ? (string) $data['message'] : 'OTP verification failed';
		return \Result::failure($errorMsg);
	}

	/** @return array<array{string, string, string}> */
	public static function getConstants(): array
	{
		return [
			['SMS_CELLCAST_APIKEY', 'required', 'API token from Cellcast dashboard'],
			['SMS_TESTMODE', 'optional', 'Dry-run test mode: bool for legacy, JSON map for config-driven fake responses'],
			['SMS_LOCAL_PREFIX', 'optional', 'Local dialling prefix to strip (default "0" for AU)'],
			['SMS_INTERNATIONAL_PREFIX', 'optional', 'Country code to prepend (default "61" for AU)'],
			['SMS_SENDER_OPTIONS', 'optional', 'Comma-separated sender options, may include _USER_MOBILE_ (overrides API discovery)'],
			['SMS_TESTMODE', 'optional', 'Dry-run without delivering'],
			['SMS_VERBOSE', 'optional', 'Log HTTP to error log'],
		];
	}

	public function getKey(): string
	{
		return 'cellcast';
	}

	public static function usagePreference(): int
	{
		return 9;
	}

	public function getDescription(): string
	{
		$desc = 'Cellcast';
		if ($this->url !== self::CANONICAL_URL) {
			$desc .= ' (via ' . $this->url . ')';
		}
		return $desc;
	}

	public function hasCapability(SmsCapability $cap): bool
	{
		return \in_array($cap, [
			SmsCapability::GET_BALANCE,
			SmsCapability::DEFERRED_SEND,
			SmsCapability::DEFERRED_SEND_CANCEL,
			SmsCapability::REGISTER_SENDER_NUMBER,
			SmsCapability::REGISTER_SENDER_ID,
			SmsCapability::LIST_OPT_OUTS,
			SmsCapability::BATCH_DELIVERY_QUERY,
		], true);
	}

	public const SEGMENT_COST_MILLICENTS = 4300;

	public function getSegmentCost(): int
	{
		return static::SEGMENT_COST_MILLICENTS;
	}

	public function getDeferredSendMaxDelay(): ?int
	{
		return 86400; // 24 hours per Cellcast documentation
	}

	public function listRecentDeliveries(?int $since = null): \Result
	{
		$since = $since ?? time() - 86400;
		$all = [];
		$page = 1;
		do {
			$path = '/api/v2/report/message?campType=sms&fromDate=' . date('Y-m-d', $since) . '&page=' . $page . '&limit=100';
			$request = new HttpRequest(
				url: $this->url . $path,
				method: 'GET',
				headers: $this->authHeader(),
				body: '',
			);
			$result = $this->httpClient->send($request);
			if ($result->isFailure()) return $result;
			$data = json_decode($result->getValue()->body, true);
			if (!is_array($data)) return \Result::success($all);
			$messages = $data['data'] ?? $data['messages'] ?? [];
			if (!is_array($messages)) return \Result::success($all);
			foreach ($messages as $msg) {
				if (!is_array($msg)) continue;
				$statusStr = isset($msg['status']) ? (string)$msg['status'] : '';
				$status = match ($statusStr) {
					'queued' => SmsStatus::QUEUED,
					'scheduled' => SmsStatus::SCHEDULED,
					'sent' => SmsStatus::SENT,
					'delivered' => SmsStatus::DELIVERED,
					'failed', 'blocked', 'rejected', 'expired' => SmsStatus::FAILED,
					'canceled' => SmsStatus::CANCELLED,
					default => SmsStatus::UNKNOWN,
				};
				$sendTs = null;
				if (!empty($msg['send_time'])) {
					$sendTs = strtotime((string)$msg['send_time']) ?: null;
				}
				$phone = (string)($msg['destination'] ?? $msg['to'] ?? '');
				$phone = preg_replace('/\D/', '', $phone);
				if ($phone === '') {
					continue; // skip records with empty/malformed phone numbers
				}
				$all[] = new SmsDelivery(
					recipient: new PhoneNumber($phone),
					status: $status,
					remoteId: isset($msg['_id']) ? (string)$msg['_id'] : (isset($msg['messageId']) ? (string)$msg['messageId'] : null),
					deliveryTimestamp: null,
					sendTimestamp: $sendTs,
					message: (string)($msg['message_text'] ?? $msg['body'] ?? ''),
					rawResponse: json_encode($msg),
				);
			}
			$totalPages = (int)($data['totalPages'] ?? $data['meta']['totalPages'] ?? 1);
			$page++;
		} while ($page <= $totalPages && $page <= 10);
		return \Result::success($all);
	}

	/**
	 * List all opted-out phone numbers from Cellcast.
	 *
	 * Fetches all pages from GET /api/v1/apiClient/getOptout internally.
	 * The Cellcast API does not return an opt-out timestamp — optedOutAt
	 * is always null.  Full names are populated when available.
	 *
	 * @return \Result<\Sms\OptOutEntry[], string>
	 */
	public function listOptOuts(): \Result
	{
		// 1. Cache hit — stored as primitive arrays to avoid unserialize issues
		$cached = $this->cache?->get('sms_optouts');
		if ($cached !== null && \is_array($cached)) {
			$entries = [];
			foreach ($cached as $row) {
				$entries[] = new \Sms\OptOutEntry(
					number: new \Sms\PhoneNumber($row['number']),
					name: $row['name'] ?? null,
				);
			}
			return \Result::success($entries);
		}
        $page = 1;
        $size = 100;
        $hasNext = true;
        $allEntries = [];
				while ($hasNext) {
			$path = '/api/v1/apiClient/getOptout?page=' . $page . '&size=' . $size;
			$result = $this->request('GET', $path);

			if ($result->isFailure()) {
				return $result;
			}

			$data = $result->getValue();
			$responseData = $data['data'] ?? null;

			if (!\is_array($responseData)) {
				return \Result::failure('No data field in opt-out response. Raw: ' . json_encode($data));
			}

			$items = $responseData['items'] ?? [];
			foreach ($items as $item) {
				$number = isset($item['number']) ? (string) $item['number'] : '';
				if ($number === '') continue;

				$name = null;
				$fullName = isset($item['full_name']) ? (string) $item['full_name'] : '';
				if ($fullName !== '') {
					$name = $fullName;
				} else {
					$first = isset($item['first_name']) ? (string) $item['first_name'] : '';
					$last = isset($item['last_name']) ? (string) $item['last_name'] : '';
					if ($first !== '' || $last !== '') {
						$name = trim($first . ' ' . $last);
					}
				}

				$allEntries[] = new \Sms\OptOutEntry(
					number: new \Sms\PhoneNumber($number),
					name: $name,
				);
			}

			$hasNext = !empty($responseData['hasNextPage']);
			$page++;
		}

		// Cache primitive arrays (not OptOutEntry objects) for safe session serialization
		$cacheRows = [];
		foreach ($allEntries as $e) {
			$cacheRows[] = [
				'number' => $e->number->value,
				'name' => $e->name,
			];
		}
		$this->cache?->set('sms_optouts', $cacheRows, 0);

		return \Result::success($allEntries);
	}

	/**
	 * Remove a phone number from the opt-out list.
	 *
	 * Cellcast does not support opt-out removal via the API.
	 *
	 * @return \Result<bool, string>
	 */
	public function removeOptOut(\Sms\PhoneNumber $number): \Result
	{
		return \Result::failure('Cellcast does not support removing opt-outs via the API.');
	}

	/**
	 * Register a sender ID (business identity) with Cellcast.
	 *
	 * Discovery (no args): returns the business registration field schema.
	 * Creation ($senderId set, params null): returns the schema with the
	 *   sender ID pre-populated (Cellcast has no separate creation API).
	 * Submission ($senderId set, params set): calls POST
	 *   /api/v1/business/add with the full business registration payload.
	 *
	 * @see https://developer.cellcast.com/sender-id/business-name.html
	 * @inheritDoc
	 */
	public function registerSenderId(?SenderID $senderId = null, ?array $validationParams = null): \Result
	{
		if ($validationParams === null) {
			// Return field schema (bare or pre-populated)
			$sid = $senderId ?? new \Sms\SenderID('');
			return \Result::success(new RegistrationStep(
                message: "Submitting this form will use the <a href='https://developer.cellcast.com/sender-id/business-name.html'>Cellcast Custom Business Name registration API</a> to register a Sender ID.",
				fields: self::getSenderIdFieldSchema($sid),
			));
		}

		// Phase 2: Submit to Cellcast registration API
		$businessName        = $validationParams['businessname'] ?? '';
		$descriptionInternal = $validationParams['descriptionInternal'] ?? '';
		$purposeOfUse        = $validationParams['purposeOfUse'] ?? '';
		$ownership           = !empty($validationParams['ownership']);
		$customerContact     = $validationParams['customerContact'] ?? '';

		$companyInfo = [];
		if (!empty($validationParams['company_name']))    $companyInfo['name']    = $validationParams['company_name'];
		if (!empty($validationParams['company_abn']))     $companyInfo['abn']     = $validationParams['company_abn'];
		if (!empty($validationParams['company_website'])) $companyInfo['website'] = $validationParams['company_website'];
		if (!empty($validationParams['company_address'])) $companyInfo['address'] = $validationParams['company_address'];

		$requestorContact = [];
		if (!empty($validationParams['requestor_firstName']))   $requestorContact['firstName']   = $validationParams['requestor_firstName'];
		if (!empty($validationParams['requestor_lastName']))    $requestorContact['lastName']    = $validationParams['requestor_lastName'];
		if (!empty($validationParams['requestor_position']))    $requestorContact['position']    = $validationParams['requestor_position'];
		if (!empty($validationParams['requestor_phoneNumber'])) $requestorContact['phoneNumber'] = $validationParams['requestor_phoneNumber'];
		if (!empty($validationParams['requestor_email']))       $requestorContact['email']       = $validationParams['requestor_email'];

		if ($businessName === '') {
			return \Result::failure('businessname is required');
		}

		// Submit fields as per https://developer.cellcast.com/sender-id/business-name.html
		$body = array_filter([
			'businessname' => $businessName,
			'descriptionInternal' => $descriptionInternal !== '' ? $descriptionInternal : null,
			'purposeOfUse' => $purposeOfUse !== '' ? $purposeOfUse : null,
			'ownership' => $ownership,
			'companyInformation' => $companyInfo !== [] ? $companyInfo : null,
			'requestorContact' => $requestorContact !== [] ? $requestorContact : null,
			'customerContact' => $customerContact !== '' ? $customerContact : null,
		], fn ($v) => $v !== null);

		$headers = $this->authHeader();
		$bodyStr = json_encode($body);
		$headers .= "Content-Type: application/json\r\n";
		$headers .= 'Content-Length: ' . \strlen($bodyStr) . "\r\n";

		$httpRequest = new HttpRequest(
			url: $this->url . '/api/v1/business/add',
			method: 'POST',
			headers: $headers,
			body: $bodyStr,
			timeout: 30,
		);

		$response = $this->httpClient->send($httpRequest);

		if ($response->isFailure()) {
			return \Result::failure($response->getError());
		}

		$parsed = $this->parseJsonBody($response->getValue()->body);

		if ($parsed->isFailure()) {
			return \Result::failure($parsed->getError());
		}

		$apiData = $parsed->getValue();
		$apiMsg = \is_array($apiData) && isset($apiData['message']) ? (string) $apiData['message'] : '';
		$success = \is_array($apiData) && !empty($apiData['status']);

		// Build form rows from submitted params, mirroring V5's pattern
		$formRows = [];
		foreach (self::getSenderIdFieldSchema($senderId ?? new \Sms\SenderID('')) as $f) {
			$formRows[] = ['label' => $f->label, 'value' => (string) ($validationParams[$f->name] ?? '')];
		}

		return \Result::success(new RegistrationStep(
			registered: false, // Cellcast business-name approval is a manual step
			message: $success ? ($apiMsg !== '' ? $apiMsg : 'Sender ID registration submitted.') : ($apiMsg !== '' ? $apiMsg : 'Registration submitted.'),
			instructions: 'Cellcast will review your business name registration. You will be contacted if further information is required.',
			contact: '',
			form: $formRows,
		));
	}

	/**
	 * Field schema for sender ID (business identity) registration.
	 *
     * Fields and descriptions are from https://developer.cellcast.com/sender-id/business-name.html
     *
	 * @return FormField[]
	 */
	public static function getSenderIdFieldSchema(\Sms\SenderID $senderId): array
	{
		return [
			new FormField('businessname',        'Business Name / Sender ID',          'text',     required: true,  description: 'Required. Must be between 3 and 11 characters.', value: $senderId->value),
			new FormField('descriptionInternal',  'Internal Description',   'text',     required: true,  description: 'Required. Internal description for this business name.'),
			new FormField('purposeOfUse',         'Purpose of Use',         'select',   required: true,  description: 'Required. Purpose of use / traffic type.', options: ['Promotional SMS', 'Transactional SMS', 'Service SMS']),
			new FormField('ownership',            'I own this business',     'checkbox', required: true,  description: 'Required. Boolean indicating ownership of the business name (true/false).'),
			new FormField('company_name',         'Company Name',            'text',     required: true,  description: 'Required. Company name.'),
			new FormField('company_abn',          'ABN',                     'text',     required: true,  description: 'Required. Australian Business Number (ABN).'),
			new FormField('company_website',      'Website',                 'text',     required: true,  description: 'Required. Company website URL.'),
			new FormField('company_address',      'Address',                 'text',     required: true,  description: 'Required. Company address.'),
			new FormField('requestor_firstName',  'First Name (requestor)',  'text',     required: true,  description: 'Required. Requestor\'s first name.'),
			new FormField('requestor_lastName',   'Last Name (requestor)',   'text',     required: true,  description: 'Required. Requestor\'s last name.'),
			new FormField('requestor_position',   'Position (requestor)',    'text',     required: true,  description: 'Required. Requestor\'s position/role.'),
			new FormField('requestor_phoneNumber','Phone Number (requestor)','text',     required: true,  description: 'Required. Requestor\'s phone number (digits only).'),
			new FormField('requestor_email',      'Email (requestor)',       'text',     required: true,  description: 'Required. Requestor\'s email address.'),
			new FormField('customerContact',      'Customer Contact Number', 'text',     required: false, description: 'Optional. Your contact number. Cellcast may contact you on this number for business name approval. You can use formats +61400000000, 61400000000, 0400000000, or 400000000.'),
		];
	}
}
