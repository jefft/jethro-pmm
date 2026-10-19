<?php

namespace SmsMockServer\Recorder;

/**
 * Where a recorder case's request goes: the real API (or the running mock)
 * over HTTP, or the provider handlers in-process for tests.
 */
interface Transport
{
    /**
     * @param array<string, string> $query
     * @param list<string> $headers Header lines ("Name: value")
     * @return array{int, string} HTTP status and raw response body
     */
    public function send(string $method, string $path, array $query, ?array $body, array $headers): array;
}
