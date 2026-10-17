<?php

namespace SmsMockServer\Provider;

use SmsMockServer\Profile;

/**
 * FiveCentSms-specific profile wrapper: account state the v5 routes list
 * (sender numbers, sender IDs, opt-outs) plus behaviour helpers.
 */
final class FiveCentSmsProfile
{
    /** @var array<string, self> name => FiveCentSmsProfile */
    private static array $registry = [];

    public static function get(string $name): ?self
    {
        return self::$registry[$name] ?? null;
    }

    private function __construct(
        public readonly Profile $profile,
        /** @var list<array<string,mixed>> GET /senderid items (senderid, status, and any ACMA fields) */
        public array $senders = [],
        /** @var list<string> Opted-out numbers, international form (GET /optouts) */
        public array $optOuts = [],
    ) {}

    /** Register a 5centsms profile with the given name. */
    public static function register(string $name, callable $setup): self
    {
        $profile = new Profile();
        $fp = new self($profile);
        $setup($fp);
        Profile::register('5centsms', $name, $profile);
        self::$registry[$name] = $fp;
        return $fp;
    }

    /**
     * Sender numbers the account has already verified, e.g. the test user's
     * own mobile; GET /senderid lists them with status "approved", as the
     * live API does once the verification link has been clicked.
     *
     * @param list<string> $numbers International form, e.g. '61491570157'
     */
    public function preRegisterNumbers(array $numbers): void
    {
        foreach ($numbers as $n) {
            $this->senders[] = ['senderid' => $n, 'status' => 'approved'];
        }
    }

    /**
     * ACMA-approved alphanumeric Sender IDs, listed by GET /senderid.
     *
     * @param list<string> $ids e.g. ['Jethro']
     */
    public function setSenderIds(array $ids): void
    {
        foreach ($ids as $id) {
            $this->senders[] = ['senderid' => $id, 'status' => 'approved'];
        }
    }

    /**
     * Full GET /senderid entries, to mirror a live account's list exactly
     * (statuses pending/unverified/approved/acma_approved, ACMA fields).
     * `id` and `shared` are filled in when omitted.
     *
     * @param list<array<string,mixed>> $items
     */
    public function setSenders(array $items): void
    {
        array_push($this->senders, ...$items);
    }

    /**
     * Numbers GET /optouts lists as opted out.
     *
     * @param list<string> $numbers International form, e.g. '61491570159'
     */
    public function setOptOuts(array $numbers): void
    {
        $this->optOuts = $numbers;
    }

    public function setBalance(int $n): void
    {
        $this->profile->balance = $n;
    }

    /** Install an OnSendHook that forces a specific destination to FAILED. */
    public function failRecipient(string $dest, string $reason): void
    {
        $prev = $this->profile->onSendHook;
        $this->profile->onSendHook = function (\SmsMockServer\PendingSMS $p) use ($dest, $reason, $prev) {
            if ($prev) $prev($p);
            if ($p->destination === $dest) {
                $p->forceStatus('failed', $reason);
            }
        };
    }

    /** Reject every send: POST /sms answers {"error": msg}, the live API's rejection shape. */
    public function rejectAllSends(string $msg, int $status = 200): void
    {
        $this->profile->endpoint('POST', '/sms')
            ->returnJSON(['error' => $msg])
            ->returnStatus($status);
    }

    public function approveInstantly(): void
    {
        $this->profile->approvalDelay = 0;
    }

    public function approveAfter(int $seconds): void
    {
        $this->profile->approvalDelay = $seconds;
    }

    /** Reject all sender ID registrations. */
    public function rejectRegistrations(string $reason): void
    {
        $this->profile->onRegisterHook = function (\SmsMockServer\PendingRegistration $p) use ($reason) {
            $p->reject($reason);
        };
    }
}
