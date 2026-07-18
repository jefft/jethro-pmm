<?php

namespace SmsMockServer;

use SmsMockServer\Provider\Ctx;
use SmsMockServer\Provider\ProviderInterface;

/**
 * Top-level HTTP handler — parses URL grammar, resolves provider + profile,
 * handles /meta/ endpoints, and delegates to the correct provider.
 */
final class Router
{
    /** @var array<string, ProviderInterface> */
    private array $providers;

    public function __construct(
        public readonly Store $store,
        public readonly Meta $meta,
        public readonly State $state,
        ProviderInterface ...$providers,
    ) {
        foreach ($providers as $p) {
            $this->providers[$p->name()] = $p;
        }
    }

    /** Handle the incoming request. Reads from PHP superglobals. */
    public function dispatch(): void
    {
        try {
            $this->doDispatch();
        } catch (\Throwable $e) {
            error_log("PANIC: " . $e->getMessage());
            Util::json(500, ['error' => 'internal: panic']);
        }
    }

    private function doDispatch(): void
    {
        $path = ltrim($_SERVER['PATH_INFO'] ?? '/', '/');
        $parts = $path === '' ? [] : explode('/', $path);

        // Index page
        if ($parts === [] || $parts[0] === '') {
            $this->renderIndexPage();
        }

        // Built-in default providers: /5centsms/... or /cellcast/...
        $entry = Profile::getByName($parts[0]);
        if ($entry !== null) {
            $profileName = $parts[0];
            $providerName = $entry['provider'];
            $hooks = $entry['profile'];
            $p = $this->providers[$providerName] ?? null;
            if ($p === null) Util::json(404, ['error' => "unknown provider: $providerName"]);
            $remainder = $this->buildRemainder($parts, 1);
            if ($remainder === '/' || $remainder === '') {
                $this->renderProfileInfo($providerName, $profileName);
            }
            $this->doDispatchToProvider($p, $providerName, $profileName, $hooks, $remainder);
        }

        // Test scenario profiles: /tests/functional/{dir}/{scenario}/...
        if (($parts[0] ?? '') === 'tests' && ($parts[1] ?? '') === 'functional') {
            $profileName = $parts[3] ?? '';
            if ($profileName === '') {
                $this->renderIndexPage();
            }
            $entry = Profile::getByName($profileName);
            if ($entry === null) {
                $this->renderProfileNotFound($profileName);
            }
            $providerName = $entry['provider'];
            $hooks = $entry['profile'];
            $p = $this->providers[$providerName] ?? null;
            if ($p === null) Util::json(404, ['error' => "unknown provider: $providerName"]);
            $remainder = $this->buildRemainder($parts, 4);
            if ($remainder === '/' || $remainder === '') {
                $this->renderProfileInfo($providerName, $profileName);
            }
            $this->doDispatchToProvider($p, $providerName, $profileName, $hooks, $remainder);
        }

        // Anything else — show index
        $this->renderIndexPage();
    }


    private function buildRemainder(array $parts, int $consumed): string
    {
        if ($consumed >= count($parts)) return '/';
        return '/' . implode('/', array_slice($parts, $consumed));
    }

    private function doDispatchToProvider(
        ProviderInterface $p,
        string $providerName,
        string $profileName,
        ?Profile $hooks,
        string $remainder,
    ): void {
        $remainderForRecord = $remainder;
        if (!empty($_SERVER['QUERY_STRING'])) {
            $remainderForRecord .= '?' . $_SERVER['QUERY_STRING'];
        }

        $scopeKey = Util::scopeKey($providerName, $profileName);

        if (str_starts_with($remainder, '/meta')) {
            $metaPath = substr($remainder, 5);
            $_SERVER['PATH_INFO'] = $metaPath === '' ? '/' : $metaPath;
            $this->meta->handleMeta($profileName, $providerName, $this->store, $this->state, new \DateTimeImmutable('now'));
        }

        $_SERVER['PATH_INFO'] = $remainder;
        $this->meta->recordRequest($scopeKey, $_SERVER['REQUEST_METHOD'], $remainderForRecord, '(simulator)');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $body = file_get_contents('php://input');
            if ($body !== false && $body !== '') {
                $this->meta->recordPost($scopeKey, $remainder, $body);
            }
        }

        $now = new \DateTimeImmutable('now');
        $state = $this->state;
        if ($hooks !== null && ($hooks->deliveryDelay !== null || $hooks->approvalDelay !== null)) {
            $state = new State(
                deliveryDelay: $hooks->deliveryDelay ?? $this->state->deliveryDelay,
                approvalDelay: $hooks->approvalDelay ?? $this->state->approvalDelay,
                scheduledStayScheduled: $this->state->scheduledStayScheduled,
            );
        }

        $ctx = new Ctx(
            providerName: $providerName,
            profileName: $profileName,
            store: $this->store,
            profileHooks: $hooks,
            meta: $this->meta,
            state: $state,
            now: $now,
        );

        $p->handle($ctx);
    }

    // ── HTML pages ──────────────────────────────────────────────────────

    /** Render the root index page listing default providers and test profiles. */
    private function renderIndexPage(): never
    {
        // Built-in default providers
        $defaultsHtml = '';
        foreach (['5centsms', 'cellcast'] as $name) {
            if (isset($this->providers[$name])) {
                $defaultsHtml .= sprintf(
                    '<li><a href="%1$s/"><code>%1$s</code></a> <span class="desc">default, no overrides (balance: 12345)</span></li>',
                    self::h($name),
                );
            }
        }

        // Test profiles from registry (skip built-in defaults)
        $registry = Profile::all();
        $profilesDir = Profile::$profilesDir;
        $testHtml = '';
        foreach ($registry as $providerName => $profiles) {
            $profileRows = '';
            foreach ($profiles as $profileName => $profile) {
                // Skip built-in default profiles — shown in the defaults section
                if ($profileName === $providerName) continue;
                $filePath = $profilesDir !== ''
                    ? "$profilesDir/$profileName.smsmock.php"
                    : "$profileName.smsmock.php";
                $profileRows .= sprintf(
                    '<li><a href="tests/functional/sms/sms/%s"><code>%s</code></a> <span class="path">%s</span></li>',
                    self::h($profileName),
                    self::h($profileName),
                    self::h($filePath),
                );
            }
            if ($profileRows === '') continue;
            $profileCount = count(array_filter(array_keys($profiles), fn($n) => $n !== $providerName));
            $label = $profileCount === 1 ? 'profile' : 'profiles';
            $testHtml .= sprintf(
                '<section><h2>%s <span class="count">(%d %s)</span></h2><ul>%s</ul></section>',
                self::h($providerName),
                $profileCount,
                $label,
                $profileRows,
            );
        }

        if ($testHtml === '') {
            $testHtml = '<p class="empty">No test profiles registered.</p>';
        }

        self::htmlPage('SMS Mock Server', <<<HTML
            <section>
                <h2>Default Providers</h2>
                <ul>$defaultsHtml</ul>
            </section>
            <p>Default providers emulate the real SMS API with no overrides — plain passthrough with a static balance.</p>
            <hr>
            <h2>Test Profiles</h2>
            $testHtml
            <hr>
            <p class="meta">Meta endpoints: append <code>/meta/lastRequest</code> or <code>/meta/lastPost</code> to a profile or default provider URL.</p>
        HTML);
    }

    /** Render an error page for a non-existent profile. */
    private function renderProfileNotFound(string $profileName): never
    {
        // Collect all known profile names across all providers
        $allNames = [];
        foreach (Profile::all() as $profiles) {
            foreach ($profiles as $name => $p) {
                $allNames[] = $name;
            }
        }
        sort($allNames);

        $listHtml = '';
        if ($allNames !== []) {
            $items = '';
            foreach ($allNames as $name) {
                $isBuiltin = in_array($name, ['5centsms', 'cellcast'], true);
                $url = $isBuiltin ? "$name/" : "tests/functional/sms/sms/$name";
                $items .= sprintf(
                    '<li><a href="%s"><code>%s</code></a></li>',
                    self::h($url),
                    self::h($name),
                );
            }
            $listHtml = sprintf('<ul>%s</ul>', $items);
        } else {
            $listHtml = '<p>No profiles registered.</p>';
        }
        $safeProfile = self::h($profileName);

        self::htmlPage('Profile Not Found', <<<HTML
            <p class="error">Profile <code>$safeProfile</code> not found.</p>
            <p>Available profiles:</p>
            $listHtml
            <p><a href="./">&larr; Back to mock index</a></p>
        HTML, 404);
    }

    /** Render an informational page for a valid profile at its root URL. */
    private function renderProfileInfo(string $providerName, string $profileName): never
    {
        $profilesDir = Profile::$profilesDir;
        $isBuiltin = $profileName === $providerName;
        $urlBase = $isBuiltin ? $profileName : "tests/functional/sms/sms/$profileName";

        if ($isBuiltin) {
            $filePath = '(built-in — no file)';
        } else {
            $filePath = $profilesDir !== ''
                ? "$profilesDir/$profileName.smsmock.php"
                : "$profileName.smsmock.php";
        }

        $safeProvider = self::h($providerName);
        $safeProfile = self::h($profileName);
        $safePath = self::h($filePath);

        self::htmlPage("$safeProvider / $safeProfile", <<<HTML
            <dl>
                <dt>Provider</dt><dd><code>$safeProvider</code></dd>
                <dt>Profile</dt><dd><code>$safeProfile</code></dd>
                <dt>File</dt><dd><span class="path">$safePath</span></dd>
            </dl>
            <p>Meta endpoints (GET to read, DELETE to clear):</p>
            <ul>
                <li><a href="$urlBase/meta/lastRequest">/meta/lastRequest</a></li>
                <li><a href="$urlBase/meta/lastPost">/meta/lastPost</a></li>
            </ul>
            <p><a href="./">&larr; Back to mock index</a></p>
        HTML);
    }



    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** Emit a full HTML page with basic styling. */
    private static function htmlPage(string $title, string $body, int $status = 200): never
    {
        $htmlTitle = self::h($title);
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$htmlTitle</title>
<style>
    body { font-family: system-ui, sans-serif; max-width: 48rem; margin: 2rem auto; padding: 0 1rem; color: #1a1a1a; background: #fafafa; }
    h1 { font-size: 1.5rem; border-bottom: 2px solid #ccc; padding-bottom: 0.25rem; }
    h2 { font-size: 1.15rem; margin: 1.5rem 0 0.5rem; }
    ul { margin: 0.25rem 0; padding-left: 1.5rem; }
    li { margin: 0.25rem 0; }
    code { background: #e8e8e8; padding: 0.1em 0.3em; border-radius: 3px; font-size: 0.9em; }
    .path { color: #666; font-size: 0.85em; }
    .count { color: #888; font-weight: normal; }
    .empty, .meta { color: #888; }
    .error { color: #c00; font-weight: 600; }
    a { color: #2563eb; }
    hr { border: none; border-top: 1px solid #ddd; margin: 1.5rem 0; }
    section { margin-bottom: 1rem; }
</style>
</head>
<body>
<h1>$htmlTitle</h1>
$body
</body>
</html>
HTML;
        exit;
    }
}
