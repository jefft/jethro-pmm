<?php

namespace SmsMockServer\Recorder;

/**
 * One provider's recordable API exchanges, and how to authenticate them.
 *
 * A case is an array:
 *   name             file name under the output dir; "send/…" for cases that
 *                    deliver a real SMS (recorded only with --send)
 *   method, path     the request; path, query and body values may hold
 *                    {var} placeholders (provider vars and captures)
 *   query?, body?    query parameters; JSON body (sent with Content-Type)
 *   auth?            false to send no credentials (default true)
 *   capture?         [var => 'dotted.path'] into the reply, for later cases
 *   sentIds?         [listPath, idKey]: the reply lists messages this run
 *                    created; they are cancelled when the run ends
 *   onlyRunMessages? [listPath, idKey]: keep only list items this run
 *                    created, so account history can't leak into recordings
 */
interface ProviderCases
{
    /** @return list<string> Header lines that authenticate a request */
    public function authHeaders(): array;

    /** @return array<string, string> Placeholder values, e.g. the test number */
    public function vars(): array;

    /** @return list<array<string, mixed>> In run order */
    public function cases(): array;

    /** @return array{method: string, path: string, body: array<string, mixed>} Cancels a message this run created */
    public function cancelRequest(string $messageId): array;
}
