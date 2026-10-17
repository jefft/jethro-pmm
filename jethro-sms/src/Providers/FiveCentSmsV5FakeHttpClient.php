<?php

declare(strict_types=1);

namespace Sms\Providers;

use Sms\FakeHttpClient;
use Sms\HttpClient;
use Sms\HttpRequest;

final readonly class FiveCentSmsV5FakeHttpClient extends FakeHttpClient
{
    public function send(HttpRequest $request): \Result
    {

        $expected = $this->matchExpected($request);
        if ($expected !== null) return $expected;

        // All other requests (POST /sms with "test":true, GETs for balance
        // and sender-id listing) go through to the real API.
        return $this->inner->send($request);
    }

    protected function expectedResponses(): array
    {
        return [
            'POST /senderid' => json_encode([
                'error' => '',
                'message' => 'Sender ID created (test mode — not actually created)',
            ]),
        ];
    }
}
