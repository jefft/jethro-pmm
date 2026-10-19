<?php

namespace SmsMockServer\Recorder;

use SmsMockServer\JsonResponse;
use SmsMockServer\MessageStore;
use SmsMockServer\Meta;
use SmsMockServer\Profile;
use SmsMockServer\Provider\Ctx;
use SmsMockServer\Provider\ProviderInterface;
use SmsMockServer\State;
use SmsMockServer\Util;

/**
 * Drives the provider handlers directly, without HTTP or a database: sets the
 * request globals, injects the body, and captures the response that would
 * have been written. Used by the unit tests.
 */
final class InProcessTransport implements Transport
{
    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly string $providerName,
        private readonly string $profileName,
        private readonly ?Profile $hooks,
        private readonly MessageStore $store,
        private readonly Meta $meta,
        private readonly State $state,
    ) {}

    public function send(string $method, string $path, array $query, ?array $body, array $headers): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['PATH_INFO'] = $path;
        $_SERVER['QUERY_STRING'] = http_build_query($query);
        $_GET = $query;
        $_SERVER['HTTP_AUTHORIZATION'] = '';
        foreach ($headers as $header) {
            if (stripos($header, 'Authorization:') === 0) {
                $_SERVER['HTTP_AUTHORIZATION'] = trim(substr($header, strlen('Authorization:')));
            }
        }

        $ctx = new Ctx(
            providerName: $this->providerName,
            profileName: $this->profileName,
            store: $this->store,
            profileHooks: $this->hooks,
            meta: $this->meta,
            state: $this->state,
            now: new \DateTimeImmutable('now'),
            rawBody: $body === null ? null : (string) json_encode($body),
        );

        Util::collectResponses(true);
        try {
            $this->provider->handle($ctx);
            throw new \RuntimeException("$method $path produced no response");
        } catch (JsonResponse $response) {
            return [
                $response->status,
                (string) json_encode($response->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        } finally {
            Util::collectResponses(false);
        }
    }
}
