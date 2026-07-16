#!/usr/bin/env php
<?php
/**
 * Dump expectedResponses() from all FakeHttpClient subclasses as JSON.
 *
 * Usage:
 *   php scripts/dump-sms-mocks.php [--output <file>]
 *
 * If --output is given, writes to the file.  Otherwise prints to stdout.
 *
 * Closures are evaluated with representative request bodies so the
 * output file contains realistic static JSON that the proxy can serve
 * without needing the PHP SMS pipeline.
 */

require_once __DIR__ . '/../jethro-sms/src/load.php';

use Sms\{HttpRequest, NativeHttpClient, CellcastFakeHttpClient,
    FiveCentSmsV5FakeHttpClient, SmsBroadcastFakeHttpClient, TemplateFakeHttpClient};

/** Create a representative request for closure evaluation. */
function fakeRequest(string $method, string $url, string $body = ''): HttpRequest
{
    return new HttpRequest(
        url: $url,
        method: $method,
        headers: ($body !== '' ? "Content-Type: application/json\r\n" : ''),
        body: $body,
    );
}

/**
 * Extract expectedResponses() from a FakeHttpClient subclass,
 * evaluating closures with representative requests.
 */
function extractResponses(string $class, NativeHttpClient $inner): array
{
    $instance = new $class($inner);
    $ref = new \ReflectionClass($instance);
    $method = $ref->getMethod('expectedResponses');
    $responses = $method->invoke($instance);

    $result = [];
    foreach ($responses as $key => $body) {
        if ($body instanceof \Closure) {
            // Evaluate with a representative request body
            $body = match (true) {
                // Cellcast gateway: POST with contacts array
                str_contains($key, '/gateway') => $body(fakeRequest(
                    'POST', 'https://api.cellcast.com/api/v1/gateway',
                    '{"contacts":["61400123456","61400987654"]}',
                )),
                // SMS Broadcast: POST with form-encoded recipients
                $class === SmsBroadcastFakeHttpClient::class => $body(fakeRequest(
                    'POST', 'https://www.smsbroadcast.com.au/api-adv.php',
                    'username=test&password=test&to=61400123456,61400987654&message=Test&from=Test',
                )),
                default => '"<<closure: ' . $key . '>>"',
            };
        }
        $result[$key] = $body;
    }
    return $result;
}

// ── Build the merged config ──────────────────────────────────────────

$inner = new NativeHttpClient();
$all = [];

foreach ([
    CellcastFakeHttpClient::class,
    FiveCentSmsV5FakeHttpClient::class,
    SmsBroadcastFakeHttpClient::class,
    TemplateFakeHttpClient::class,
] as $class) {
    $all = array_merge($all, extractResponses($class, $inner));
}

$json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ── Output ───────────────────────────────────────────────────────────

$args = $_SERVER['argv'] ?? [];
$outputFile = null;
for ($i = 1; $i < count($args); $i++) {
    if ($args[$i] === '--output' && isset($args[$i + 1])) {
        $outputFile = $args[++$i];
    }
}

if ($outputFile !== null) {
    file_put_contents($outputFile, $json . "\n");
    echo "Wrote " . count($all) . " mock responses to $outputFile\n";
} else {
    echo $json;
}
