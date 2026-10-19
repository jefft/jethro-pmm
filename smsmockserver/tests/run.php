#!/usr/bin/env php
<?php
/**
 * smsmockserver tests — no web server, no database.
 *
 * 1. Pure units: the normaliser's format tokens and the status/approval state
 *    machine.
 * 2. The real ask: run each provider's recorded cases through its handlers
 *    in-process (MemoryStore + InProcessTransport) and compare the normalised
 *    replies, case by case, with the committed live recordings in
 *    recordings/<provider>/actual/.
 *
 *   php tests/run.php [provider...]
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use SmsMockServer\MemoryStore;
use SmsMockServer\Meta;
use SmsMockServer\Profile;
use SmsMockServer\Recorder\InProcessTransport;
use SmsMockServer\Recorder\Normaliser;
use SmsMockServer\Recorder\Providers;
use SmsMockServer\Recorder\Recorder;
use SmsMockServer\State;

// ── Tiny harness ──────────────────────────────────────────────────────

$tests = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['tests'][] = [$name, $fn];
}

function check(bool $condition, string $message = 'assertion failed'): void
{
    if (!$condition) throw new RuntimeException($message);
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? "$message: " : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true),
        );
    }
}

/** @return list<string> Relative paths of normalised recording files */
function recordingFiles(string $dir): array
{
    if (!is_dir($dir)) return [];
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($dir) + 1);
        if (str_ends_with($relative, '.json') && !str_ends_with($relative, '.raw.json')) {
            $files[] = $relative;
        }
    }
    sort($files);
    return $files;
}

function firstDifference(string $expected, string $actual): string
{
    $e = explode("\n", $expected);
    $a = explode("\n", $actual);
    for ($i = 0; $i < max(count($e), count($a)); $i++) {
        if (($e[$i] ?? null) !== ($a[$i] ?? null)) {
            return 'line ' . ($i + 1) . ":\n  expected: " . ($e[$i] ?? '<missing>') . "\n  got:      " . ($a[$i] ?? '<missing>');
        }
    }
    return 'contents differ but no line does?';
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

// ── Pure units: normaliser ────────────────────────────────────────────

test('normaliser: volatile values keep only their format', function () {
    $normalise = fn(string $json, ?string $key = null) => Normaliser::normalise(json_decode($json), $key);
    same('<uuid>', $normalise('"8fcc051c-1f59-4fca-a4cd-b7df9ec8802e"'));
    same('<24-hex>', $normalise('"6ad3932ad4e1d4c8a0046b3d"'));
    same('<iso-ms>', $normalise('"2026-06-08T12:28:36.690Z"'));
    same('<Y-m-d H:i:s>', $normalise('"2026-10-17 10:59:49"'));
    same('<msisdn 614…>', $normalise('"61491570158"'));
    same('<msisdn 04…>', $normalise('"0491570158"'));
    same('<msisdn 4…>', $normalise('"491570158"'));
    same('<next_page>', $normalise('"/api/v5/sms?after=6ad3932ad4e1d4c8a0046b3d"'));
    same('<email>', $normalise('"mock@example.com"'));
    same('<url>', $normalise('"https://example.com/"'));
});

test('normaliser: account data, opaque queue blobs and empty objects', function () {
    same('<text>', Normaliser::normalise('Some Church', 'businessname'));
    same('<sender-id>', Normaliser::normalise('CoastEC', 'senderid'));
    same('<text>', Normaliser::normalise('ACMA note', 'acma_additional_details'));
    // Empty objects and arrays are the same after associative decoding.
    same([], Normaliser::normalise(json_decode('{}')));
    same([], Normaliser::normalise(json_decode('[]')));
    // Cellcast's queue bookkeeping keeps only the status the client reads.
    $jobInfo = Normaliser::normalise(json_decode('{"queue":1,"data":{"messageData":{"status":"queued","_id":"x"}}}'), 'jobInfo');
    same('{"data":{"messageData":{"status":"queued"}}}', json_encode($jobInfo));
});

// ── Pure units: state machine ─────────────────────────────────────────

test('state: scheduled → sent → delivered, cancelled and forced win', function () {
    $now = new DateTimeImmutable('2026-10-19 12:00:00');
    $state = new State(deliveryDelay: 10);
    $row = fn(array $overrides = []) => $overrides + [
        'cancelled_at' => null,
        'scheduled_send_at' => null,
        'created_at' => $now->modify('-30 seconds')->format('Y-m-d H:i:s.v'),
        'forced_status' => null,
        'forced_reason' => null,
    ];
    same(State::MSG_DELIVERED, $state->deriveMessage($row(), $now), 'sent 30s ago');
    same(State::MSG_SENT, $state->deriveMessage($row(['created_at' => $now->modify('-5 seconds')->format('Y-m-d H:i:s.v')]), $now), 'sent 5s ago');
    same(State::MSG_SCHEDULED, $state->deriveMessage($row(['scheduled_send_at' => $now->modify('+1 hour')->format('Y-m-d H:i:s.v')]), $now), 'scheduled a day ahead');
    same(State::MSG_CANCELLED, $state->deriveMessage($row(['cancelled_at' => $now->format('Y-m-d H:i:s.v')]), $now), 'cancelled wins');
    same(State::MSG_FAILED, $state->deriveMessage($row(['forced_status' => State::MSG_FAILED]), $now), 'forced status');
});

// ── The mock's replies vs the live recordings ─────────────────────────

Profile::loadProfiles(__DIR__ . '/../profiles');
$providers = Providers::all();
$wanted = array_slice($argv, 1) ?: array_keys($providers);

foreach ($wanted as $name) {
    if (!isset($providers[$name])) {
        fwrite(STDERR, "Unknown provider '$name'\n");
        exit(2);
    }
    $config = $providers[$name];

    test("$name: replies match recordings/$name/actual", function () use ($name, $config) {
        foreach ($config['mock_env'] as $key => $value) {
            putenv("$key=$value");
        }
        $actual = __DIR__ . "/../recordings/$name/actual";
        $produced = sys_get_temp_dir() . '/smsmock-test-' . bin2hex(random_bytes(4));

        $hooks = Profile::getByName($config['profile'])['profile'] ?? null;
        check($hooks !== null, "the recording profile {$config['profile']} must be registered");
        $state = new State(
            deliveryDelay: $hooks->deliveryDelay ?? 5,
            approvalDelay: $hooks->approvalDelay ?? 0,
        );
        $providerClass = $config['provider'];
        $transport = new InProcessTransport(
            provider: new $providerClass(),
            providerName: $name,
            profileName: $config['profile'],
            hooks: $hooks,
            store: new MemoryStore(),
            meta: new Meta(sys_get_temp_dir()),
            state: $state,
        );
        $cases = ($config['cases'])::fromEnv();
        (new Recorder($cases, $transport, $produced, includeSends: is_dir("$actual/send")))->run();

        $problems = [];
        $expectedFiles = recordingFiles($actual);
        $producedFiles = recordingFiles($produced);
        foreach (array_diff($expectedFiles, $producedFiles) as $missing) {
            $problems[] = "$missing: not produced by the mock";
        }
        foreach (array_diff($producedFiles, $expectedFiles) as $extra) {
            $problems[] = "$extra: produced but not recorded live";
        }
        foreach (array_intersect($expectedFiles, $producedFiles) as $file) {
            $expected = file_get_contents("$actual/$file");
            $got = file_get_contents("$produced/$file");
            if ($expected !== $got) {
                $problems[] = "$file: " . firstDifference($expected, $got);
            }
        }
        if ($problems !== []) {
            removeDir($produced);
            throw new RuntimeException(implode("\n", $problems));
        }
        removeDir($produced);
        check($expectedFiles !== [], "no recordings to compare in $actual");
        echo '      ' . count($expectedFiles) . " cases identical\n";
    });
}

// ── Run ───────────────────────────────────────────────────────────────

$failed = 0;
foreach ($tests as [$name, $fn]) {
    try {
        $fn();
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $name\n    " . str_replace("\n", "\n    ", $e->getMessage()) . "\n";
    }
}
echo "\n" . count($tests) - $failed . ' passed, ' . $failed . " failed\n";
exit($failed === 0 ? 0 : 1);
