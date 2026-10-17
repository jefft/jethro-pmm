<?php

namespace SmsMockServer\Recorder;

/**
 * Cellcast (api.cellcast.com) exchanges, authenticated with a Bearer token.
 *
 * Without --send nothing is delivered: the only send is scheduled a day ahead
 * and cancelled.
 *
 * Environment: CELLCAST_APIKEY, CELLCAST_TEST_NUMBER (recipient; the only
 * number to test with), CELLCAST_TEST_SENDER (optional; an approved custom
 * number, default the test number in international form).
 */
final class CellcastCases implements ProviderCases
{
    /** A well-formed message ID no account has. */
    private const UNKNOWN_ID = '000000000000000000000000';

    private function __construct(
        private readonly string $apiToken,
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
        $testNumber = $env('CELLCAST_TEST_NUMBER');
        return new self(
            $env('CELLCAST_APIKEY'),
            $testNumber,
            $env('CELLCAST_TEST_SENDER', preg_replace('/^0/', '61', $testNumber)),
        );
    }

    public function authHeaders(): array
    {
        return ['Authorization: Bearer ' . $this->apiToken];
    }

    public function authBodyFields(): array
    {
        return [];
    }

    public function vars(): array
    {
        return [
            'test_number' => $this->testNumber,
            'sender' => $this->sender,
            'day_ahead' => gmdate('Y-m-d H:i:s', time() + 86400),
            'too_soon' => gmdate('Y-m-d H:i:s', time() + 30),
            'today' => gmdate('Y-m-d'),
            'unknown_id' => self::UNKNOWN_ID,
        ];
    }

    public function cases(): array
    {
        $send = fn(string $scheduleAt) => [
            'message' => 'Jethro smsmockserver recording: scheduled, then cancelled',
            'contacts' => ['{test_number}'],
            'sender' => '{sender}',
            'countryCode' => 61,
            'scheduleAt' => $scheduleAt,
        ];
        $cancel = fn() => [
            'method' => 'POST', 'path' => '/api/v1/gateway/cancelScheduleQuickMessage',
            'body' => ['messageId' => '{scheduled_id}', 'type' => 'sms'],
        ];

        return [
            ['name' => 'account', 'method' => 'GET', 'path' => '/api/v1/apiClient/account'],
            ['name' => 'customNumber', 'method' => 'GET', 'path' => '/api/v1/customNumber'],
            ['name' => 'business', 'method' => 'GET', 'path' => '/api/v1/business'],
            [
                'name' => 'optouts', 'method' => 'GET', 'path' => '/api/v1/apiClient/getOptout',
                'query' => ['page' => '1', 'size' => '100'],
            ],
            // No recipients: rejected, so nothing is sent.
            [
                'name' => 'gateway.send-invalid', 'method' => 'POST', 'path' => '/api/v1/gateway',
                'body' => ['contacts' => [], 'sender' => '{sender}', 'countryCode' => 61],
            ],
            [
                'name' => 'gateway.send-day-ahead', 'method' => 'POST', 'path' => '/api/v1/gateway',
                'body' => $send('{day_ahead}'),
                'capture' => ['scheduled_id' => 'data.queueResponse.0.MessageId'],
                'sentIds' => ['data.queueResponse', 'MessageId'],
            ],
            ['name' => 'report.lookup-scheduled', 'method' => 'GET', 'path' => '/api/v2/report/message/{scheduled_id}'],
            [
                'name' => 'report.list', 'method' => 'GET', 'path' => '/api/v2/report/message',
                'query' => ['campType' => 'sms', 'fromDate' => '{today}', 'page' => '1', 'limit' => '100'],
                'onlyRunMessages' => ['data.items', '_id'],
            ],
            ['name' => 'report.lookup-unknown', 'method' => 'GET', 'path' => '/api/v2/report/message/{unknown_id}'],
            ['name' => 'gateway.cancel'] + $cancel(),
            ['name' => 'gateway.cancel-again'] + $cancel(),
            ['name' => 'report.lookup-cancelled', 'method' => 'GET', 'path' => '/api/v2/report/message/{scheduled_id}'],
            // Delivers an SMS 30s from now: run only with --send.
            [
                'name' => 'send/gateway.send-too-soon', 'method' => 'POST', 'path' => '/api/v1/gateway',
                'body' => $send('{too_soon}'),
                'sentIds' => ['data.queueResponse', 'MessageId'],
            ],
        ];
    }

    public function cancelRequest(string $messageId): array
    {
        return [
            'method' => 'POST',
            'path' => '/api/v1/gateway/cancelScheduleQuickMessage',
            'body' => ['messageId' => $messageId, 'type' => 'sms'],
        ];
    }
}
