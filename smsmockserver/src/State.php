<?php

namespace SmsMockServer;

/**
 * Pure state machines for SMS delivery and sender-registration approval.
 * No I/O, no side effects — identical logic to the Go version.
 */
final class State
{
    // ── Message status constants ───────────────────────────────────────

    public const MSG_SCHEDULED  = 'scheduled';
    public const MSG_SENT       = 'sent';
    public const MSG_DELIVERED  = 'delivered';
    public const MSG_FAILED     = 'failed';
    public const MSG_CANCELLED  = 'cancelled';
    public const MSG_UNKNOWN    = 'unknown';

    // ── Approval state constants ───────────────────────────────────────

    public const APPROVAL_PENDING  = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';

    // ── DeriveCfg ──────────────────────────────────────────────────────

    /**
     * @param \DateInterval|int|null $deliveryDelay SENT→DELIVERED gap (seconds or DateInterval)
     * @param \DateInterval|int|null $approvalDelay PENDING→APPROVED gap
     * @param bool $scheduledStayScheduled Force scheduled-at messages to stay SCHEDULED
     */
    public function __construct(
        public readonly mixed $deliveryDelay = 5,
        public readonly mixed $approvalDelay = 0,
        public readonly bool $scheduledStayScheduled = false,
    ) {}

    // ── DeriveMessage ──────────────────────────────────────────────────

    /**
     * Compute the current delivery status for an SMS message.
     *
     * Precedence:
     *  1. cancelled_at != null → CANCELLED
     *  2. forced_status is a terminal pin → that status
     *  3. forced_status is non-empty but not terminal → that status
     *  4. now < pivot → SCHEDULED
     *  5. cfg.scheduledStayScheduled && scheduled_send_at != null → SCHEDULED
     *  6. now < pivot + deliveryDelay → SENT
     *  7. Otherwise → DELIVERED
     *
     * @param array{cancelled_at: ?string, scheduled_send_at: ?string, created_at: string,
     *              forced_status: ?string, forced_reason: ?string} $row
     */
    public function deriveMessage(array $row, \DateTimeImmutable $now): string
    {
        // 1. Cancelled
        if ($row['cancelled_at'] !== null) {
            return self::MSG_CANCELLED;
        }

        // 2–3. Forced status
        $fs = $row['forced_status'];
        if ($fs !== null && $fs !== '') {
            if (self::isTerminal($fs)) {
                return $fs;
            }
            return $fs;
        }

        // Pivot: use scheduled_send_at if set, else created_at
        $pivot = $row['scheduled_send_at'] !== null
            ? new \DateTimeImmutable($row['scheduled_send_at'])
            : new \DateTimeImmutable($row['created_at']);

        // 4. Before pivot → SCHEDULED
        if ($now < $pivot) {
            return self::MSG_SCHEDULED;
        }

        // 5. ScheduledStayScheduled
        if ($this->scheduledStayScheduled && $row['scheduled_send_at'] !== null) {
            return self::MSG_SCHEDULED;
        }

        $delaySeconds = $this->resolveSeconds($this->deliveryDelay);

        // 6. Within delivery delay window → SENT
        $pivotPlusDelay = (clone $pivot)->modify("+{$delaySeconds} seconds");
        if ($now < $pivotPlusDelay) {
            return self::MSG_SENT;
        }

        // 7. Default → DELIVERED
        return self::MSG_DELIVERED;
    }

    // ── DeriveApproval ─────────────────────────────────────────────────

    /**
     * Compute the current approval state for a sender registration.
     *
     * Precedence:
     *  1. forced_state == "pending" → PENDING (indefinite pin)
     *  2. forced_state == "rejected" → REJECTED
     *  3. forced_state == "approved" → APPROVED
     *  4. rejected_at != null → REJECTED
     *  5. otp_code set but otp_verified_at still null → PENDING
     *  6. approved_at != null → APPROVED
     *  7. now < created_at + approvalDelay → PENDING
     *  8. Otherwise → APPROVED
     *
     * @param array{forced_state: ?string, rejected_at: ?string, approved_at: ?string,
     *              otp_code: ?string, otp_verified_at: ?string, created_at: string} $row
     */
    public function deriveApproval(array $row, \DateTimeImmutable $now): string
    {
        $fs = $row['forced_state'];

        // 1–3. Forced state
        if ($fs === self::APPROVAL_PENDING)  return self::APPROVAL_PENDING;
        if ($fs === self::APPROVAL_REJECTED) return self::APPROVAL_REJECTED;
        if ($fs === self::APPROVAL_APPROVED) return self::APPROVAL_APPROVED;

        // 4. Rejected
        if ($row['rejected_at'] !== null) {
            return self::APPROVAL_REJECTED;
        }

        // 5. OTP pending
        if ($row['otp_code'] !== null && $row['otp_verified_at'] === null) {
            return self::APPROVAL_PENDING;
        }

        // 6. Approved
        if ($row['approved_at'] !== null) {
            return self::APPROVAL_APPROVED;
        }

        $delaySeconds = $this->resolveSeconds($this->approvalDelay);

        // 7. Within approval delay → PENDING
        $createdAt = new \DateTimeImmutable($row['created_at']);
        $createdPlusDelay = $createdAt->modify("+{$delaySeconds} seconds");
        if ($now < $createdPlusDelay) {
            return self::APPROVAL_PENDING;
        }

        // 8. Default → APPROVED
        return self::APPROVAL_APPROVED;
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private static function isTerminal(string $s): bool
    {
        return in_array($s, [
            self::MSG_FAILED,
            self::MSG_CANCELLED,
            self::MSG_DELIVERED,
            self::MSG_UNKNOWN,
            'test-message',
        ], true);
    }

    private function resolveSeconds(mixed $delay): int
    {
        if ($delay instanceof \DateInterval) {
            return (int) $delay->format('%s') + ((int) $delay->format('%i') * 60)
                 + ((int) $delay->format('%h') * 3600) + ((int) $delay->format('%a') * 86400);
        }
        return (int) $delay;
    }

    /** Unix timestamp for the delivery timestamp field. */
    public function deliveryTs(string $status, string $createdAt, \DateTimeImmutable $now): int
    {
        if ($status === self::MSG_DELIVERED) {
            $created = new \DateTimeImmutable($createdAt);
            $delaySeconds = $this->resolveSeconds($this->deliveryDelay);
            return $created->modify("+{$delaySeconds} seconds")->getTimestamp();
        }
        return $now->getTimestamp();
    }
}
