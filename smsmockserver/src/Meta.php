<?php

namespace SmsMockServer;

/**
 * Per-scope request recording and /meta/ HTTP handlers.
 *
 * Records every non-meta request (method + URI + match key) and POST body
 * per (provider, profile) scope to JSON files under TMPDIR/smsmockserver/.
 * Thread-safety via per-scope flock.
 */
final class Meta
{
    private string $rootDir;

    /** @var array<string, resource> per-scope file handles */
    private array $postsFH = [];
    private array $requestsFH = [];

    public function __construct(string $tmpDir)
    {
        $this->rootDir = rtrim($tmpDir, '/') . '/smsmockserver';
        if (!is_dir($this->rootDir)) {
            mkdir($this->rootDir, 0777, true);
        }
    }

    private function dir(string $scope): string
    {
        $dir = $this->rootDir . '/' . $scope;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    // ── Request tracking ───────────────────────────────────────────────

    public function recordRequest(string $scope, string $method, string $uri, string $matchKey): void
    {
        $file = $this->dir($scope) . '/requests.json';
        $fh = fopen($file, 'c+');
        flock($fh, LOCK_EX);
        $data = [];
        $stat = fstat($fh);
        if ($stat['size'] > 0) {
            $data = json_decode(stream_get_contents($fh), true) ?: [];
        }
        $data['requests'] ??= [];
        $data['requests'][] = [
            'method' => $method,
            'uri' => $uri,
            'matchKey' => $matchKey,
            'time' => date('c'),
        ];
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /** @return list<array{method:string,uri:string,matchKey:string,time:string}>|null */
    public function getLastRequest(string $scope): ?array
    {
        $file = $this->dir($scope) . '/requests.json';
        if (!file_exists($file)) return null;
        $raw = file_get_contents($file);
        $data = json_decode($raw, true);
        return $data['requests'] ?? null;
    }

    public function deleteLastRequest(string $scope): void
    {
        @unlink($this->dir($scope) . '/requests.json');
    }

    // ── POST tracking ──────────────────────────────────────────────────

    public function recordPost(string $scope, string $uri, string $body): void
    {
        $parsed = null;
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $parsed = $decoded;
            }
        }
        $entry = [
            'url' => $uri,
            'rawBody' => $body,
            'json' => $parsed,
            'time' => date('c'),
        ];
        $file = $this->dir($scope) . '/lastPost.json';
        file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function getLastPost(string $scope): ?array
    {
        $file = $this->dir($scope) . '/lastPost.json';
        if (!file_exists($file)) return null;
        $raw = file_get_contents($file);
        $data = json_decode($raw, true);
        return is_array($data) && isset($data['url']) ? $data : null;
    }

    public function deleteLastPost(string $scope): void
    {
        @unlink($this->dir($scope) . '/lastPost.json');
    }

    // ── Meta HTTP handlers ─────────────────────────────────────────────

    /**
     * Handle GET/DELETE /meta/... for a given scope.
     * Called by the router when a /meta/ path is detected.
     */
    public function handleMeta(
        string $profile,
        string $provider,
        Store $store,
        State $state,
        \DateTimeImmutable $now,
    ): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_SERVER['PATH_INFO'] ?? '/';
        $scope = Util::scopeKey($provider, $profile);

        if ($method === 'DELETE') {
            if ($path === '/lastPost') {
                $this->deleteLastPost($scope);
                Util::json(200, ['ok' => true]);
            }
            if ($path === '/lastRequest') {
                $this->deleteLastRequest($scope);
                Util::json(200, ['ok' => true]);
            }
            if ($path === '/sms') {
                $store->deleteMessagesForScope($profile, $provider);
                Util::json(200, ['ok' => true]);
            }
            if ($path === '/registrations') {
                $store->deleteRegistrationsForScope($profile, $provider);
                Util::json(200, ['ok' => true]);
            }
            Util::json(404, ['error' => 'unknown meta DELETE endpoint']);
        }

        if ($method !== 'GET') {
            Util::json(405, ['error' => 'method not allowed']);
        }

        switch ($path) {
            case '/lastPost':
                $post = $this->getLastPost($scope);
                Util::json(200, $post);
            case '/lastRequest':
                $reqs = $this->getLastRequest($scope);
                Util::json(200, $reqs);
            case '/sms':
                $this->handleListSms($profile, $provider, $store, $state, $now);
            case '/registrations':
                $this->handleListRegistrations($profile, $provider, $store, $state, $now);
            default:
                Util::json(404, ['error' => 'unknown meta endpoint']);
        }
    }

    private function handleListSms(
        string $profile, string $provider, Store $store, State $state, \DateTimeImmutable $now,
    ): never {
        $rows = $store->listMessagesForScope($profile, $provider);
        $out = [];
        foreach ($rows as $row) {
            $status = $state->deriveMessage($row, $now);
            $out[] = [
                'id' => (int) $row['id'],
                'status' => $status,
                'sender' => $row['sender'],
                'destination' => $row['destination'],
                'body' => $row['body'],
                'created_at' => $row['created_at'],
                'scheduled_send_at' => $row['scheduled_send_at'],
                'cancelled_at' => $row['cancelled_at'],
                'delivery_ts' => $state->deliveryTs($status, $row['created_at'], $now),
            ];
        }
        Util::json(200, ['messages' => $out]);
    }

    private function handleListRegistrations(
        string $profile, string $provider, Store $store, State $state, \DateTimeImmutable $now,
    ): never {
        $rows = $store->listRegistrationsForScope($profile, $provider);
        $out = [];
        foreach ($rows as $row) {
            $approval = $state->deriveApproval($row, $now);
            $out[] = [
                'id' => (int) $row['id'],
                'kind' => $row['kind'],
                'value' => $row['value'],
                'display_name' => $row['display_name'],
                'approval' => $approval,
                'otp_code' => $row['otp_code'],
                'otp_verified_at' => $row['otp_verified_at'],
                'approved_at' => $row['approved_at'],
                'rejected_at' => $row['rejected_at'],
                'rejected_reason' => $row['rejected_reason'],
                'created_at' => $row['created_at'],
            ];
        }
        Util::json(200, ['registrations' => $out]);
    }
}
