<?php

declare(strict_types=1);

namespace Sms;

/**
 * Features an SmsProvider may (or may not) support.
 *
 * Used by callers to conditionally expose UI (e.g. a "Cancel" button
 * only when the provider supports DEFERRED_SEND_CANCEL).
 * @see SmsProvider
 */

enum SmsCapability
{
    /** The provider can query the account balance. */
    case GET_BALANCE;

    /** The provider can return registered sender IDs. */
    case GET_SENDER_IDS;

    /** The provider can schedule a message for future delivery. */
    case DEFERRED_SEND;

    /** The provider can cancel a previously scheduled (deferred) message. */
    case DEFERRED_SEND_CANCEL;

    /** The provider requires sender phone number registration before use. */
    case REGISTER_SENDER_NUMBER;

    /** The provider supports registering a sender ID (business identity) with the upstream gateway. */
    case REGISTER_SENDER_ID;

    /** The provider can list numbers that have opted out / unsubscribed. */
    case LIST_OPT_OUTS;

    /** The provider can remove a number from the opt-out list. */
    case REMOVE_OPT_OUT;

    /** The provider can query delivery statuses for multiple messages in one batch call. */
    case BATCH_DELIVERY_QUERY;
}
