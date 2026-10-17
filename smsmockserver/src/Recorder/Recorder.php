<?php

namespace SmsMockServer\Recorder;

/**
 * Runs a provider's cases against a base URL — the live API or this mock —
 * and writes one pair of files per case:
 *   <name>.json      request and reply normalised (Normaliser), for diffing
 *   <name>.raw.json  the reply as received, phone numbers masked
 * Credentials are never written. Messages a run scheduled are cancelled
 * when it ends, even if a case fails.
 */
final class Recorder
{
    /** @var array<string, string> */
    private array $vars;

    /** @var list<string> Message IDs this run created */
    private array $runIds = [];

    /** @var list<string> Message IDs to cancel when the run ends */
    private array $toCancel = [];

    public function __construct(
        private readonly ProviderCases $cases,
        private readonly string $baseUrl,
        private readonly string $outDir,
        private readonly bool $includeSends,
    ) {
        $this->vars = $cases->vars();
    }

    /** @return int Number of cases recorded */
    public function run(): int
    {
        $this->clearOutDir();
        $recorded = 0;
        try {
            foreach ($this->cases->cases() as $case) {
                if (str_starts_with($case['name'], 'send/') && !$this->includeSends) continue;
                $this->record($case);
                $recorded++;
            }
        } finally {
            foreach ($this->toCancel as $id) {
                $cancel = $this->cases->cancelRequest($id);
                [$headers, $body] = $this->credentials(true, $cancel['body']);
                $this->http($cancel['method'], $cancel['path'], [], $body, $headers);
            }
        }
        return $recorded;
    }

    /** @param array<string, mixed> $case */
    private function record(array $case): void
    {
        $path = $this->fill($case['path']);
        $query = $this->fill($case['query'] ?? []);
        // The record keeps the case's own body: credentials are added to the
        // request actually sent, never written to disk.
        $body = isset($case['body']) ? $this->fill($case['body']) : null;
        [$headers, $sentBody] = $this->credentials($case['auth'] ?? true, $body);

        [$status, $raw] = $this->http($case['method'], $path, $query, $sentBody, $headers);
        $reply = json_decode($raw);
        if ($reply === null && trim($raw) !== 'null') {
            $reply = $raw; // not JSON: compare the text
        }

        foreach ($case['capture'] ?? [] as $var => $dotted) {
            $value = self::dig($reply, $dotted);
            if (!\is_string($value) || $value === '') {
                throw new \RuntimeException("{$case['name']}: nothing to capture at '$dotted'. Reply ($status): $raw");
            }
            $this->vars[$var] = $value;
        }
        if (isset($case['sentIds'])) {
            [$listPath, $idKey] = $case['sentIds'];
            foreach ((array) self::dig($reply, $listPath) as $item) {
                if (isset($item->$idKey)) {
                    $this->runIds[] = $this->toCancel[] = (string) $item->$idKey;
                }
            }
        }
        if (isset($case['onlyRunMessages']) && $reply instanceof \stdClass) {
            [$listPath, $idKey] = $case['onlyRunMessages'];
            $reply->$listPath = array_values(array_filter(
                (array) ($reply->$listPath ?? []),
                fn($item) => \in_array((string) ($item->$idKey ?? ''), $this->runIds, true),
            ));
        }

        $request = array_filter([
            'method' => $case['method'],
            'path' => $path,
            'query' => $query === [] ? null : (object) $query,
            'body' => $body === null ? null : json_decode(json_encode($body)),
            'auth' => ($case['auth'] ?? true) ? null : false,
        ], fn($v) => $v !== null);

        $this->write($case['name'] . '.json', [
            'request' => Normaliser::normalise((object) $request),
            'response' => ['status' => $status, 'body' => Normaliser::normalise($reply)],
        ]);
        $this->write($case['name'] . '.raw.json', [
            'request' => (object) $request,
            'response' => ['status' => $status, 'body' => $reply],
        ], maskNumbers: true);
    }

    /**
     * @param array<string, string> $query
     * @param list<string> $headers
     * @return array{int, string} HTTP status and body
     */
    private function http(string $method, string $path, array $query, ?array $body, array $headers): array
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

    /** Replace {var} placeholders throughout a string or array. */
    private function fill(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map($this->fill(...), $value);
        }
        if (!\is_string($value)) {
            return $value;
        }
        // A lone placeholder keeps the variable's type (e.g. v5's `schedule`
        // is a Unix timestamp, not a string).
        if (preg_match('/^\{(\w+)\}$/', $value, $m)) {
            if (!\array_key_exists($m[1], $this->vars)) {
                throw new \RuntimeException("Unknown placeholder {{$m[1]}}");
            }
            return $this->vars[$m[1]];
        }
        return preg_replace_callback('/\{(\w+)\}/', function (array $m) {
            if (!\array_key_exists($m[1], $this->vars)) {
                throw new \RuntimeException("Unknown placeholder {{$m[1]}}");
            }
            return (string) $this->vars[$m[1]];
        }, $value);
    }

    /** @return array{list<string>, ?array} Headers and body to send, credentials included */
    private function credentials(bool $auth, ?array $body): array
    {
        if (!$auth) return [[], $body];
        $fields = $this->cases->authBodyFields();
        return [$this->cases->authHeaders(), $fields === [] ? $body : array_merge($fields, $body ?? [])];
    }

    private static function dig(mixed $value, string $dotted): mixed
    {
        foreach (explode('.', $dotted) as $key) {
            $value = match (true) {
                $value instanceof \stdClass => $value->$key ?? null,
                \is_array($value) => $value[(int) $key] ?? null,
                default => null,
            };
        }
        return $value;
    }

    private function clearOutDir(): void
    {
        if (is_dir($this->outDir)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->outDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (str_ends_with($file->getFilename(), '.json')) unlink($file->getPathname());
            }
        }
    }

    private function write(string $name, array $document, bool $maskNumbers = false): void
    {
        $file = $this->outDir . '/' . $name;
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0777, true);
        }
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($maskNumbers) {
            // Australian mobiles, local or international: keep the prefix only.
            $json = preg_replace('/(?<!\d)(614|04)\d{8}(?!\d)/', '$1xxxxxxxx', $json);
        }
        file_put_contents($file, $json . "\n");
    }
}
