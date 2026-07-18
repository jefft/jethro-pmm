<?php

namespace SmsMockServer;

/**
 * MySQL CRUD for mock SMS messages and registrations.
 *
 * Uses PDO. No connection pooling — each instance owns its own PDO handle.
 * All timestamps are stored/returned as ISO 8601 strings in UTC.
 */
final class Store
{
    public function __construct(
        public readonly \PDO $pdo,
    ) {}

    // ── Migrate ────────────────────────────────────────────────────────

    /** Apply schema idempotently. */
    public function migrate(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS mock_sms_message (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                profile VARCHAR(255) NOT NULL DEFAULT '',
                provider VARCHAR(64) NOT NULL,
                remote_id VARCHAR(64) NOT NULL,
                sender VARCHAR(255) NOT NULL DEFAULT '',
                destination VARCHAR(64) NOT NULL,
                body TEXT NOT NULL,
                created_at DATETIME(3) NOT NULL,
                scheduled_send_at DATETIME(3) NULL,
                cancelled_at DATETIME(3) NULL,
                forced_status VARCHAR(32) NULL,
                forced_reason VARCHAR(512) NULL,
                UNIQUE KEY uq_provider_remote (provider, remote_id),
                INDEX idx_scope (profile, provider)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS mock_sms_registration (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                profile VARCHAR(255) NOT NULL DEFAULT '',
                provider VARCHAR(64) NOT NULL,
                kind VARCHAR(32) NOT NULL,
                value VARCHAR(255) NOT NULL,
                display_name VARCHAR(255) NOT NULL DEFAULT '',
                otp_code VARCHAR(16) NULL,
                otp_verified_at DATETIME(3) NULL,
                approved_at DATETIME(3) NULL,
                rejected_at DATETIME(3) NULL,
                rejected_reason VARCHAR(512) NULL,
                forced_state VARCHAR(32) NULL,
                raw_request TEXT NULL,
                created_at DATETIME(3) NOT NULL,
                UNIQUE KEY uq_scope_value (profile, provider, kind, value),
                INDEX idx_scope (profile, provider)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // View: mirrors the state machine in MySQL for /meta/ queries.
        // CREATE OR REPLACE VIEW is idempotent.
        $this->pdo->exec("
            CREATE OR REPLACE VIEW `mocksms` AS
            SELECT
                `mock_sms_message`.`id` AS `id`,
                `mock_sms_message`.`remote_id` AS `remote_id`,
                `mock_sms_message`.`provider` AS `provider`,
                `mock_sms_message`.`profile` AS `profile`,
                `mock_sms_message`.`sender` AS `sender`,
                `mock_sms_message`.`destination` AS `destination`,
                `mock_sms_message`.`body` AS `body`,
                `mock_sms_message`.`created_at` AS `created_at`,
                `mock_sms_message`.`scheduled_send_at` AS `scheduled_send_at`,
                `mock_sms_message`.`forced_status` AS `forced_status`,
                `mock_sms_message`.`forced_reason` AS `forced_reason`,
                `mock_sms_message`.`created_at` AS `inserted_at`,
                CASE
                    WHEN `mock_sms_message`.`cancelled_at` IS NOT NULL THEN 'cancelled'
                    WHEN `mock_sms_message`.`forced_status` IS NOT NULL AND `mock_sms_message`.`forced_status` <> '' THEN `mock_sms_message`.`forced_status`
                    WHEN UTC_TIMESTAMP(3) < COALESCE(`mock_sms_message`.`scheduled_send_at`, `mock_sms_message`.`created_at`) THEN 'scheduled'
                    WHEN UTC_TIMESTAMP(3) < COALESCE(`mock_sms_message`.`scheduled_send_at`, `mock_sms_message`.`created_at`) + INTERVAL 5 SECOND THEN 'sent'
                    ELSE 'delivered'
                END AS `current_status`
            FROM `mock_sms_message`
        ");
    }

    // ── Message CRUD ───────────────────────────────────────────────────

    public function insertMessage(
        string $profile, string $provider, string $remoteId,
        string $sender, string $destination, string $body,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $scheduledSendAt = null,
        ?string $forcedStatus = null,
        ?string $forcedReason = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mock_sms_message
             (profile, provider, remote_id, sender, destination, body,
              created_at, scheduled_send_at, forced_status, forced_reason)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $profile, $provider, $remoteId, $sender, $destination, $body,
            $createdAt->format('Y-m-d H:i:s.v'),
            $scheduledSendAt?->format('Y-m-d H:i:s.v'),
            $forcedStatus, $forcedReason,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function getMessageByRemoteId(string $provider, string $remoteId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mock_sms_message WHERE provider = ? AND remote_id = ?'
        );
        $stmt->execute([$provider, $remoteId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function listMessagesForScope(string $profile, string $provider): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mock_sms_message WHERE profile = ? AND provider = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$profile, $provider]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function markMessageCancelled(int $id, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mock_sms_message SET cancelled_at = ? WHERE id = ?'
        );
        $stmt->execute([$now->format('Y-m-d H:i:s.v'), $id]);
    }

    public function deleteMessagesForScope(string $profile, string $provider): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM mock_sms_message WHERE profile = ? AND provider = ?'
        );
        $stmt->execute([$profile, $provider]);
    }

    // ── Registration CRUD ──────────────────────────────────────────────

    public function upsertRegistration(
        string $profile, string $provider, string $kind, string $value,
        string $displayName,
        ?string $otpCode = null,
        ?\DateTimeImmutable $approvedAt = null,
        ?\DateTimeImmutable $rejectedAt = null,
        ?string $rejectedReason = null,
        ?string $forcedState = null,
        ?string $rawRequest = null,
        \DateTimeImmutable $createdAt = new \DateTimeImmutable('now'),
    ): int {
        if ($createdAt === null) {
            $createdAt = new \DateTimeImmutable('now');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO mock_sms_registration
             (profile, provider, kind, value, display_name, otp_code,
              approved_at, rejected_at, rejected_reason, forced_state, raw_request, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               display_name = VALUES(display_name),
               otp_code = VALUES(otp_code),
               approved_at = VALUES(approved_at),
               rejected_at = VALUES(rejected_at),
               rejected_reason = VALUES(rejected_reason),
               forced_state = VALUES(forced_state),
               raw_request = VALUES(raw_request)'
        );
        $stmt->execute([
            $profile, $provider, $kind, $value, $displayName, $otpCode,
            $approvedAt?->format('Y-m-d H:i:s.v'),
            $rejectedAt?->format('Y-m-d H:i:s.v'),
            $rejectedReason, $forcedState, $rawRequest,
            $createdAt->format('Y-m-d H:i:s.v'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function getRegistration(string $provider, string $profile, string $kind, string $value): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mock_sms_registration
             WHERE provider = ? AND profile = ? AND kind = ? AND value = ?'
        );
        $stmt->execute([$provider, $profile, $kind, $value]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function listRegistrationsForScope(string $profile, string $provider): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mock_sms_registration WHERE profile = ? AND provider = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$profile, $provider]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function markRegistrationOTPVerified(int $id, \DateTimeImmutable $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mock_sms_registration SET otp_verified_at = ? WHERE id = ?'
        );
        $stmt->execute([$now->format('Y-m-d H:i:s.v'), $id]);
    }

    public function markRegistrationApproval(int $id, \DateTimeImmutable $approvedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mock_sms_registration SET approved_at = ? WHERE id = ?'
        );
        $stmt->execute([$approvedAt->format('Y-m-d H:i:s.v'), $id]);
    }

    public function deleteRegistration(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM mock_sms_registration WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteRegistrationsForScope(string $profile, string $provider): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM mock_sms_registration WHERE profile = ? AND provider = ?'
        );
        $stmt->execute([$profile, $provider]);
    }
}
