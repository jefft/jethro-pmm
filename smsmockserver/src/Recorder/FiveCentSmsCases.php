<?php

namespace SmsMockServer\Recorder;

/**
 * 5CentSMS v5 (www.5centsms.com.au/api/v5) exchanges. Credentials go in the
 * JSON body of every request, GETs and DELETEs included.
 *
 * Without --send nothing is delivered: the only send is scheduled a day ahead
 * and cancelled.
 *
 * Environment: FIVECENTSMS_APIKEY_ID, FIVECENTSMS_APIKEY,
 * FIVECENTSMS_TEST_NUMBER (recipient; the only number to test with),
 * FIVECENTSMS_TEST_SENDER (optional; an approved sender ID or number,
 * default the test number in international form).
 */
final class FiveCentSmsCases implements ProviderCases
{
    /** A well-formed message ID no account has. */
    private const UNKNOWN_ID = '000000000000000000000000';

    /** A mobile not on the opt-out list. */
    private const UNLISTED_NUMBER = '61400000001';

    private function __construct(
        private readonly string $keyId,
        private readonly string $keySecret,
        private readonly string $testNumber,
        private readonly string $sender,
        private readonly string $existingSenderid,
    ) {}

    public static function fromEnv(): self
    {
        $env = static function (string $name, ?string $default = null): string {
            $value = getenv($name);
            if ($value === false || $value === '') {
                if ($default !== null) return $default;
                throw new \RuntimeException("Set $name");
            }
            return $value;
        };
        $testNumber = $env('FIVECENTSMS_TEST_NUMBER');
        return new self(
            $env('FIVECENTSMS_APIKEY_ID'),
            $env('FIVECENTSMS_APIKEY'),
            $testNumber,
            $env('FIVECENTSMS_TEST_SENDER', preg_replace('/^0/', '61', $testNumber)),
            // Must be an alphanumeric Sender ID already on the account, so
            // recording the registration refusal has no side effects. The
            // mock profile's equivalent is 'MockChurch'.
            $env('FIVECENTSMS_EXISTING_SENDERID', 'MockChurch'),
        );
    }

    public function authHeaders(): array
    {
        return [];
    }

    public function authBodyFields(): array
    {
        return ['key-id' => $this->keyId, 'key-secret' => $this->keySecret];
    }

    public function vars(): array
    {
        return [
            'test_number' => $this->testNumber,
            'sender' => $this->sender,
            'existing_senderid' => $this->existingSenderid,
            'day_ahead' => time() + 86400,
            'too_soon' => time() + 30,
            'unknown_id' => self::UNKNOWN_ID,
            'unlisted_number' => self::UNLISTED_NUMBER,
        ];
    }

    public function cases(): array
    {
        $send = fn(string $text, int|string $schedule) => [
            'sender' => '{sender}',
            'to' => '{test_number}',
            'message' => $text,
            'test' => false,
            'schedule' => $schedule,
        ];

        return [
            ['name' => 'balance', 'method' => 'GET', 'path' => '/balance'],
            ['name' => 'senderid', 'method' => 'GET', 'path' => '/senderid'],
            // Registration is pinned with refusal replies only: an ID the
            // account already has ({existing_senderid}) is refused without
            // side effects, and so is a body without "senderid". The created
            // path feeds an ACMA compliance email, so it stays unrecorded.
            ['name' => 'senderid.register-missing', 'method' => 'POST', 'path' => '/senderid',
             'body' => []],
            ['name' => 'senderid.register-existing', 'method' => 'POST', 'path' => '/senderid',
             'body' => ['senderid' => '{existing_senderid}']],
            ['name' => 'optouts', 'method' => 'GET', 'path' => '/optouts'],
            ['name' => 'optouts.remove-unlisted', 'method' => 'DELETE', 'path' => '/optouts/{unlisted_number}'],
            // No `to`: rejected, so nothing is sent.
            [
                'name' => 'messages.send-invalid', 'method' => 'POST', 'path' => '/sms',
                'body' => ['sender' => '{sender}', 'message' => 'x', 'test' => false],
            ],
            [
                'name' => 'messages.send-day-ahead', 'method' => 'POST', 'path' => '/sms',
                'body' => $send('Jethro smsmockserver recording: scheduled a day ahead, then cancelled', '{day_ahead}'),
                'capture' => ['scheduled_id' => 'messages.0.id'],
                'sentIds' => ['messages', 'id'],
            ],
            ['name' => 'messages.lookup-scheduled', 'method' => 'GET', 'path' => '/sms/{scheduled_id}'],
            [
                'name' => 'messages.list', 'method' => 'GET', 'path' => '/sms',
                'onlyRunMessages' => ['messages', 'id'],
            ],
            ['name' => 'messages.lookup-unknown', 'method' => 'GET', 'path' => '/sms/{unknown_id}'],
            ['name' => 'messages.cancel', 'method' => 'DELETE', 'path' => '/sms/{scheduled_id}'],
            ['name' => 'messages.cancel-again', 'method' => 'DELETE', 'path' => '/sms/{scheduled_id}'],
            ['name' => 'messages.lookup-cancelled', 'method' => 'GET', 'path' => '/sms/{scheduled_id}'],
            // Delivers an SMS 30s from now: run only with --send.
            [
                'name' => 'send/messages.send-too-soon', 'method' => 'POST', 'path' => '/sms',
                'body' => $send('Jethro smsmockserver recording: scheduled 30s ahead', '{too_soon}'),
                'sentIds' => ['messages', 'id'],
            ],
        ];
    }

    public function cancelRequest(string $messageId): array
    {
        return ['method' => 'DELETE', 'path' => '/sms/' . rawurlencode($messageId), 'body' => null];
    }

    public function unrecordable(): array
    {
        return []; // every served v5 route is recorded
    }
}
