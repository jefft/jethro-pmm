<?php

namespace SmsMockServer\Provider;

use SmsMockServer\Store;
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
        public readonly Store $store,
        public readonly ?Profile $profileHooks,
        public readonly Meta $meta,
        public readonly State $state,
        public readonly \DateTimeImmutable $now,
    ) {}

    public function scopeKey(): string
    {
        return \SmsMockServer\Util::scopeKey($this->providerName, $this->profileName);
    }
}
