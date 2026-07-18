<?php

namespace SmsMockServer\Provider;

use SmsMockServer\Profile;

/**
 * FiveCentSms-specific profile wrapper.
 */
final class FiveCentSmsProfile
{
    private function __construct(
        public readonly Profile $profile,
    ) {}

    /** Register a 5centsms profile with the given name. */
    public static function register(string $name, callable $setup): self
    {
        $profile = new Profile();
        $fp = new self($profile);
        $setup($fp);
        Profile::register('5centsms', $name, $profile);
        return $fp;
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

    /** Reject all sends via endpoint override. */
    public function rejectAllSends(string $msg, int $status = 200): void
    {
        $this->profile->endpoint('POST', '/api/v1/sendSms')
            ->returnJSON(['error_code' => 1, 'error_msg' => $msg])
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
