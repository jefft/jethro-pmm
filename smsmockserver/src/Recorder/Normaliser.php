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
 *   their format is kept: "<uuid>", "<24-hex>", "<Y-m-d H:i:s>", "<msisdn
 *   614…>", "<next_page>", … Literal strings (statuses, error messages)
 *   must match exactly.
 * - two keys hold per-account values rather than API contract:
 *   `acma_additional_details` ("<text>") and an alphanumeric `senderid`
 *   ("<sender-id>"); phone-number values are tokenised by format anyway.
 *
 * Input is json_decode()d without $associative, so {} and [] stay distinct.
 */
final class Normaliser
{
    /** Volatile string formats: pattern => placeholder. */
    private const FORMATS = [
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i' => '<uuid>',
        '/^[0-9a-f]{24}$/i' => '<24-hex>',
        '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/' => '<Y-m-d H:i:s>',
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/' => '<Y-m-d\TH:i:s\Z>',
        '/^\d{4}-\d{2}-\d{2}$/' => '<Y-m-d>',
        '/^614\d{8}$/' => '<msisdn 614…>',
        '/^04\d{8}$/' => '<msisdn 04…>',
        '#^/?api/v5/[a-z]+/?\?after=[0-9a-f]*$#i' => '<next_page>',
    ];

    public static function normalise(mixed $value, ?string $key = null): mixed
    {
        if ($value instanceof \stdClass) {
            $out = [];
            foreach (get_object_vars($value) as $itemKey => $item) {
                $out[$itemKey] = self::normalise($item, $itemKey);
            }
            ksort($out, SORT_STRING);
            return (object) $out;
        }
        if (\is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                $normalised = self::normalise($item, $key);
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
            // A request path ending in the id a case captured (/sms/<id>).
            if (preg_match('#^(/.*/)([0-9a-f]{24}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$#i', $value, $m)) {
                return $m[1] . (\strlen($m[2]) === 24 ? '<24-hex>' : '<uuid>');
            }
            if ($key === 'acma_additional_details') return '<text>';
            if ($key === 'senderid') return '<sender-id>';
        }
        return $value;
    }
}
