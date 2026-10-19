<?php

namespace SmsMockServer;

/**
 * In-memory MessageStore for tests: the same rows and semantics as Store, so
 * provider handlers can run without MySQL.
 */
final class MemoryStore implements MessageStore
{
    /** @var list<array<string,mixed>> */
    private array $messages = [];

    /** @var list<array<string,mixed>> */
    private array $registrations = [];

    private int $nextId = 1;

    public function insertMessage(
        string $profile, string $provider, string $remoteId,
        string $sender, string $destination, string $body,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $scheduledSendAt = null,
        ?string $forcedStatus = null,
        ?string $forcedReason = null,
    ): int {
        $id = $this->nextId++;
        $this->messages[] = [
            'id' => $id,
            'profile' => $profile,
            'provider' => $provider,
            'remote_id' => $remoteId,
            'sender' => $sender,
            'destination' => $destination,
            'body' => $body,
            'created_at' => $createdAt->format('Y-m-d H:i:s.v'),
            'scheduled_send_at' => $scheduledSendAt?->format('Y-m-d H:i:s.v'),
            'cancelled_at' => null,
            'forced_status' => $forcedStatus,
            'forced_reason' => $forcedReason,
        ];
        return $id;
    }

    public function getMessageByRemoteId(string $provider, string $remoteId): ?array
    {
        foreach ($this->messages as $row) {
            if ($row['provider'] === $provider && $row['remote_id'] === $remoteId) {
                return $row;
            }
        }
        return null;
    }

    public function listMessagesForScope(string $profile, string $provider): array
    {
        $rows = array_values(array_filter(
            $this->messages,
            fn(array $row) => $row['profile'] === $profile && $row['provider'] === $provider,
        ));
        usort($rows, fn(array $a, array $b) => strcmp($b['created_at'], $a['created_at']));
        return $rows;
    }

    public function markMessageCancelled(int $id, \DateTimeImmutable $now): void
    {
        foreach ($this->messages as &$row) {
            if ($row['id'] === $id) {
                $row['cancelled_at'] = $now->format('Y-m-d H:i:s.v');
            }
        }
    }

    public function deleteMessagesForScope(string $profile, string $provider): void
    {
        $this->messages = array_values(array_filter(
            $this->messages,
            fn(array $row) => !($row['profile'] === $profile && $row['provider'] === $provider),
        ));
    }

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
        foreach ($this->registrations as &$row) {
            if ($row['profile'] === $profile && $row['provider'] === $provider
                && $row['kind'] === $kind && $row['value'] === $value) {
                // Mirrors INSERT ... ON DUPLICATE KEY UPDATE: created_at is kept.
                $row['display_name'] = $displayName;
                $row['otp_code'] = $otpCode;
                $row['approved_at'] = $approvedAt?->format('Y-m-d H:i:s.v');
                $row['rejected_at'] = $rejectedAt?->format('Y-m-d H:i:s.v');
                $row['rejected_reason'] = $rejectedReason;
                $row['forced_state'] = $forcedState;
                $row['raw_request'] = $rawRequest;
                return $row['id'];
            }
        }
        $id = $this->nextId++;
        $this->registrations[] = [
            'id' => $id,
            'profile' => $profile,
            'provider' => $provider,
            'kind' => $kind,
            'value' => $value,
            'display_name' => $displayName,
            'otp_code' => $otpCode,
            'otp_verified_at' => null,
            'approved_at' => $approvedAt?->format('Y-m-d H:i:s.v'),
            'rejected_at' => $rejectedAt?->format('Y-m-d H:i:s.v'),
            'rejected_reason' => $rejectedReason,
            'forced_state' => $forcedState,
            'raw_request' => $rawRequest,
            'created_at' => $createdAt->format('Y-m-d H:i:s.v'),
        ];
        return $id;
    }

    public function getRegistration(string $provider, string $profile, string $kind, string $value): ?array
    {
        foreach ($this->registrations as $row) {
            if ($row['provider'] === $provider && $row['profile'] === $profile
                && $row['kind'] === $kind && $row['value'] === $value) {
                return $row;
            }
        }
        return null;
    }

    public function listRegistrationsForScope(string $profile, string $provider): array
    {
        $rows = array_values(array_filter(
            $this->registrations,
            fn(array $row) => $row['profile'] === $profile && $row['provider'] === $provider,
        ));
        usort($rows, fn(array $a, array $b) => strcmp($b['created_at'], $a['created_at']));
        return $rows;
    }

    public function markRegistrationOTPVerified(int $id, \DateTimeImmutable $now): void
    {
        foreach ($this->registrations as &$row) {
            if ($row['id'] === $id) {
                $row['otp_verified_at'] = $now->format('Y-m-d H:i:s.v');
            }
        }
    }

    public function markRegistrationApproval(int $id, \DateTimeImmutable $approvedAt): void
    {
        foreach ($this->registrations as &$row) {
            if ($row['id'] === $id) {
                $row['approved_at'] = $approvedAt->format('Y-m-d H:i:s.v');
            }
        }
    }

    public function deleteRegistration(int $id): void
    {
        $this->registrations = array_values(array_filter(
            $this->registrations,
            fn(array $row) => $row['id'] !== $id,
        ));
    }

    public function deleteRegistrationsForScope(string $profile, string $provider): void
    {
        $this->registrations = array_values(array_filter(
            $this->registrations,
            fn(array $row) => !($row['profile'] === $profile && $row['provider'] === $provider),
        ));
    }
}
