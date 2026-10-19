<?php

namespace SmsMockServer\Provider;

use SmsMockServer\MessageStore;
use SmsMockServer\Meta;
use SmsMockServer\State;
use SmsMockServer\Profile;

/**
 * Context passed to every provider handler.
 */
final class Ctx
{
    public function __construct(
        public readonly string $providerName,
        public readonly string $profileName,
        public readonly MessageStore $store,
        public readonly ?Profile $profileHooks,
        public readonly Meta $meta,
        public readonly State $state,
        public readonly \DateTimeImmutable $now,
        /** Raw request body, injected by in-process tests; null = php://input */
        private readonly ?string $rawBody = null,
    ) {}

    public function rawBody(): string
    {
        return $this->rawBody ?? (string) file_get_contents('php://input');
    }

    /** @return array<string, mixed> The request's JSON body, or [] */
    public function jsonBody(): array
    {
        return json_decode($this->rawBody(), true) ?: [];
    }

    public function scopeKey(): string
    {
        return \SmsMockServer\Util::scopeKey($this->providerName, $this->profileName);
    }
}
