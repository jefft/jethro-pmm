<?php

declare(strict_types=1);

namespace Sms;

interface SmsProvider
{
    /**
     * Create a configured provider from PHP constants.
     *
     * Reads SMS_* constants from conf.php and constructs a fully-configured
     * provider instance.  Throws \RuntimeException if required constants
     * are missing or invalid.
     *
     * When $tfa is true, 2FA-prefixed constants are tried first for each
     * field (e.g. 2FA_SMS_5CENTSMS_APIKEY_ID), falling back to the
     * standard constant (SMS_5CENTSMS_APIKEY_ID) if the 2FA version is unset.
     *
     * @param bool $tfa Whether to try 2FA_* constants first (two-factor auth).
     */
    public static function fromConstants(bool $tfa = false): static;

    /**
     * Short, unique key identifying this provider (e.g. '5centsmsv5', 'cellcast').
     *
     * Used in the smsdelivery.provider column and the SMS_PROVIDER setting.
     */
    public function getKey(): string;

    /**
     * List the PHP constants this provider reads, for admin UI documentation.
     *
     * @return array<array{string, string, string}>  Each element is
     *         [constant_key, 'required'|'optional', purpose].
     */
    public static function getConstants(): array;

    /**
     * Preference score for auto-detection and admin UI ordering.
     *
     * Higher scores are preferred.  -1 means deprecated — excluded from
     * auto-detection and not shown in configuration help.
     */
    public static function usagePreference(): int;

    /**
     * Return a copy of this provider with the given cache wired in.
     *
     * Providers that don't use caching return $this unchanged.
     * Called by getSmsProvider() after fromConstants() — keeps
     * constant-reading and infrastructure-wiring as separate steps.
     */
    public function withCache(SmsCache $cache): static;

    /**
     * Send one or more SMS messages and return the results.
     *
     * Every send is inherently a batch: the caller passes all (message,
     * recipients) pairs at once, and each decorator processes them in a
     * single invocation.  A single-message send is just a batch of one entry.
     *
     * @param array<int, array{message: string, recipients: SmsRecipient[]}> $entries
     * @param SmsSender $sender
     * @param int|null $sendAt Unix timestamp for deferred delivery (only for providers with DEFERRED_SEND capability), or null for immediate
     * @param bool $preview When true, expand tokens and return per-recipient
     *                      SmsDelivery objects with $message set, but do not
     *                      actually send or persist anything.
     * @return \Result<SmsDeliveryBatch, string>
     */
    public function send(
        array     $entries,
        SmsSender $sender,
        ?int      $sendAt = null,
        bool      $preview = false,
    ): \Result;

    /**
     * Get registered sender IDs from the provider.
     *
     * @param bool $getAll When false (default), only ACMA-approved sender IDs are returned.
     *                     When true, all sender IDs are returned.
     *                     Providers without ACMA status support ignore this parameter.
     * @return \Result<SenderID[], string>
     */
    public function getSenderIds(bool $getAll = false): \Result;

    /**
     * Get the current account balance (number of SMSes that may be sent) from the provider.
     *
     * @return \Result<int, string>
     */
    public function getBalance(): \Result;

    /**
     * Quick health check — whether the upstream provider is reachable
     * and responding.  Implementations SHOULD cache the result when a
     * cache is available; callers MUST tolerate transient failures.
     *
     * @return \Result<bool, string>
     */
    public function isOperational(): \Result;

    /**
     * Query the upstream provider to update this delivery with the latest status.
     *
     * The returned SmsDelivery may be a new instance with timestamps populated
     * (deliveryTimestamp, sendTimestamp) alongside the updated status.
     *
     * @return \Result<SmsDelivery, string>
     */
    public function updateDelivery(SmsDelivery $delivery): \Result;

    /**
     * Cancel the previously sent (scheduled/deferred) deliveries in the batch.
     *
     * Cancellation uses ONLY each delivery's remoteId; all other delivery
     * fields are echoed unchanged into the result.  Returns success whenever
     * the cancel operation could be attempted: per-delivery outcomes are read
     * from each returned delivery's status (CANCELLED on success, unchanged
     * otherwise, with the upstream/transport reason in statusDetail()).
     * Result::failure is reserved for operation-level errors
     * (unsupported provider, auth/transport failure).
     *
     * @return \Result<SmsDeliveryBatch, string>  Batch with per-delivery updated statuses
     */
    public function cancel(SmsDeliveryBatch $batch): \Result;

    /**
     * Get all registered sender phone numbers from the provider.
     *
     * Returns a list of phone numbers that have been registered
     * and approved as sender numbers.  Providers that don't support
     * this return an empty list.
     *
     * @return \Result<PhoneNumber[], string>
     */
    public function getSenderNumbers(): \Result;

    /**
     * Check whether a specific phone number is approved as a sender.
     *
     * @param PhoneNumber $number The phone number to check
     * @return \Result<bool, string>
     */
    public function verifySenderNumber(PhoneNumber $number): \Result;

    /**
     * Register a phone number as a sender with the upstream gateway.
     *
     * Opaque, provider-specific state machine.  Call with no arguments to
     * get the initial number-entry form; call with a phone number
     * (+ optional form-submission params) to advance.  Render `fields`
     * as a form; collect user input; repeat until `fields` is empty/null.
     *
     * @param ContactPhoneNumber|null $contact  null for the initial number-entry form
     * @param array<string, string>|null $params  form-submission params
     * @return \Result<RegistrationStep, string>  success; `isComplete()` when fields is empty
     */
    public function registerSenderNumber(?ContactPhoneNumber $contact = null, ?array $params = null): \Result;

    /**
     * Register a sender ID (business identity) with the upstream gateway.
     *
     * Same opaque state machine as registerSenderNumber().  Call with no
     * arguments to get the initial sender-ID entry form; call with a
     * SenderID (+ optional params) to advance.
     *
     * @param SenderID|null $senderId  null for the initial sender-ID entry form
     * @param array<string, string>|null $params  form-submission params
     * @return \Result<RegistrationStep, string>  success; `isComplete()` when fields is empty
     */
    public function registerSenderId(?SenderID $senderId = null, ?array $params = null): \Result;

    /**
     * Human-readable description of this provider for admin status panels.
     *
     * Examples: "5CentSMS (v5)", "SMS Broadcast", "https://my-gateway.example.com/api"
     */
    public function getDescription(): string;

    /**
     * Check whether this provider supports a specific capability.
     *
     * Use this instead of retrieving and searching the full list.
     */
    public function hasCapability(SmsCapability $cap): bool;

    /**
     * Per-segment cost in millicents (USD 0.00001).
     *
     * 5¢ = 5000, 4.3¢ = 4300, etc.
     */
    public function getSegmentCost(): int;

    /**
     * List all phone numbers that have opted out / unsubscribed.
     *
     * Fetches all pages from the upstream provider internally — the
     * caller receives a flat list.  Expected volume is small; caching
     * is the caller's responsibility.
     *
     * @return \Result<OptOutEntry[], string>
     */
    public function listOptOuts(): \Result;

    /**
     * Remove a phone number from the upstream opt-out list.
     *
     * Gated on the REMOVE_OPT_OUT capability.  Providers that don't
     * support removal return a failure.
     *
     * @return \Result<bool, string>
     */
    public function removeOptOut(PhoneNumber $number): \Result;

    /**
     * Maximum delay in seconds for deferred (scheduled) sends, or null if unspecified.
     *
     * When the provider has DEFERRED_SEND capability, this returns the max
     * seconds ahead that $sendAt may be from now().  Returns null when the
     * provider does not enforce a limit (or does not support deferred send).
     *
     * Callers use this to set the `max` attribute on schedule-datetime inputs
     * and to validate schedule times server-side before sending.
     *
     * @return int|null max seconds or null (unspecified / no limit / N/A)
     */
    public function getDeferredSendMaxDelay(): ?int;

    /**
     * Query recent delivery statuses from the provider in one batch call.
     *
     * Returns all SmsDelivery objects since $since (Unix timestamp).
     * Providers that don't support batch queries return an empty array;
     * the caller falls back to per-delivery updateDelivery().
     *
     * @param ?int $since Unix timestamp — return deliveries after this time. null = last 24h.
     * @return \Result<SmsDelivery[], string>
     */
    public function listRecentDeliveries(?int $since = null): \Result;
}
