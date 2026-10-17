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
 *   their format is kept: "<uuid>", "<24-hex>", "<iso-ms>", "<Y-m-d H:i:s>",
 *   "<msisdn 614…>", "<next_page>", "<email>", "<url>", … Literal strings
 *   (statuses, error messages) must match exactly.
 * - keys in TEXT_KEYS hold per-account values rather than API contract and
 *   normalise to "<text>"; an alphanumeric `senderid` becomes "<sender-id>".
 * - empty objects become [] (clients decode associatively, where it is the
 *   same empty array).
 * - Cellcast's queue `jobInfo` keeps only data.messageData.status, the one
 *   part the client uses; the rest is vendor bookkeeping.
 *
 * Input is json_decode()d without $associative, so a non-empty {} stays an
 * object; empty ones become [] as noted above.
 */
final class Normaliser
{
    /** Volatile string formats: pattern => placeholder. */
    private const FORMATS = [
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i' => '<uuid>',
        '/^[0-9a-f]{24}$/i' => '<24-hex>',
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/' => '<iso-ms>',
        '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/' => '<Y-m-d H:i:s>',
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/' => '<Y-m-d\TH:i:s\Z>',
        '/^\d{4}-\d{2}-\d{2}$/' => '<Y-m-d>',
        '/^614\d{8}$/' => '<msisdn 614…>',
        '/^04\d{8}$/' => '<msisdn 04…>',
        '/^4\d{8}$/' => '<msisdn 4…>',
        '/^[^@\s]+@[^@\s]+\.[^@\s]+$/' => '<email>',
        '/^https?:\/\//' => '<url>',
        '#^/?api/v5/[a-z]+/?\?after=[0-9a-f]*$#i' => '<next_page>',
    ];

    /**
     * Keys whose string values are account/people data rather than API
     * contract, so only their presence and type are compared.
     */
    public const TEXT_KEYS = [
        'account_name', 'account_email', 'businessname', 'descriptionInternal',
        'purposeOfUse', 'name', 'firstName', 'lastName', 'email', 'abn',
        'website', 'address', 'acma_additional_details', 'request_name', 'stack',
    ];

    public static function normalise(mixed $value, ?string $key = null): mixed
    {
        if ($value instanceof \stdClass) {
            // Cellcast's queue bookkeeping: only messageData.status is contract
            // (it decides SCHEDULED vs SENT); the rest is vendor internals.
            if ($key === 'jobInfo') {
                return (object) ['data' => (object) ['messageData' => (object) [
                    'status' => self::normalise($value->data->messageData->status ?? null, 'status'),
                ]]];
            }
            $out = [];
            foreach (get_object_vars($value) as $itemKey => $item) {
                $out[$itemKey] = self::normalise($item, $itemKey);
            }
            ksort($out, SORT_STRING);
            // Clients decode associatively, where {} and [] are the same empty array.
            return $out === [] ? [] : (object) $out;
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
            if (\in_array($key, self::TEXT_KEYS, true)) return '<text>';
            if ($key === 'senderid') return '<sender-id>';
        }
        return $value;
    }
}
