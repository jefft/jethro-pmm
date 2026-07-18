<?php

namespace SmsMockServer\Provider;

use SmsMockServer\Profile;
use SmsMockServer\PendingSMS;

/**
 * Cellcast-specific profile wrapper with Senders/OptOuts fields
 * and ergonomic helper methods.
 */
final class CellcastProfile
{
    /** @var array<string, self> name => CellcastProfile */
    private static array $registry = [];

    public static function get(string $name): ?self
    {
        return self::$registry[$name] ?? null;
    }

    /** @param Profile $profile The underlying framework profile */
    private function __construct(
        public readonly Profile $profile,
        /** @var list<array{number:string,name:string}> */
        public array $senders = [],
        /** @var list<array{number:string,first_name:string,last_name:string,full_name:string}> */
        public array $optOuts = [],
    ) {}

    /** Register a cellcast profile with the given name. */
    public static function register(string $name, callable $setup): self
    {
        $profile = new Profile();
        $cp = new self($profile);
        $setup($cp);
        Profile::register('cellcast', $name, $profile);
        self::$registry[$name] = $cp;
        return $cp;
    }

    public function setBalance(int $n): void
    {
        $this->profile->balance = $n;
    }

    /** @param list<array{number:string,name:string}> $senders */
    public function setSenders(array $senders): void
    {
        $this->senders = $senders;
    }

    /** @param list<array{number:string,first_name:string,last_name:string,full_name:string}> $items */
    public function setOptOuts(array $items): void
    {
        $this->optOuts = $items;
    }

    /**
     * Install an endpoint override that rejects every send.
     * @param string $msg Rejection message
     * @param int $status HTTP status (default 200 to match Cellcast)
     */
    public function rejectAllSends(string $msg, int $status = 200): void
    {
        $this->profile->endpoint('POST', '/api/v1/gateway')
            ->returnJSON(['status' => false, 'message' => $msg])
            ->returnStatus($status);
    }

    /**
     * Install an OnSendHook that forces a specific destination to FAILED.
     * Multiple calls are additive.
     */
    public function failRecipient(string $dest, string $reason): void
    {
        $prev = $this->profile->onSendHook;
        $this->profile->onSendHook = function (PendingSMS $p) use ($dest, $reason, $prev) {
            if ($prev) $prev($p);
            if ($p->destination === $dest) {
                $p->forceStatus('failed', $reason);
            }
        };
    }

    /** Set ApprovalDelay to 0 (auto-approve registrations). */
    public function approveInstantly(): void
    {
        $this->profile->approvalDelay = 0;
    }

    /** Set approval delay in seconds for registrations. */
    public function approveAfter(int $seconds): void
    {
        $this->profile->approvalDelay = $seconds;
    }

    /** Install an OnRegisterHook that rejects all registrations. */
    public function rejectRegistrations(string $reason): void
    {
        $this->profile->onRegisterHook = function (\SmsMockServer\PendingRegistration $p) use ($reason) {
            $p->reject($reason);
        };
    }

    /** Require OTP for number registrations. */
    public function requireOTPForNumbers(string $code = '123456'): void
    {
        $this->profile->onRegisterHook = function (\SmsMockServer\PendingRegistration $p) use ($code) {
            if ($p->kind === 'number') {
                $p->requireOTP($code);
            }
        };
    }
}
