<?php

declare(strict_types=1);

namespace Sms\Providers;
use Sms\ContactPhoneNumber;
use Sms\FormField;
use Sms\HttpClient;
use Sms\HttpRequest;
use Sms\HttpResponse;
use Sms\LoggingHttpClient;
use Sms\NativeHttpClient;
use Sms\OptOutEntry;
use Sms\OverridingSmsProvider;
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
use Sms\sendSummary;
use Sms\statusFromV5Code;


/**
 * SMS provider for the 5centsms v5 API.
 * https://docs.5centsms.com.au/
 *
 * Sends JSON POST requests to https://www.5centsms.com.au/api/v5/sms (config.url + '/sms')
 * with key-id/key-secret authentication in the body.
 *
 * This provider is designed for the Australian 5centsms gateway.
 * Number internationalisation defaults to Australian prefixes (local '0',
 * international '61'), configurable via SMS_LOCAL_PREFIX / SMS_INTERNATIONAL_PREFIX.
 *
 * PHP constants read by fromConstants():
 *   SMS_5CENTSMS_APIKEY_ID    — API key ID (required)
 *   SMS_5CENTSMS_APIKEY — API key secret (required)
 *   SMS_5CENTSMS_URL          — API base URL (default https://www.5centsms.com.au/api/v5)
 *   SMS_LOCAL_PREFIX          — local dialling prefix to strip (default '0')
 *   SMS_INTERNATIONAL_PREFIX  — country code to prepend (default '61')
 *   SMS_TESTMODE              — bool; when true, adds "test":true to the
 *                                JSON body for API-level dry-run.
 *   SMS_VERBOSE               — bool; logs request/response to error_log
 *   SMS_SENDER_OPTIONS             — comma-separated sender IDs (overrides API discovery)
 *
 * Sender override is handled by OverridingSmsProvider via SMS_SENDER.
 *
 * When $tfa is true, 2FA_* prefixed constants are tried first for each field
 * (2FA_SMS_5CENTSMS_APIKEY_ID, 2FA_SMS_5CENTSMS_APIKEY, 2FA_SMS_5CENTSMS_URL), falling back to the standard constant if the 2FA version is unset.
 *
 * Balance caching: getBalance() check cache first, then HTTP.
 * On success the result is stored in cache.  send() deletes
 * the cached balance because sending consumes credits.  If the cache is
 * null (not provided), caching is silently skipped — every getBalance()
 * call hits the API.
 *
 * Sender ID caching: checks static config, then cache, then API.  The sender
 * ID list changes rarely so there's no automatic invalidation — it
 * persists until the session ends.
 *
 * Test mode (legacy boolean): when testMode is true, `"test": true` is passed in the
 * JSON request body so the 5centsms API treats messages as test sends
 * without actually delivering them.  Real HTTP calls are still made.
 *
 */

class FiveCentSmsV5Provider implements SmsProvider
{
    private const CANONICAL_URL = 'https://www.5centsms.com.au/api/v5';
    public function __construct(
        /** Base URL for the v5 API. The provider appends /sms, /balance, or /senderid as needed. */
        private string      $url = self::CANONICAL_URL,
        /** API key ID for authentication — sent as the `key-id` field in the JSON POST body. */
        private string      $keyId = '',
        /** API key secret for authentication — sent as the `key-secret` field in the JSON POST body. */
        private string      $keySecret = '',
        /** When true, `"test":true` is set in the JSON body for API dry-run. */
        private bool        $testMode = false,
        /** When true the HTTP request and response are written to the PHP error log. */
        private bool        $verbose = false,
        /** Comma-separated Sender IDs. When set, overrides API-discovered Sender IDs. */
        private string      $senderIds = '',
        /** Local dialling prefix to strip when internationalising numbers (default '0' for AU). */
        private string      $localPrefix = '0',
        /** Country code to prepend when internationalising numbers (default '61' for AU). */
        private string      $internationalPrefix = '61',
        private ?HttpClient $httpClient = null,
        private ?SmsCache   $cache = null,
    )
    {
        $this->httpClient ??= new NativeHttpClient();
    }

    /**
     * Create a v5 provider from PHP constants.
     *
     * Reads SMS_5CENTSMS_* constants from conf.php. When $tfa is true, each field
     * tries its 2FA_SMS_5CENTSMS_* variant first (2FA_SMS_5CENTSMS_APIKEY_ID,
     * 2FA_SMS_5CENTSMS_APIKEY, 2FA_SMS_5CENTSMS_URL, 2FA_SENDER_ID),
     * falling back to the standard SMS_5CENTSMS_* constant if the 2FA version is unset.
     *
     * @param bool $tfa When true, reads 2FA_* constants first.
     * @throws \RuntimeException when APIKEY_ID or APIKEY_SECRET constants are missing
     */
    public static function fromConstants(bool $tfa = false): static
    {
        $url = $keyId = $keySecret = '';

        if ($tfa) {
            $url = (string)ifdef('2FA_SMS_5CENTSMS_URL', '');
            $keyId = (string)ifdef('2FA_SMS_5CENTSMS_APIKEY_ID', '');
            $keySecret = (string)ifdef('2FA_SMS_5CENTSMS_APIKEY', '');
        }

        if ($url === '') {
            $url = rtrim((string)ifdef('SMS_5CENTSMS_URL', self::CANONICAL_URL), '/');
        }
        if ($keyId === '') {
            $keyId = (string)ifdef('SMS_5CENTSMS_APIKEY_ID', '');
        }
        if ($keySecret === '') {
            $keySecret = (string)ifdef('SMS_5CENTSMS_APIKEY', '');
        }

        if ($keyId === '' || $keySecret === '') {
            throw new \RuntimeException(
                'Missing SMS configuration: '
                . implode(', ', array_filter([
                    $keyId === '' ? 'SMS_5CENTSMS_APIKEY_ID' : '',
                    $keySecret === '' ? 'SMS_5CENTSMS_APIKEY' : '',
                ]))
            );
        }

        $testMode = filter_var(ifdef('SMS_TESTMODE', false), FILTER_VALIDATE_BOOLEAN);
        return new static(
            url: $url,
            keyId: $keyId,
            keySecret: $keySecret,
            testMode: $testMode,
            httpClient: static::createHttpClient(
                $testMode,
                filter_var(ifdef('SMS_VERBOSE', false), FILTER_VALIDATE_BOOLEAN),
            ),
            senderIds: (string)ifdef('SMS_SENDER_OPTIONS', ''),
            localPrefix: (string)ifdef('SMS_LOCAL_PREFIX', '0'),
            internationalPrefix: (string)ifdef('SMS_INTERNATIONAL_PREFIX', '61'),
        );
    }

    public function withCache(SmsCache $cache): static
    {
        $clone = clone $this;
        $clone->cache = $cache;
        return $clone;
    }

    /**
     * Create the HTTP client for FiveCentSmsV5Provider.
     */
    public static function createHttpClient(bool $testMode, bool $verbose): HttpClient
    {
        $client = $testMode ? new FiveCentSmsV5FakeHttpClient(new NativeHttpClient()) : new NativeHttpClient();
        if ($verbose) {
            $client = new LoggingHttpClient($client);
        }
        return $client;
    }

    public function getBalance(): \Result
    {
        // 1. Cache hit
        $cached = $this->cache?->get('sms_balance');
        if ($cached !== null) {
            return \Result::success((int)$cached);
        }

        // 2. Fetch from API
        $body = json_encode([
            'key-id' => $this->keyId,
            'key-secret' => $this->keySecret,
        ]);

        $headers = "Content-Type: application/json\r\n";
        $headers .= 'Content-Length: ' . \strlen($body) . "\r\n";

        // FiveCent balance endpoint uses GET with a JSON body (non-standard but required by their API).
        $request = new HttpRequest(
            url: $this->url . '/balance',
            method: 'GET',
            headers: $headers,
            body: $body,
            timeout: 5,
        );

        $response = $this->httpClient->send($request);

        if ($response->isFailure()) {
            return \Result::failure($response->getError());
        }

        $balanceResult = $this->parseBalance($response->getValue());
        if ($balanceResult->isSuccess()) {
            $this->cache?->set('sms_balance', $balanceResult->getValue(), 300);
        }
        return $balanceResult;
    }

    public function isOperational(): \Result
    {
        // 1. Cache hit
        $cached = $this->cache?->get('sms_operational');
        if ($cached !== null) {
            return \Result::success((bool)$cached);
        }

        // 2. Check via balance API
        $balanceResult = $this->getBalance();
        if ($balanceResult->isSuccess()) {
            $this->cache?->set('sms_operational', true, 300);
            return \Result::success(true);
        }

        $this->cache?->set('sms_operational', false, 60);
        return \Result::success(false);
    }

    /**
     * Parse the balance API response.
     *
     * @see docs/docs/developer/reference/sms/_5centsmsv5/balance/GET.md
     *
     * @return \Result<int, string>
     */
    private function parseBalance(HttpResponse $response): \Result
    {
        $data = json_decode($response->body, true);

        if (!\is_array($data)) {
            return \Result::failure('Invalid JSON response from balance API. Raw: ' . $response->body);
        }

        if (!empty($data['error'])) {
            return \Result::failure($data['error'] . ' — raw: ' . $response->body);
        }

        if (!isset($data['balance']['credits'])) {
            return \Result::failure('No credits field in balance response. Raw: ' . $response->body);
        }

        return \Result::success((int)$data['balance']['credits']);
    }

    /**
     * Query the 5centsms v5 API for updated delivery status.
     *
     * GETs {baseUrl}/sms/{remoteId} and returns a new SmsDelivery
     * with the latest status and timestamps populated.
     */
    public function updateDelivery(SmsDelivery $delivery): \Result
    {
        $remoteId = $delivery->remoteId();
        if ($remoteId === null) {
            return \Result::failure('No remote ID on delivery');
        }

        $request = new HttpRequest(
            url: $this->url . '/sms/' . urlencode($remoteId),
            method: 'GET',
            headers: "Content-Type: application/json\r\n",
            body: json_encode([
                'key-id' => $this->keyId,
                'key-secret' => $this->keySecret,
            ]),
            timeout: 5,
        );

        $response = $this->httpClient->send($request);
        if ($response->isFailure()) {
            return \Result::failure('SMS info request failed: ' . $response->getError());
        }

        return $this->parseDeliveryInfo($delivery->recipient(), $response->getValue());
    }

    /**
     * Parse a delivery status API response into an updated SmsDelivery.
     *
     * Works for both GET /sms/{id} ({"message":{...}}) and DELETE /sms/{id}
     * ({"messages":{...}}).
     *
     * @see docs/docs/developer/reference/sms/_5centsmsv5/sms/{id}/GET.md
     * @see docs/docs/developer/reference/sms/_5centsmsv5/sms/DELETE.md
     */
    private function parseDeliveryInfo(PhoneNumber $recipient, HttpResponse $response): \Result
    {
        $data = json_decode($response->body, true);

        if (!\is_array($data)) {
            return \Result::failure('Invalid JSON response from SMS info API. Raw: ' . $response->body);
        }

        $data = self::trimArrayKeys($data);

        if (!empty($data['error'])) {
            return \Result::failure($data['error'] . ' — raw: ' . $response->body);
        }

        $msg = $data['message'] ?? $data['messages'] ?? null;
        if (!\is_array($msg)) {
            return \Result::failure('No message field in SMS info response. Raw: ' . $response->body);
        }

        $rawDelTs = isset($msg['delivery_timestamp']) ? (int)trim((string)$msg['delivery_timestamp']) : 0;
        $rawSendTs = isset($msg['send_timestamp']) ? (int)trim((string)$msg['send_timestamp']) : 0;

        $statusInt = (int)(trim((string)($msg['status'] ?? '')));
        $statusText = trim((string)($msg['status_text'] ?? ''));
        return \Result::success(new SmsDelivery(
            recipient: $recipient,
            status: \Sms\statusFromV5Code($statusInt, $statusText),
            remoteId: isset($msg['id']) ? trim((string)$msg['id']) : null,
            deliveryTimestamp: $rawDelTs > 0 ? $rawDelTs : null,
            sendTimestamp: $rawSendTs > 0 ? $rawSendTs : null,
        ));
    }

    /**
     * Recursively trim string keys and string values in an array.
     *
     * FiveCent v5 occasionally returns JSON with trailing spaces in keys
     * (e.g. "messages " instead of "messages").
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function trimArrayKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $trimmedKey = \trim((string)$key);
            if (\is_array($value)) {
                $result[$trimmedKey] = self::trimArrayKeys($value);
            } elseif (\is_string($value)) {
                $result[$trimmedKey] = \trim($value);
            } else {
                $result[$trimmedKey] = $value;
            }
        }
        return $result;
    }

    /**
     * Cancel previously sent (scheduled/deferred) SMS messages via HTTP DELETE.
     *
     * Iterates each delivery in the batch; per-message gateway failures leave
     * that delivery unchanged — the batch-level result is always success unless
     * there is a batch-level impossibility (e.g. auth failure before any attempt).
     * The v5 API returns status CANCELLED (1007) in the response.
     *
     * @return \Result<SmsDeliveryBatch, string>
     */
    public function cancel(SmsDeliveryBatch $batch): \Result
    {
        $results = [];
        foreach ($batch->deliveries as $delivery) {
            $results[] = $this->cancelOneDelivery($delivery);
        }
        return \Result::success(new SmsDeliveryBatch($batch->batchId, $results));
    }

    /**
     * Cancel a single delivery via HTTP DELETE to the v5 API.
     *
     * On success the returned delivery carries the status reported by the
     * API (CANCELLED 1007).  On failure — transport error or unparseable
     * response — returns the delivery unchanged except for statusDetail(),
     * which carries the transport/parse message.
     */
    private function cancelOneDelivery(SmsDelivery $delivery): SmsDelivery
    {
        $remoteId = $delivery->remoteId();
        if ($remoteId === null) {
            return $delivery->with(statusDetail: 'no remote message ID to cancel');
        }

        $request = new HttpRequest(
            url: $this->url . '/sms/' . urlencode($remoteId),
            method: 'DELETE',
            headers: "Content-Type: application/json\r\n",
            body: json_encode([
                'key-id' => $this->keyId,
                'key-secret' => $this->keySecret,
            ]),
            timeout: 5,
        );

        $response = $this->httpClient->send($request);
        if ($response->isFailure()) {
            return $delivery->with(statusDetail: $response->getError());
        }

        $parsed = $this->parseDeliveryInfo($delivery->recipient(), $response->getValue());
        if ($parsed->isFailure()) {
            return $delivery->with(statusDetail: $parsed->getError());
        }
        return $parsed->getValue();
    }

    /** @return array<array{string, string, string}> */
    public static function getConstants(): array
    {
        return [
            ['SMS_5CENTSMS_APIKEY_ID', 'required', 'API key ID from https://www.5centsms.com.au/dashboard/api'],
            ['SMS_5CENTSMS_APIKEY', 'required', 'API key secret from https://www.5centsms.com.au/dashboard/api'],
            ['SMS_5CENTSMS_URL', 'optional', 'API base URL (default: https://www.5centsms.com.au/api/v5)'],
            ['SMS_LOCAL_PREFIX', 'optional', 'Local dialling prefix to strip (default "0" for AU)'],
            ['SMS_INTERNATIONAL_PREFIX', 'optional', 'Country code to prepend (default "61" for AU)'],
            ['SMS_SENDER_OPTIONS', 'optional', 'Comma-separated sender options, may include _USER_MOBILE_ (overrides API discovery)'],
            ['SMS_TESTMODE', 'optional', 'Dry-run test mode: bool (adds "test":true to API)'],
            ['SMS_VERBOSE', 'optional', 'Log HTTP to error log'],
        ];
    }


    public function getKey(): string
    {
        return '5centsmsv5';
    }

    public static function usagePreference(): int
    {
        return 10;
    }

    public function getDescription(): string
    {
        $desc = '5CentSMS (v5)';
        if ($this->url !== self::CANONICAL_URL) {
            $desc .= ' (via ' . $this->url . ')';
        }
        return $desc;
    }

    /** @inheritDoc */
    public function getSenderNumbers(): \Result
    {
        // fetchSendersFromApi() is session-cached (shared with getSenderIds()).
        // Filter to approved phone numbers only (digit-only, ≥7 chars,
        // acmaApproved true), then convert to PhoneNumber objects.
        return $this->fetchSendersFromApi()->map(
            fn (array $senderIds) => array_values(array_map(
                static fn (SenderID $s) => new PhoneNumber($s->value),
                array_filter(
                    $senderIds,
                    static fn (SenderID $s) => $s->acmaApproved === true
                        && \ctype_digit($s->value)
                        && \strlen($s->value) >= 7,
                ),
            ))
        );
    }

    /** @inheritDoc */
    public function verifySenderNumber(PhoneNumber $number): \Result
    {
        $numbersResult = $this->getSenderNumbers();
        if ($numbersResult->isFailure()) {
            return \Result::failure('Could not fetch sender numbers: ' . $numbersResult->getError());
        }

        // Internationalise: v5 returns numbers as 61XXXXXXXXX, input may be 04XXXXXXXX
        $intl = $number->internationalise($this->localPrefix, $this->internationalPrefix)->value;
        foreach ($numbersResult->getValue() as $registered) {
            if ($registered->value === $intl) {
                return \Result::success(true);
            }
        }

        return \Result::success(false);
    }

    /** @inheritDoc */
    public function registerSenderNumber(?ContactPhoneNumber $contact = null, ?array $validationParams = null): \Result
    {
        if ($validationParams === null) {
            // Bare schema — no contact, no side effects (UI help)
            if ($contact === null) {
                return \Result::success(new RegistrationStep(
                    message: 'A verification link will be sent to your mobile number.',
                    fields: [],
                ));
            }
            // Phase 1: Register the number upstream
            $number = $contact->phoneNumber->internationalise('0', '61')->value;
            $result = $this->createSenderViaApi($number);
            if ($result->isFailure()) {
                return \Result::failure($result->getError());
            }
            $data = $result->getValue();
            $errorMsg = \is_array($data) && !empty($data['error']) ? $data['error'] : '';
            if ($errorMsg !== '') {
                return \Result::failure($errorMsg);
            }
            $this->cache?->delete('sms_senderids');
            return \Result::success(new RegistrationStep(
                registered: false,
                message: \is_array($data) && isset($data['message']) ? (string)$data['message'] : 'Verification link sent to the number',
                fields: [],
            ));
        }

        // Phase 2: v5 verification is out-of-band (link sent via SMS)
        return \Result::success(new RegistrationStep(
            registered: true,
            message: 'Verification complete — your number is now registered.',
        ));
    }

    /**
     * Register a Sender ID with 5CentSMS.
     *
     * Discovery (no args): returns the ACMA compliance field schema.
     * Creation ($senderId set, params null): calls POST /api/v5/senderid
     *   to create the ID upstream, then returns the schema pre-filled with
     *   the sender ID value.  Fails if the ID is taken.
     * Submission ($senderId set, params set): returns the ACMA compliance
     *   details as structured data (message, instructions, contact, and a
     *   label/value 'form' of the submitted params).  The web/CLI layer
     *   renders it — this method emits no HTML.
     *
     * ACMA registration itself cannot be automated — the provider requires
     * an email to hello@5centsms.com.au with these details.
     *
     * @see https://docs.5centsms.com.au/#06c16433-59d6-4431-8558-499922dc6b02
     * @inheritDoc
     */
    public function registerSenderId(?SenderID $senderId = null, ?array $validationParams = null): \Result
    {
        if ($validationParams === null) {
            // Bare schema — no sender ID, no side effects (UI help)
            if ($senderId === null) {
                return \Result::success(new RegistrationStep(
                    instructions: "5centsms <a href='https://docs.5centsms.com.au/#06c16433-59d6-4431-8558-499922dc6b02'>documentation</a> states:
<h3>ACMA Requirements for Alphanumeric Sender IDs</h3>
<p>Your requested ID must clearly represent a valid entity or brand, such as a sole trader, company, partnership, trust, co-operative, registered organisation, personal name, registered trademark, government body (with authorization), product or service name, or an acronym/initialism of at least three characters. Please email us details showing how your sender ID meets these criteria.</p> ",
                    fields: self::getSenderIdFieldSchema(new SenderID(''))
                ));
            }
            // Phase 1: Create the sender ID upstream, then return field schema
            $apiResult = $this->createSenderViaApi($senderId->value);
            if ($apiResult->isFailure()) {
                return \Result::failure($apiResult->getError());
            }
            $data = $apiResult->getValue();
            $errorMsg = \is_array($data) && !empty($data['error']) ? $data['error'] : '';
            if ($errorMsg !== '') {
                return \Result::failure($errorMsg);
            }
            $this->cache?->delete('sms_senderids');
            return \Result::success(new RegistrationStep(
                fields: self::getSenderIdFieldSchema($senderId),
            ));
        }

        // Phase 2: Return the ACMA compliance details as structured data.
        // The caller (web or CLI) renders it; the provider emits no markup.
        $validationParams['senderid'] = $senderId->value;
        $formData = $this->buildSenderIdFormData($validationParams);
        $body = "Hello, please can we ACMA-register the following Sender ID:\n\n";
        foreach ($formData as $row) {
            $body .= ($row['label'] ?? '') . ': ' . ($row['value'] ?? '') . "\n";
        }
        $mailtoUrl = 'mailto:hello@5centsms.com.au'
            . '?subject=' . rawurlencode('Sender ID registration: ' . $senderId->value)
            . '&body=' . rawurlencode($body);
        return \Result::success(new RegistrationStep(
            registered: false, // ACMA approval is a manual, out-of-band step
            message: 'Sender ID created — email the details below to complete registration.',
            instructions: $this->registerSenderIdInstructions(),
            contact: $mailtoUrl,
            form: $formData,
        ));
    }

    /** Plain-text next-step guidance shown after a Sender ID is created. */
    protected function registerSenderIdInstructions(): string
    {
        return '5CentSMS does not support automatic ACMA-registration of Sender IDs. '
            . 'Please have an authorised representative email these details to the address below:';
    }

    /**
     * Create a sender ID via the 5centsms v5 API.
     *
     * POSTs to /senderid with the given value.  Returns the parsed JSON
     * response body on success, or an HTTP error on failure.
     *
     * @return \Result<array, string>
     */
    private function createSenderViaApi(string $senderId): \Result
    {
        $body = json_encode([
            'key-id' => $this->keyId,
            'key-secret' => $this->keySecret,
            'senderid' => $senderId,
        ]);

        $headers = "Content-Type: application/json\r\n";
        $headers .= 'Content-Length: ' . \strlen($body) . "\r\n";

        $request = new HttpRequest(
            url: $this->url . '/senderid',
            method: 'POST',
            headers: $headers,
            body: $body,
            timeout: 10,
        );

        $response = $this->httpClient->send($request);

        if ($response->isFailure()) {
            return \Result::failure($response->getError());
        }

        $data = json_decode($response->getValue()->body, true);
        return \Result::success(\is_array($data) ? $data : []);
    }

    /**
     * Field schema for sender ID (business identity) registration.
     *
     * 5CentSMS creates the Sender ID via the API, then the admin must
     * email supporting compliance details to hello@5centsms.com.au.
     *
     * This form captures what the docs say is needed for validation:
     *
     *  >  Your requested ID must clearly represent a valid entity or brand, such as a sole trader, company, partnership, trust, co-operative, registered organisation, personal name, registered trademark, government body (with authorization), product or service name, or an acronym/initialism of at least three characters. Please email us details showing how your sender ID meets these criteria.
     *
     * @return FormField[]
     */
    public static function getSenderIdFieldSchema(SenderID $senderId): array
    {
        return [
            new FormField('senderid', 'Sender ID to register', 'text', required: true, value: $senderId->value),
            new FormField('contact_name', 'Contact Name', 'text', required: true),
            new FormField('contact_email', 'Contact Email', 'text', required: true),
            new FormField('contact_telephone', 'Contact Telephone', 'text', required: true),
            new FormField('abn', 'ABN (if applicable)', 'text', required: false),
            new FormField('business_name', 'Business Name', 'text', required: true),
            new FormField('business_web_address', 'Business Web Address', 'text', required: false),
            new FormField('business_address', 'Business Address', 'text', required: true),
            new FormField('business_telephone', 'Business Telephone', 'text', required: true),
        ];
    }

    /**
     * Build the ACMA compliance details as label/value pairs for the caller to render.
     *
     * Returns plain data (no markup); the web layer turns this into a table and the
     * CLI prints it as text.  Values come from the caller's submitted $params.
     *
     * @param array<string, string> $params
     * @return array<int, array{label: string, value: string}>
     */
    private function buildSenderIdFormData(array $params): array
    {
        $rows = [];
        foreach (self::getSenderIdFieldSchema(new SenderID($params['senderid'] ?? '')) as $f) {
            $rows[] = ['label' => $f->label, 'value' => (string) ($params[$f->name] ?? '')];
        }
        return $rows;
    }

    public function hasCapability(SmsCapability $cap): bool
    {
        return \in_array($cap, [
            SmsCapability::GET_BALANCE,
            SmsCapability::GET_SENDER_IDS,
            SmsCapability::DEFERRED_SEND,
            SmsCapability::DEFERRED_SEND_CANCEL,
            SmsCapability::REGISTER_SENDER_ID,
            SmsCapability::REGISTER_SENDER_NUMBER,
            SmsCapability::LIST_OPT_OUTS,
            SmsCapability::REMOVE_OPT_OUT,
            SmsCapability::BATCH_DELIVERY_QUERY,
        ], true);
    }

    public function getDeferredSendMaxDelay(): ?int
    {
        return 365 * 86400; // 365 days per 5centsms documentation
    }

    public function listRecentDeliveries(?int $since = null): \Result
    {
        $since = $since ?? time() - 86400;
        $all = [];
        $after = null;
        $pageCount = 0;
        do {
            if (++$pageCount > 10) return \Result::success($all);
            $url = $this->url . '/sms';
            if ($after !== null) $url .= '?after=' . $after;
            $request = new HttpRequest(
                url: $url,
                method: 'GET',
                headers: "Content-Type: application/json\r\n",
                body: json_encode(['key-id' => $this->keyId, 'key-secret' => $this->keySecret]),
            );
            $result = $this->httpClient->send($request);
            if ($result->isFailure()) return $result;
            $data = json_decode($result->getValue()->body, true);
            if (!is_array($data)) return \Result::success($all);
            foreach ($data['messages'] ?? [] as $msg) {
                if (!is_array($msg)) continue;
                $sendTs = (int)($msg['send_timestamp'] ?? 0);
                if ($sendTs < $since) return \Result::success($all);
                $phone = (string)($msg['destination'] ?? '');
                if ($phone === '') continue;
                $all[] = new SmsDelivery(
                    recipient: new PhoneNumber($phone),
                    status: \Sms\statusFromV5Code((int)($msg['status'] ?? 1003), (string)($msg['status_text'] ?? '')),
                    remoteId: isset($msg['id']) ? (string)$msg['id'] : null,
                    deliveryTimestamp: !empty($msg['delivery_timestamp']) ? (int)$msg['delivery_timestamp'] : null,
                    sendTimestamp: $sendTs > 0 ? $sendTs : null,
                    message: (string)($msg['message_text'] ?? ''),
                    rawResponse: json_encode($msg),
                );
            }
            $nextPage = (string)($data['next_page'] ?? '');
            $after = null;
            if ($nextPage !== '' && ($data['count'] ?? 0) > 0) {
                $parsed = parse_url($nextPage);
                parse_str($parsed['query'] ?? '', $qp);
                $after = $qp['after'] ?? null;
            }
        } while ($after !== null);
        return \Result::success($all);
    }

    public const SEGMENT_COST_MILLICENTS = 5000;

    public function getSegmentCost(): int
    {
        return static::SEGMENT_COST_MILLICENTS;
    }

    /**
     * List all opted-out phone numbers from the 5centsms v5 API.
     *
     * Fetches all pages from GET /api/v5/optouts internally (1000 per page,
     * cursor-based).  Results are cached for the session duration.
     * Each entry carries an epoch timestamp; names are always null
     * (the v5 API doesn't return subscriber names).
     *
     * @return \Result<OptOutEntry[], string>
     */
    public function listOptOuts(): \Result
    {
        // 1. Cache hit — stored as primitive arrays to avoid unserialize issues
        $cached = $this->cache?->get('sms_optouts');
        if ($cached !== null && \is_array($cached)) {
            $entries = [];
            foreach ($cached as $row) {
                $entries[] = new OptOutEntry(
                    number: new PhoneNumber($row['number']),
                    optedOutAt: $row['optedOutAt'] ?? null,
                );
            }
            return \Result::success($entries);
        }

        // 2. Fetch from API
        $allEntries = [];
        $after = null;

        do {
            $url = $this->url . '/optouts';
            if ($after !== null) {
                $url .= '?after=' . $after;
            }

            $body = json_encode([
                'key-id' => $this->keyId,
                'key-secret' => $this->keySecret,
            ]);

            $request = new HttpRequest(
                url: $url,
                method: 'GET',
                headers: "Content-Type: application/json\r\n"
                    . 'Content-Length: ' . \strlen($body) . "\r\n",
                body: $body,
                timeout: 10,
            );

            $response = $this->httpClient->send($request);
            if ($response->isFailure()) {
                return \Result::failure('Opt-out request failed: ' . $response->getError());
            }

            $data = json_decode($response->getValue()->body, true);
            if (!\is_array($data)) {
                return \Result::failure('Invalid JSON response from opt-out API. Raw: ' . $response->getValue()->body);
            }

            if (!empty($data['error'])) {
                return \Result::failure($data['error'] . ' — raw: ' . $response->getValue()->body);
            }

            $numbers = $data['numbers'] ?? [];
            foreach ($numbers as $entry) {
                $number = isset($entry['number']) ? (string) $entry['number'] : '';
                if ($number === '') continue;

                $allEntries[] = new OptOutEntry(
                    number: new PhoneNumber($number),
                    optedOutAt: isset($entry['timestamp']) ? (int) $entry['timestamp'] : null,
                );
            }

            $nextPage = isset($data['next_page']) ? (string) $data['next_page'] : '';
            $after = null;
            if ($nextPage !== '' && ($data['count'] ?? 0) > 0) {
                $parsed = parse_url($nextPage);
                $queryString = $parsed['query'] ?? '';
                parse_str($queryString, $queryParams);
                $after = $queryParams['after'] ?? null;
            }
        } while ($after !== null && $after !== '');

        // Cache primitive arrays (not OptOutEntry objects) for safe session serialization
        $cacheRows = [];
        foreach ($allEntries as $e) {
            $cacheRows[] = [
                'number' => $e->number->value,
                'optedOutAt' => $e->optedOutAt,
            ];
        }
        $this->cache?->set('sms_optouts', $cacheRows, 0);

        return \Result::success($allEntries);
    }

    /**
     * Remove a phone number from the 5centsms v5 opt-out list.
     *
     * Calls DELETE /api/v5/optouts/:number.  Returns true on success.
     * A "Record Not Found" error is treated as a success — the number
     * is already not in the opt-out list.
     *
     * @return \Result<bool, string>
     */
    public function removeOptOut(PhoneNumber $number): \Result
    {
        $url = $this->url . '/optouts/' . urlencode($number->value);
        $body = json_encode([
            'key-id' => $this->keyId,
            'key-secret' => $this->keySecret,
        ]);

        $request = new HttpRequest(
            url: $url,
            method: 'DELETE',
            headers: "Content-Type: application/json\r\n"
                . 'Content-Length: ' . \strlen($body) . "\r\n",
            body: $body,
            timeout: 10,
        );

        $response = $this->httpClient->send($request);
        if ($response->isFailure()) {
            return \Result::failure('Opt-out removal failed: ' . $response->getError());
        }

        $data = json_decode($response->getValue()->body, true);
        if (!\is_array($data)) {
            return \Result::failure('Invalid JSON response from opt-out removal API. Raw: ' . $response->getValue()->body);
        }

        if (!empty($data['error'])) {
            $err = $data['error'];
            // "Record Not Found" — number wasn't opted out, which is fine
            if (stripos($err, 'Record Not Found') !== false || stripos($err, 'Not Found') !== false) {
                $this->cache?->delete('sms_optouts');
                return \Result::success(true);
            }
            return \Result::failure($err);
        }

        $this->cache?->delete('sms_optouts');
        return \Result::success(true);
    }

    public function getSenderIds(bool $getAll = false): \Result
    {
        // fetchSendersFromApi() handles session-caching — getSenderNumbers()
        // shares the same cache key ('sms_senderids') for the same endpoint.
        return $this->fetchSendersFromApi()->map(
            function (array $senderIds) use ($getAll) {
                // Exclude phone numbers — those are returned by getSenderNumbers()
                $senderIds = self::filterOutPhoneNumbers($senderIds);
                return $getAll ? $senderIds : self::filterAcmaApproved($senderIds);
            }
        );
    }

    /**
     * Exclude phone-number-like SenderIDs (all-digit, ≥7 chars).
     *
     * Phone numbers are returned separately by getSenderNumbers().
     *
     * @param SenderID[] $senderIds
     * @return SenderID[]
     */
    private static function filterOutPhoneNumbers(array $senderIds): array
    {
        return array_values(array_filter(
            $senderIds,
            static fn(SenderID $s) => !(\ctype_digit($s->value) && \strlen($s->value) >= 7),
        ));
    }

    /**
     * Filter a list of sender IDs to only those that are ACMA-approved.
     *
     * Sender IDs whose acmaApproved status is unknown (null) are
     * excluded — only explicitly approved IDs pass the filter.
     *
     * @param SenderID[] $senderIds
     * @return SenderID[]
     */
    private static function filterAcmaApproved(array $senderIds): array
    {
        return array_values(array_filter(
            $senderIds,
            static fn(SenderID $s) => $s->acmaApproved === true,
        ));
    }

    /**
     * Fetch senders (IDs and numbers) from the 5centsms API, with caching.
     *
     * A single cache key ('sms_senderids') is shared with {@see getSenderIds()}
     * and {@see getSenderNumbers()} — both hit the same upstream endpoint,
     * so they share one session-cached result.
     *
     * @return \Result<SenderID[], string>
     */
    private function fetchSendersFromApi(): \Result
    {
        // 1. Cache hit — shared by getSenderIds() and getSenderNumbers()
        $cached = $this->cache?->get('sms_senderids');
        if ($cached !== null && \is_array($cached)) {
            $ids = array_map(
                static fn($s) => \is_array($s)
                    ? new SenderID($s['value'], $s['acmaApproved'] ?? null)
                    : new SenderID($s),
                $cached,
            );
            return \Result::success($ids);
        }

        // 2. API call
        $body = json_encode([
            'key-id' => $this->keyId,
            'key-secret' => $this->keySecret,
        ]);

        $headers = "Content-Type: application/json\r\n";
        $headers .= 'Content-Length: ' . \strlen($body) . "\r\n";

        $request = new HttpRequest(
            url: $this->url . '/senderid',
            method: 'GET',
            headers: $headers,
            body: $body,
            timeout: 5,
        );

        $response = $this->httpClient->send($request);

        if ($response->isFailure()) {
            return \Result::failure($response->getError());
        }

        $senderIds = $this->parseSenderIds($response->getValue());

        // 3. Populate cache — store ALL sender IDs (text IDs and phone numbers)
        // so both getSenderIds() and getSenderNumbers() can share one entry.
        $this->cache?->set('sms_senderids', array_map(
            static fn(SenderID $s) => ['value' => $s->value, 'acmaApproved' => $s->acmaApproved],
            $senderIds,
        ), 1800);

        return \Result::success($senderIds);
    }

    /**
     * Parse the senderid API response into SenderID objects.
     *
     * The FiveCent API has returned sender IDs in at least four different
     * JSON shapes over time.  This method tries them all in order:
     *   senderids → sender_ids → data → senderid
     *
     * Items can be plain strings or objects with a senderid/id/sender_id
     * key.  The acmaApproved flag is set from the status field if present
     * (both 'approved' and 'acma_approved' map to true; anything else maps to false).
     *
     * Failures are silent — invalid JSON or API errors return an empty
     * array rather than throwing.  This is deliberate: the caller treats
     * an empty sender ID list as "use config override or user mobile".
     *
     * @see docs/docs/developer/reference/sms/_5centsmsv5/senderid/GET.md
     *
     * @return SenderID[]
     */
    private function parseSenderIds(HttpResponse $response): array
    {
        $data = json_decode($response->body, true);

        if (!\is_array($data)) {
            return [];
        }

        // Check for API-level error
        if (!empty($data['error'])) {
            return [];
        }

        // Try common response formats
        $items = $data['senderids'] ?? $data['sender_ids'] ?? $data['data'] ?? $data['senderid'] ?? null;

        if (!\is_array($items)) {
            return [];
        }

        $senderIds = [];
        foreach ($items as $item) {
            if (\is_string($item)) {
                $senderIds[] = new SenderID($item);
            } elseif (\is_array($item) && isset($item['senderid'])) {
                $acmaApproved = array_key_exists('status', $item)
                    ? \in_array($item['status'], ['approved', 'acma_approved'], true)
                    : null;
                $senderIds[] = new SenderID((string)$item['senderid'], $acmaApproved);
            } elseif (\is_array($item) && isset($item['id'])) {
                $senderIds[] = new SenderID((string)$item['id']);
            } elseif (\is_array($item) && isset($item['sender_id'])) {
                $senderIds[] = new SenderID((string)$item['sender_id']);
            }
        }

        return $senderIds;
    }

    /**
     * Send one or more SMS messages via the 5centsms v5 API.
     *
     * Iterates entries — each entry produces one HTTP POST (multiple
     * recipients in one entry share a comma-separated `to` field).
     *
     * @param array<int, array{message: string, recipients: SmsRecipient[]}> $entries
     * @param SmsSender $sender Sender number or ID
     * @param int|null $sendAt Unix timestamp for deferred delivery (adds "schedule" to JSON body)
     * @return \Result<SmsDeliveryBatch, string>
     */
    public function send(
        array     $entries,
        SmsSender $sender,
        ?int      $sendAt = null,
        bool      $preview = false,
    ): \Result
    {
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
            $recipientNumbers = array_map(static fn(SmsRecipient $p) => $p->getPhoneNumber()->value, $recipients);

            $bodyData = [
                'key-id' => $this->keyId,
                'key-secret' => $this->keySecret,
                'sender' => (string) $sender,
                'to' => implode(',', $recipientNumbers),
                'message' => $message,
                'test' => $this->testMode,
            ];
            if ($sendAt !== null) {
                $bodyData['schedule'] = $sendAt;
            }
            $body = json_encode($bodyData);

            $headers = "Content-Type: application/json\r\n";
            $headers .= 'Content-Length: ' . \strlen($body) . "\r\n";

            $request = new HttpRequest(
                url: $this->url . '/sms',
                method: 'POST',
                headers: $headers,
                body: $body,
            );

            $response = $this->httpClient->send($request);

            if ($response->isFailure()) {
                return \Result::failure($response->getError());
            }

            $this->cache?->delete('sms_balance');

            foreach ($this->parseResponse($response->getValue(), $recipientNumbers) as $d) {
                $allDeliveries[] = $d->with(message: $message);
            }
        }

        return \Result::success(new SmsDeliveryBatch(null, $allDeliveries));
    }

    /**
     * Parse the v5 API JSON response into per-recipient results.
     *
     * The response is a JSON object with a 'messages' array.  Each message
     * entry maps to one recipient.  Recipients in our request list that
     * don't appear in the response are marked as failures with
     * "Recipient not found in API response".
     *
     * Recipients in the response that we DIDN'T request are ignored —
     * they don't appear in the results array at all.  This means
     * SmsBatchDelivery::sendSummary() must handle the case where none of our
     * recipients appear in the results (see that method's docs).
     *
     * @see docs/docs/developer/reference/sms/_5centsmsv5/sms/POST.md
     *
     * @param string[] $recipients Phone numbers as raw digit strings
     * @return SmsDelivery[]
     */
    private function parseResponse(HttpResponse $response, array $recipients): array
    {
        $body = $response->body;
        $data = json_decode($body, true);

        if (!\is_array($data)) {
            return [];
        }

        // Check for API-level error
        if (!empty($data['error'])) {
            return [];
        }

        if (empty($data['messages']) || !\is_array($data['messages'])) {
            return [];
        }

        // Build a map of destination -> message info
        $messageMap = [];
        foreach ($data['messages'] as $msg) {
            if (isset($msg['destination'])) {
                $messageMap[$msg['destination']] = $msg;
            }
        }

        $results = [];
        foreach ($recipients as $dest) {
            $msg = $messageMap[$dest] ?? null;

            if ($msg === null) {
                $results[] = new SmsDelivery(
                    recipient: new PhoneNumber($dest),
                    status: SmsStatus::FAILED,
                );
                continue;
            }

            $results[] = new FiveCentSmsDelivery(
                recipient: new PhoneNumber($dest),
                // Store the raw per-recipient JSON for DB logging
                rawJson: json_encode($msg),
            );
        }

        return $results;
    }
}
