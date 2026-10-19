<?php

namespace SmsMockServer;

/** Pure helper functions — no side effects, no I/O. */
final class Util
{
    /** When true, json() throws JsonResponse instead of writing and exiting (tests). */
    private static bool $collectResponses = false;

    public static function collectResponses(bool $collect): void
    {
        self::$collectResponses = $collect;
    }

    /** Send the JSON content type, unless responses are being collected. */
    public static function jsonHeader(): void
    {
        if (!self::$collectResponses) {
            header('Content-Type: application/json');
        }
    }

    /**
     * Write a JSON response and exit; with collecting enabled, throw it
     * instead so a caller can inspect it.
     */
    public static function json(int $status, mixed $data): never
    {
        if (self::$collectResponses) {
            throw new JsonResponse($status, $data);
        }
        http_response_code($status);
        \SmsMockServer\Util::jsonHeader();
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Generate random hex string of $n bytes (2*$n chars). */
    public static function randomHex(int $n = 6): string
    {
        return bin2hex(random_bytes($n));
    }

    /** Split path on /, discarding empty segments. */
    public static function splitPath(string $path): array
    {
        return array_values(array_filter(explode('/', $path), fn(string $s) => $s !== ''));
    }

    /** Parse schedule timestamp, return DateTimeImmutable or null. */
    public static function parseScheduleAt(?string $s): ?\DateTimeImmutable
    {
        if ($s === null || $s === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $s);
        return $dt ?: null;
    }

    /** Build a scope key "provider" or "provider/profile". */
    public static function scopeKey(string $provider, string $profile): string
    {
        return $profile === '' ? $provider : "$provider/$profile";
    }
}
