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

    /**
     * The "METHOD /path" routes handle() dispatches, for the recorder
     * coverage check (every route needs a recorded case or must be marked
     * unrecordable by the provider's cases class). Parameterised path
     * segments are written as {id}. Keep in sync with handle().
     *
     * @return list<string>
     */
    public static function routes(): array;
}
