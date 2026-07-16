<?php

declare(strict_types=1);

namespace Sms\Providers;

use Sms\FakeHttpClient;
use Sms\HttpClient;
use Sms\HttpRequest;

final readonly class SmsBroadcastFakeHttpClient extends FakeHttpClient
{
    public function send(HttpRequest $request): \Result
    {

        // Balance POSTs fall through to the real API — matchExpected()
        // only handles send POSTs.
        if ($request->method === 'POST' && !str_contains($request->body, 'action=balance')) {
            $expected = $this->matchExpected($request);
            if ($expected !== null) return $expected;
        }

        return $this->inner->send($request);
    }

    protected function expectedResponses(): array
    {
        return [
            'POST /' => function (HttpRequest $request): string {
                parse_str($request->body, $params);
                $to = explode(',', $params['to'] ?? []);
                return implode("\n", array_map(
                    fn(string $num) => 'OK:' . $num . ':fake_' . bin2hex(random_bytes(4)),
                    $to,
                ));
            },
        ];
    }
}

/**
 * Fake HTTP client for template-based providers (TemplateSmsProvider,
 * FiveCentSmsV4Provider).
 *
 * Returns a generic 'OK' response for all POSTs, which the template
 * response parser treats as "all recipients succeeded".
 * @see FakeHttpClient
 * @see TemplateSmsProvider
 */
