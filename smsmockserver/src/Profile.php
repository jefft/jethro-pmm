<?php

namespace SmsMockServer;

/**
 * Profile framework — hooks, endpoint overrides, and registry for test profiles.
 *
 * Profiles modify proxy behavior: they can force SMS statuses, control
 * registration flow, override endpoints, and adjust time delays.
 */
class Profile
{
    // ── Registry ───────────────────────────────────────────────────────

    /** @var array<string, array<string, Profile>> provider => name => Profile */
    private static array $registry = [];

    /** @var array<string, string> profileName => providerName */
    private static array $flatRegistry = [];

    /** Base directory from which profiles were loaded. Set by loadProfiles(). */
    public static string $profilesDir = '';

    /** Register a profile in the global registry. */
    public static function register(string $provider, string $name, self $profile): void
    {
        self::$registry[$provider][$name] = $profile;
        self::$flatRegistry[$name] = $provider;
        $profile->provider = $provider;
        $profile->name = $name;
    }

    /** Get a profile by (provider, name), or null. */
    public static function get(string $provider, string $name): ?self
    {
        return self::$registry[$provider][$name] ?? null;
    }

    /** Get a profile by name alone, with its provider. */
    public static function getByName(string $name): ?array
    {
        $provider = self::$flatRegistry[$name] ?? null;
        if ($provider === null) return null;
        $profile = self::$registry[$provider][$name] ?? null;
        if ($profile === null) return null;
        return ['provider' => $provider, 'profile' => $profile];
    }

    /** Return the full registry: provider => [name => Profile]. */
    public static function all(): array
    {
        return self::$registry;
    }

    /** Load all .smsmock.php profile files from a directory tree. */
    public static function loadProfiles(string $profilesDir): void
    {
        self::$profilesDir = realpath($profilesDir) ?: $profilesDir;
        if (!is_dir($profilesDir)) {
            return;
        }
        // Scan one level deep — profiles live in e.g. tests/functional/sms/
        foreach (array_merge(
            glob("$profilesDir/*.smsmock.php") ?: [],
            glob("$profilesDir/*/*.smsmock.php") ?: [],
        ) as $file) {
            require_once $file;
        }
    }

    // ── Instance ───────────────────────────────────────────────────────

    public string $provider = '';
    public string $name = '';

    /** @var int|null Override balance returned by account endpoints. */
    public ?int $balance = null;

    /** @var \DateInterval|int|null Override delivery delay. */
    public mixed $deliveryDelay = null;

    /** @var \DateInterval|int|null Override approval delay. */
    public mixed $approvalDelay = null;

    /**
     * Hook called before each SMS send. Receives a PendingSMS.
     * @var null|callable(PendingSMS): void
     */
    public $onSendHook = null;

    /**
     * Hook called before each registration. Receives a PendingRegistration.
     * @var null|callable(PendingRegistration): void
     */
    public $onRegisterHook = null;

    /**
     * Hook called before each cancel. Receives the message row.
     * @var null|callable(array): void
     */
    public $onCancelHook = null;

    /**
     * Hook called when OTP verification is requested.
     * @var null|callable(PendingOTPVerify): void
     */
    public $onVerifyOTPHook = null;

    /** @var array<string, Endpoint> "METHOD /path" => Endpoint configs */
    public array $endpoints = [];

    // ── Endpoint override ──────────────────────────────────────────────

    /** Create or retrieve an endpoint override for METHOD /path. */
    public function endpoint(string $method, string $path): EndpointBuilder
    {
        return new EndpointBuilder($this, $method, $path);
    }
}

// ── PendingSMS ────────────────────────────────────────────────────────

final class PendingSMS
{
    public string $sender;
    public string $destination;
    public string $body;
    public string $provider = 'cellcast';
    public ?\DateTimeImmutable $scheduledSendAt = null;
    private ?string $forcedStatus = null;
    private ?string $forcedReason = null;

    /** Force the message to a specific terminal status. Later calls win. */
    public function forceStatus(string $status, string $reason = ''): void
    {
        $this->forcedStatus = $status;
        $this->forcedReason = $reason;
    }

    public function forcedStatus(): ?string { return $this->forcedStatus; }
    public function forcedReason(): ?string { return $this->forcedReason; }
}

// ── PendingRegistration ───────────────────────────────────────────────

final class PendingRegistration
{
    public const ACTION_AUTO              = 'auto';
    public const ACTION_APPROVE           = 'approve';
    public const ACTION_APPROVE_AFTER     = 'approve_after';
    public const ACTION_REJECT            = 'reject';
    public const ACTION_PENDING_INDEFINITE = 'pending_indefinite';
    public const ACTION_REQUIRE_OTP       = 'require_otp';

    public string $provider = 'cellcast';
    public string $kind = 'number';
    public string $value = '';
    public string $displayName = '';
    public string $rawBody = '';

    private string $action = self::ACTION_AUTO;
    private mixed $actionData = null;
    private ?string $rejectReason = null;

    public function action(): string { return $this->action; }
    public function actionData(): mixed { return $this->actionData; }
    public function rejectReason(): ?string { return $this->rejectReason; }

    public function approve(): void { $this->action = self::ACTION_APPROVE; }
    public function approveAfter(int $seconds): void {
        $this->action = self::ACTION_APPROVE_AFTER;
        $this->actionData = $seconds;
    }
    public function reject(string $reason): void {
        $this->action = self::ACTION_REJECT;
        $this->rejectReason = $reason;
    }
    public function pendingIndefinite(): void { $this->action = self::ACTION_PENDING_INDEFINITE; }
    public function requireOTP(string $code): void {
        $this->action = self::ACTION_REQUIRE_OTP;
        $this->actionData = $code;
    }
}

// ── PendingOTPVerify ──────────────────────────────────────────────────

final class PendingOTPVerify
{
    public string $code;
    public string $kind;
    public string $value;

    public function __construct(string $code, string $kind, string $value)
    {
        $this->code = $code;
        $this->kind = $kind;
        $this->value = $value;
    }
}

// ── ProviderRequest ───────────────────────────────────────────────────

final class ProviderRequest
{
    public function __construct(
        public readonly string $body,
        public readonly array $query = [],
    ) {}

    public function bindJSON(): mixed
    {
        return json_decode($this->body, true);
    }
}

// ── Endpoint ──────────────────────────────────────────────────────────

final class Endpoint
{
    public const ACTION_RETURN_JSON = 'return_json';
    public const ACTION_RETURN_JSON_FUNC = 'return_json_func';
    public const ACTION_PASSTHROUGH = 'passthrough';
    public const ACTION_HANDLER = 'handler';

    public string $action = self::ACTION_RETURN_JSON;
    public int $status = 200;
    public mixed $data = null;
    /** @var null|callable(ProviderRequest): mixed */
    public $jsonFunc = null;
    /** @var null|callable(): void */
    public $handler = null;

    /** Set HTTP status code. Returns $this for chaining. */
    public function returnStatus(int $code): self
    {
        $this->status = $code;
        return $this;
    }
}

// ── EndpointBuilder ───────────────────────────────────────────────────

final class EndpointBuilder
{
    private string $key;

    public function __construct(
        private Profile $profile,
        string $method,
        string $path,
    ) {
        $this->key = strtoupper($method) . ' ' . $path;
    }

    /** Static JSON response. */
    public function returnJSON(mixed $data): Endpoint
    {
        $ep = new Endpoint();
        $ep->action = Endpoint::ACTION_RETURN_JSON;
        $ep->data = $data;
        $this->profile->endpoints[$this->key] = $ep;
        return $ep;
    }

    /** Dynamic JSON response via callback. */
    public function returnJSONFunc(callable $fn): Endpoint
    {
        $ep = new Endpoint();
        $ep->action = Endpoint::ACTION_RETURN_JSON_FUNC;
        $ep->jsonFunc = $fn;
        $this->profile->endpoints[$this->key] = $ep;
        return $ep;
    }

    /** Custom handler. */
    public function handler(callable $h): Endpoint
    {
        $ep = new Endpoint();
        $ep->action = Endpoint::ACTION_HANDLER;
        $ep->handler = $h;
        $this->profile->endpoints[$this->key] = $ep;
        return $ep;
    }

    /** Fall through to simulator default. */
    public function passthrough(): Endpoint
    {
        $ep = new Endpoint();
        $ep->action = Endpoint::ACTION_PASSTHROUGH;
        $this->profile->endpoints[$this->key] = $ep;
        return $ep;
    }

}
