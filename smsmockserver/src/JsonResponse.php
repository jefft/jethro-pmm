<?php

namespace SmsMockServer;

/**
 * Thrown instead of exiting when Util::json() is asked to collect responses
 * (in-process tests). Carries what would have been written to the wire.
 */
final class JsonResponse extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly mixed $data,
    ) {
        parent::__construct("JSON response $status");
    }
}
