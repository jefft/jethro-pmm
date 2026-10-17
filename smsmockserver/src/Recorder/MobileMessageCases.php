<?php

namespace SmsMockServer\Recorder;

/**
 * MobileMessage (api.mobilemessage.com.au) exchanges. Without --send nothing
 * is delivered: the only send is scheduled a day ahead and cancelled.
 *
 * Environment: MOBILEMESSAGE_USERNAME, MOBILEMESSAGE_PASSWORD (API key),
 * MOBILEMESSAGE_TEST_NUMBER (recipient; on the live API a number you own),
 * MOBILEMESSAGE_TEST_SENDER (optional; a sender on the account, default the
 * test number).
 */
final class MobileMessageCases implements ProviderCases
{
    /** A well-formed message ID no account has. */
    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    /** A mobile not on the opt-out list. */
    private const UNLISTED_NUMBER = '61400000001';

    private function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $testNumber,
        private readonly string $sender,
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
        $testNumber = $env('MOBILEMESSAGE_TEST_NUMBER');
        return new self(
            $env('MOBILEMESSAGE_USERNAME'),
            $env('MOBILEMESSAGE_PASSWORD'),
            $testNumber,
            $env('MOBILEMESSAGE_TEST_SENDER', $testNumber),
        );
    }

    public function authHeaders(): array
    {
        return ['Authorization: Basic ' . base64_encode($this->username . ':' . $this->password)];
    }

    public function vars(): array
    {
        return [
            'test_number' => $this->testNumber,
            'sender' => $this->sender,
            'day_ahead' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
            'too_soon' => gmdate('Y-m-d\TH:i:s\Z', time() + 30),
            'today' => gmdate('Y-m-d'),
            'tomorrow' => gmdate('Y-m-d', time() + 86400),
            'unknown_id' => self::UNKNOWN_ID,
            'unlisted_number' => self::UNLISTED_NUMBER,
        ];
    }

    public function cases(): array
    {
        $message = fn(string $text, string $scheduledFor) => ['messages' => [[
            'to' => '{test_number}',
            'sender' => '{sender}',
            'message' => $text,
            'scheduled_for' => $scheduledFor,
        ]], 'enable_unicode' => false];

        return [
            ['name' => 'account', 'method' => 'GET', 'path' => '/v1/account'],
            ['name' => 'account.no-auth', 'method' => 'GET', 'path' => '/v1/account', 'auth' => false],
            ['name' => 'senders', 'method' => 'GET', 'path' => '/v1/senders'],
            ['name' => 'unsubscribes', 'method' => 'GET', 'path' => '/v1/unsubscribes', 'query' => ['limit' => '200', 'offset' => '0']],
            ['name' => 'unsubscribes.remove-unlisted', 'method' => 'DELETE', 'path' => '/v1/unsubscribes', 'body' => ['number' => '{unlisted_number}']],
            // DELETEs read only a JSON body: a query string is "missing".
            ['name' => 'unsubscribes.remove-by-query', 'method' => 'DELETE', 'path' => '/v1/unsubscribes', 'query' => ['number' => '{unlisted_number}']],
            ['name' => 'messages.send-invalid', 'method' => 'POST', 'path' => '/v1/messages', 'body' => ['enable_unicode' => false]],
            [
                'name' => 'messages.send-day-ahead', 'method' => 'POST', 'path' => '/v1/messages',
                'body' => $message('Jethro smsmockserver recording: scheduled a day ahead, then cancelled', '{day_ahead}'),
                'capture' => ['scheduled_id' => 'results.0.message_id'],
                'sentIds' => ['results', 'message_id'],
            ],
            ['name' => 'messages.lookup-scheduled', 'method' => 'GET', 'path' => '/v1/messages', 'query' => ['message_id' => '{scheduled_id}']],
            [
                'name' => 'messages.history', 'method' => 'GET', 'path' => '/v1/messages',
                'query' => ['from' => '{today}', 'to' => '{tomorrow}', 'limit' => '200', 'offset' => '0'],
                'onlyRunMessages' => ['results', 'message_id'],
            ],
            ['name' => 'messages.lookup-unknown', 'method' => 'GET', 'path' => '/v1/messages', 'query' => ['message_id' => '{unknown_id}']],
            ['name' => 'messages.cancel-by-query', 'method' => 'DELETE', 'path' => '/v1/messages', 'query' => ['message_id' => '{scheduled_id}']],
            ['name' => 'messages.cancel', 'method' => 'DELETE', 'path' => '/v1/messages', 'body' => ['message_id' => '{scheduled_id}']],
            ['name' => 'messages.cancel-again', 'method' => 'DELETE', 'path' => '/v1/messages', 'body' => ['message_id' => '{scheduled_id}']],
            ['name' => 'messages.lookup-cancelled', 'method' => 'GET', 'path' => '/v1/messages', 'query' => ['message_id' => '{scheduled_id}']],
            ['name' => 'messages.cancel-unknown', 'method' => 'DELETE', 'path' => '/v1/messages', 'body' => ['message_id' => '{unknown_id}']],
            // Delivers an SMS: scheduled_for ≤60 s ahead is sent at once.
            [
                'name' => 'send/messages.send-too-soon', 'method' => 'POST', 'path' => '/v1/messages',
                'body' => $message('Jethro smsmockserver recording: scheduled 30s ahead, so sent at once', '{too_soon}'),
                'sentIds' => ['results', 'message_id'],
            ],
        ];
    }

    public function cancelRequest(string $messageId): array
    {
        return ['method' => 'DELETE', 'path' => '/v1/messages', 'body' => ['message_id' => $messageId]];
    }
}
