<?php

namespace SmsMockServer\Recorder;

/**
 * Runs a provider's cases through a Transport — the live API, the running
 * mock (CurlTransport), or the handlers in-process (InProcessTransport) —
 * and writes one pair of files per case:
 *   <name>.json      request and reply normalised (Normaliser), for diffing
 *   <name>.raw.json  the reply as received, phone numbers masked and
 *                    account/people values (names, emails, business details)
 *                    blanked, as are vendor-internal queue blobs
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
        private readonly Transport $transport,
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
                $this->transport->send($cancel['method'], $cancel['path'], [], $body, $headers);
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

        [$status, $raw] = $this->transport->send($case['method'], $path, $query, $sentBody, $headers);
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
            $filtered = array_values(array_filter(
                (array) self::dig($reply, $listPath),
                fn($item) => \in_array((string) ($item->$idKey ?? ''), $this->runIds, true),
            ));
            self::setPath($reply, $listPath, $filtered);
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

    /** Assign a dotted path in a decoded JSON document. */
    private static function setPath(mixed &$doc, string $dotted, mixed $value): void
    {
        $keys = explode('.', $dotted);
        $last = array_pop($keys);
        $node = &$doc;
        foreach ($keys as $key) {
            if ($node instanceof \stdClass) {
                $node = &$node->$key;
            } else {
                $node = &$node[(int) $key];
            }
        }
        if ($node instanceof \stdClass) {
            $node->$last = $value;
        } else {
            $node[(int) $last] = $value;
        }
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
        if ($maskNumbers) {
            $document = self::redact($document);
        }
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($maskNumbers) {
            // Australian mobiles, local, international or national: keep the prefix only.
            $json = preg_replace('/(?<!\d)(614|04|4)\d{8}(?!\d)/', '$1xxxxxxxx', $json);
        }
        file_put_contents($file, $json . "\n");
    }

    /** Blank the account/people values the normalised files tokenise, so a
     *  committed .raw.json carries no names, emails or business details. */
    private static function redact(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $out = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                if ($key === 'jobInfo') {
                    $out->$key = '<redacted>';
                } else {
                    $out->$key = \in_array($key, Normaliser::TEXT_KEYS, true) && \is_scalar($item)
                        ? '<redacted>'
                        : self::redact($item);
                }
            }
            return $out;
        }
        if (\is_array($value)) {
            return array_map(self::redact(...), $value);
        }
        return $value;
    }
}
