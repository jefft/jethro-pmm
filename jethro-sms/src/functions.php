<?php

declare(strict_types=1);
/**
 * Standalone functions used across the SMS engine.
 *
 * @see SendSummary
 * @see SmsStatus
 * @see SmsDelivery
 */


namespace Sms;


function statusFromV5Code(int $code, string $statusText): SmsStatus
{
    if ($code === 1527) {
        return SmsStatus::SCHEDULED;
    }
    if ($code === 1004 && $statusText === 'Test Message') {
        return SmsStatus::TEST_MESSAGE;
    }
    return match ($code) {
        1000 => SmsStatus::QUEUED,
        1001 => SmsStatus::SENT,
        1002 => SmsStatus::DELIVERED,
        1003 => SmsStatus::FAILED,
        1004 => SmsStatus::DELIVERY_IN_PROGRESS,
        1005 => SmsStatus::SCHEDULED,
        1007 => SmsStatus::CANCELLED,
        1011 => SmsStatus::SENDING,
        default => SmsStatus::FAILED,
    };
}


// ---------------------------------------------------------------------------
// sendSummary — aggregate per-recipient results for display/logging
// ---------------------------------------------------------------------------

/**
 * Aggregate per-recipient SmsDelivery results into a SendSummary.
 *
 * This bridges provider::send() (which returns SmsDelivery[]) and callers
 * that only need the high-level outcome (sendSms(), logToDatabase(),
 * formatSendSummary(), AJAX/CLI dispatch).
 *
 * Callers needing per-recipient detail should use the raw SmsDelivery[]
 * directly rather than calling this function.
 *
 * Example with SMS Broadcast results:
 *
 *   $results = [
 *     new SmsBroadcastSmsDelivery(new PhoneNumber("0402511927"), "OK:61402511927:1252164015"),
 *     new SmsBroadcastSmsDelivery(new PhoneNumber("0402511928"), "BAD:61402511928:Blocked"),
 *   ];
 *   $summary = sendSummary($results, $recipients);
 *   // → PartialSuccess(recipients: [0402511927], failures: [0402511928], remoteId: "1252164015")
 *
 * Decision tree:
 *   - Empty results → Failed("No results returned from provider")
 *   - Positional fast path (shared-mobile support): when count($results) ===
 *     count($recipients) AND every $results[$i]->recipient()->value ===
 *     $recipients[$i]->getPhoneNumber()->value, pair result i with recipient i.
 *     This correctly attributes deliveries when multiple recipients share a
 *     phone number (e.g. spouses, parent/child) — the phone-keyed map below
 *     would collapse them to the last entry.
 *   - Phone-keyed fallback (count mismatch or phone sanity check failed):
 *       - Build recipient map, iterate results:
 *           - Recipient NOT in original list  → skip (gateway returned extras)
 *           - status()->isOk()             → successes[]
 *           - !status()->isOk()            → failures[]
 *   - failures empty, successes non-empty → AllSent
 *   - failures empty, successes empty     → Failed("No recipients matched in gateway response")
 *   - successes empty, failures non-empty → Failed with first result's raw response
 *   - otherwise                           → PartialSuccess
 *
 * @param SmsDelivery[] $results Per-recipient results from provider
 * @param SmsRecipient[] $recipients Original recipients passed to send()
 */
function sendSummary(array $results, array $recipients): SendSummary
{
    if ($results === []) {
        return new Failed(error: 'No results returned from provider');
    }

    // Re-index both arrays so positional checks are reliable regardless of
    // how the caller constructed them.
    $results    = array_values($results);
    $recipients = array_values($recipients);

    // Positional fast path: every provider parser returns deliveries in
    // request order (one per recipient).  When counts match AND every
    // result's phone equals the corresponding recipient's phone, pair by
    // position.  This is the only correct strategy when recipients share a
    // phone number — a phone-keyed map would collapse them.
    $usePositional = false;
    if (count($results) === count($recipients)) {
        $usePositional = true;
        foreach ($results as $i => $r) {
            if ($r->recipient()->value !== $recipients[$i]->getPhoneNumber()->value) {
                $usePositional = false;
                break;
            }
        }
    }

    // Build (result, recipient) pairs using whichever strategy was chosen.
    // The classification loop below works identically for both strategies.
    if ($usePositional) {
        $pairs = array_map(null, $results, $recipients);
    } else {
        // Phone-keyed map fallback.  The last recipient with a given number
        // wins — consistent with the pre-fix behaviour.
        $recipMap = [];
        foreach ($recipients as $r) {
            $recipMap[$r->getPhoneNumber()->value] = $r;
        }
        $pairs = [];
        foreach ($results as $r) {
            $recip = $recipMap[$r->recipient()->value] ?? null;
            if ($recip === null) {
                // Gateway returned a number not in our list — skip.
                continue;
            }
            $pairs[] = [$r, $recip];
        }
    }

    $successes = [];
    $failures  = [];
    $remoteIds = [];

    foreach ($pairs as [$smsResult, $recip]) {
        if ($smsResult->status()->isOk()) {
            $successes[] = $recip;
        } else {
            $failures[] = $recip;
        }
        $remoteId = $smsResult->remoteId();
        if ($remoteId !== null && $remoteId !== '') {
            $remoteIds[] = $remoteId;
        }
    }

    if ($failures === []) {
        if ($successes === []) {
            return new Failed(error: 'No recipients matched in gateway response');
        }
        return new AllSent(
            recipients: $successes,
            remoteId: $remoteIds !== [] ? implode(', ', $remoteIds) : null,
        );
    }

    if ($successes === []) {
        $firstRaw = $results[0]->rawResponse();
        $errorMsg = $firstRaw !== '' ? $firstRaw : $results[0]->status()->name;
        return new Failed(error: $errorMsg);
    }

    return new PartialSuccess(
        successes: $successes,
        failures: $failures,
        remoteId: $remoteIds !== [] ? implode(', ', $remoteIds) : null,
    );
}


/**
 * Whether the message contains any of the known personalisation %tokens%.
 *
 * A bare '%' (e.g. "20% off") is NOT a token — only %name% forms whose
 * name appears in $tokenNames count, or %(...)% s-expression forms.
 * See docs/sms/improvements/38-token-detection-too-coarse.md
 *
 * @param string[] $tokenNames Token names without delimiters, e.g. ['firstname','lastname','fullname']
 */
function messageHasTokens(string $message, array $tokenNames): bool
{
    if (!str_contains($message, '%')) {
        return false;
    }
    // %(...)% always implies token expansion
    if (str_contains($message, '%(')) {
        return true;
    }
    if ($tokenNames === []) {
        return false;
    }
    $alt = implode('|', array_map('preg_quote', $tokenNames));
    return (bool) preg_match('/%(' . $alt . ')%/', $message);
}


/**
 * Parse a comma-separated Sender ID string into SenderID objects.
 *
 * @return SenderID[]
 */
function parseSenderIdsFromCsv(string $raw): array
{
    $ids = [];
    $skip = ['_USER_MOBILE_', '_SENDER_IDS_'];
    foreach (explode(',', $raw) as $id) {
        $id = trim($id);
        if ($id !== '' && !\in_array($id, $skip, true)) {
            $ids[] = new SenderID($id);
        }
    }

    return $ids;
}


/**
 * Write HTTP request and response to the PHP error log when SMS_VERBOSE is enabled.
 */
function logVerbose(HttpRequest $request, ?HttpResponse $response, ?string $error = null): void
{
    $msg = "=== SMS HTTP Request ===\n";
    $msg .= "{$request->method} {$request->url}\n";
    $msg .= "{$request->headers}\n";
    $msg .= "{$request->body}\n";
    if ($response !== null) {
        $msg .= "=== SMS HTTP Response ===\n";
        $msg .= "{$response->body}\n";
    }
    if ($error !== null) {
        $msg .= "=== SMS HTTP Error ===\n";
        $msg .= "{$error}\n";
    }
    $msg .= "=========================";
    error_log($msg);
}


/**
 * Format a single SmsDelivery as a one-line text representation.
 *
 * Format: <phone>  <StatusText> (#<code>)  [id=<remoteId>]  [sent=<ts>]  [delivered=<ts>]  ["<message>"]
 */
function formatDeliveryLine(SmsDelivery $d): string
{
	$line = $d->recipient()->value
		. '  ' . $d->statusText() . ' (#' . $d->status()->value . ')';
	if ($d->remoteId() !== null) {
		$line .= '  id=' . $d->remoteId();
	}
	if ($d->sendTimestamp() !== null) {
		$line .= '  sent=' . date('Y-m-d H:i:s', $d->sendTimestamp());
	}
	if ($d->deliveryTimestamp() !== null) {
		$line .= '  delivered=' . date('Y-m-d H:i:s', $d->deliveryTimestamp());
	}
	if ($d->message() !== null && $d->message() !== '') {
		$line .= '  "' . $d->message() . '"';
	}
	return $line;
}

/**
 * Format an array of SmsDelivery objects as sorted text lines.
 * @param SmsDelivery[] $deliveries
 */
function formatDeliveryLines(array $deliveries): string
{
	$lines = array_map('\Sms\formatDeliveryLine', $deliveries);
	sort($lines, SORT_STRING);
	return implode("\n", $lines) . (count($lines) > 0 ? "\n" : '');
}

