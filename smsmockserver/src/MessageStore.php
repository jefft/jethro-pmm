<?php

namespace SmsMockServer;

/**
 * The message/registration storage the provider handlers use. Store is the
 * MySQL implementation; MemoryStore lets tests run without a database.
 */
interface MessageStore
{
    public function insertMessage(
        string $profile, string $provider, string $remoteId,
        string $sender, string $destination, string $body,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $scheduledSendAt = null,
        ?string $forcedStatus = null,
        ?string $forcedReason = null,
    ): int;

    /** @return array<string,mixed>|null */
    public function getMessageByRemoteId(string $provider, string $remoteId): ?array;

    /** @return list<array<string,mixed>> */
    public function listMessagesForScope(string $profile, string $provider): array;

    public function markMessageCancelled(int $id, \DateTimeImmutable $now): void;

    public function deleteMessagesForScope(string $profile, string $provider): void;

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
    ): int;

    /** @return array<string,mixed>|null */
    public function getRegistration(string $provider, string $profile, string $kind, string $value): ?array;

    /** @return list<array<string,mixed>> */
    public function listRegistrationsForScope(string $profile, string $provider): array;

    public function markRegistrationOTPVerified(int $id, \DateTimeImmutable $now): void;

    public function markRegistrationApproval(int $id, \DateTimeImmutable $approvedAt): void;

    public function deleteRegistration(int $id): void;

    public function deleteRegistrationsForScope(string $profile, string $provider): void;
}
