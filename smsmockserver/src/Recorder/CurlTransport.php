<?php

namespace SmsMockServer\Recorder;

/**
 * Sends each request over HTTP (curl): the live APIs, or the running mock.
 */
final class CurlTransport implements Transport
{
    public function __construct(
        private readonly string $baseUrl,
    ) {}

    public function send(string $method, string $path, array $query, ?array $body, array $headers): array
    {
        $url = $this->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query));
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $body === null ? $headers : [...$headers, 'Content-Type: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new \RuntimeException("$method $path: " . curl_error($ch));
        }
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $raw];
    }
}
