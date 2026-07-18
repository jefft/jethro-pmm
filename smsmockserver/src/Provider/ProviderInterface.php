<?php

namespace SmsMockServer\Provider;

/**
 * Each provider handles HTTP requests for its namespace.
 */
interface ProviderInterface
{
    public function name(): string;

    /** Handle an incoming request. Reads from PHP superglobals. */
    public function handle(Ctx $ctx): void;
}
