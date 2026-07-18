<?php

namespace SmsMockServer;

/** Pure helper functions — no side effects, no I/O. */
final class Util
{
    /** Write a JSON response and exit. */
    public static function json(int $status, mixed $data): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
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
