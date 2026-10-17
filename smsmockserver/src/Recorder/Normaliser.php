<?php

namespace SmsMockServer\Recorder;

/**
 * Reduces a decoded JSON value to what a live API and the mock must agree
 * on, so that recordings of the two can be diffed:
 *
 * - objects keep every key (sorted); lists become the sorted set of their
 *   distinct normalised items, so 3 history rows compare equal to 40;
 * - numbers become "<int>" / "<float>" (balances, counts and costs differ
 *   between accounts); booleans and null stay literal;
 * - strings keep their value unless they are volatile, in which case only
 *   their format is kept: "<uuid>", "<Y-m-d H:i:s>", "<msisdn 614…>", …
 *   Literal strings (statuses, error messages) must match exactly.
 *
 * Input is json_decode()d without $associative, so {} and [] stay distinct.
 */
final class Normaliser
{
    /** Volatile string formats: pattern => placeholder. */
    private const FORMATS = [
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i' => '<uuid>',
        '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/' => '<Y-m-d H:i:s>',
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/' => '<Y-m-d\TH:i:s\Z>',
        '/^\d{4}-\d{2}-\d{2}$/' => '<Y-m-d>',
        '/^614\d{8}$/' => '<msisdn 614…>',
        '/^04\d{8}$/' => '<msisdn 04…>',
    ];

    public static function normalise(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $out = [];
            foreach (get_object_vars($value) as $key => $item) {
                $out[$key] = self::normalise($item);
            }
            ksort($out, SORT_STRING);
            return (object) $out;
        }
        if (\is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                $normalised = self::normalise($item);
                $items[json_encode($normalised)] = $normalised;
            }
            ksort($items, SORT_STRING);
            return array_values($items);
        }
        if (\is_int($value)) return '<int>';
        if (\is_float($value)) return '<float>';
        if (\is_string($value)) {
            foreach (self::FORMATS as $pattern => $placeholder) {
                if (preg_match($pattern, $value)) return $placeholder;
            }
        }
        return $value;
    }
}
