<?php

declare(strict_types=1);

namespace Sms\Providers;

use Sms\PhoneNumber;
use Sms\SmsDelivery;
use Sms\SmsStatus;

/**
 * SmsDelivery that parses an SMS Broadcast colon-delimited response line.
 *
 * Each line from the API carries the outcome and (for OK) the remote ref:
 *
 *   OK:61402511927:1252164015  →  status() = SENT,  remoteId() = "1252164015"
 *   BAD:61402511928:Blocked   →  status() = FAILED, remoteId() = null
 *   ERROR:Invalid credentials  →  status() = FAILED, remoteId() = null
 *
 * rawResponse() returns the line verbatim for DB audit.
 * @see SmsDelivery
 * @see SmsBroadcastSmsProvider
 */

final readonly class SmsBroadcastSmsDelivery extends SmsDelivery
{
    public function __construct(PhoneNumber $recipient, string $rawLine)
    {
        $parts = explode(':', $rawLine, 3);
        $status = \str_starts_with($rawLine, 'OK:') ? SmsStatus::SENT : SmsStatus::FAILED;
        $remoteId = null;
        if ($status === SmsStatus::SENT && \count($parts) >= 3 && $parts[2] !== '') {
            $remoteId = $parts[2];
        }
        parent::__construct(
            recipient: $recipient,
            status: $status,
            remoteId: $remoteId,
            rawResponse: $rawLine,
        );
    }
}

