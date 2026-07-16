<?php

declare(strict_types=1);

namespace Sms;

/**
 * Per-recipient delivery results from an SMS send.
 *
 * @see SendSummary
 * @see SmsStatus
 */

/**
 * Result for a single recipient from a send() call.
 *
 * Subclasses parse provider-specific raw response formats to extract
 * the common fields: delivery status and remote message ID.
 * @see SmsStatus
 * @see PhoneNumber
 */

readonly class SmsDelivery
{
    public function __construct(
        private PhoneNumber $recipient,
        private SmsStatus   $status,
        private ?string     $remoteId = null,
        private ?int        $deliveryTimestamp = null,
        private ?int        $sendTimestamp = null,
        private string      $rawResponse = '',
        /** The actual message text that was (or would be) sent to this recipient — token-expanded. */
        private ?string     $message = null,
        /** Provider-supplied human-readable detail about the latest operation on this delivery — e.g. why a cancel attempt was refused ("message not found"). Transient; not persisted. */
        private ?string     $statusDetail = null,
    )
    {
    }

    /**
     * Return a copy with the given fields replaced.
     *
     * Unspecified fields keep their existing values.  Because SmsDelivery is
     * readonly and immutable, this is the only way to produce a modified copy
     * and is safe across all decorators and providers.
     *
     * Returns the base SmsDelivery class — subtypes used for provider-specific
     * parsing (FiveCentSmsDelivery etc.) lose their subtype identity through
     * decoration, which matches existing behaviour (TokenExpandingSmsProvider
     * already wraps subtypes into plain SmsDelivery).
     */
    public function with(
        ?SmsStatus $status = null,
        ?string   $remoteId = null,
        ?int      $deliveryTimestamp = null,
        ?int      $sendTimestamp = null,
        ?string   $rawResponse = null,
        ?string   $message = null,
        ?string   $statusDetail = null,
    ): self {
        return new self(
            recipient: $this->recipient,
            status: $status ?? $this->status,
            remoteId: $remoteId ?? $this->remoteId,
            deliveryTimestamp: $deliveryTimestamp ?? $this->deliveryTimestamp,
            sendTimestamp: $sendTimestamp ?? $this->sendTimestamp,
            rawResponse: $rawResponse ?? $this->rawResponse,
            message: $message ?? $this->message,
            statusDetail: $statusDetail ?? $this->statusDetail,
        );
    }

    public function recipient(): PhoneNumber
    {
        return $this->recipient;
    }

    public function status(): SmsStatus
    {
        return $this->status;
    }

    public function remoteId(): ?string
    {
        return $this->remoteId;
    }

    public function rawResponse(): string
    {
        return $this->rawResponse;
    }

    /** @deprecated Use message() — the two fields were merged (see docs/sms/improvements/44-...). */
    public function body(): ?string
    {
        return $this->message;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    /** Provider-supplied detail about the latest operation — e.g. why a cancel was refused. */
    public function statusDetail(): ?string
    {
        return $this->statusDetail;
    }

    public function deliveryTimestamp(): ?int
    {
        return $this->deliveryTimestamp;
    }

    public function sendTimestamp(): ?int
    {
        return $this->sendTimestamp;
    }

    /**
     * Human-readable status text (e.g. "Scheduled", "Sent").
     * Default: derived from the status enum.
     */
    public function statusText(): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $this->status()->name)));
    }
}

/**
 * A group of SmsDelivery objects produced by a single send() action.
 *
 * $batchId is null from raw providers and in preview mode — raw gateways have
 * no notion of a batch.  DbLoggingSmsProvider (bridge layer) sets it to the
 * sms.id of the persisted send.  Deliveries sharing a batch were sent together.
 * @see SmsDelivery
 */

readonly class SmsDeliveryBatch
{
    public function __construct(
        public ?string $batchId,
        /** @var SmsDelivery[] */
        public array $deliveries,
    ) {}
}
