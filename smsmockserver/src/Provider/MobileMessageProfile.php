<?php

namespace SmsMockServer\Provider;

use SmsMockServer\Profile;

/**
 * MobileMessage-specific profile wrapper: account state the /v1 routes list
 * (senders, unsubscribes) plus behaviour helpers.
 */
final class MobileMessageProfile
{
    /** @var array<string, self> name => MobileMessageProfile */
    private static array $registry = [];

    public static function get(string $name): ?self
    {
        return self::$registry[$name] ?? null;
    }

    private function __construct(
        public readonly Profile $profile,
        /** @var list<array{sender:string,type:string}> Extra GET /v1/senders entries */
        public array $senders = [],
        /** @var list<string> Unsubscribed numbers, international form (GET /v1/unsubscribes) */
        public array $optOuts = [],
    ) {}

    /** Register a mobilemessage profile with the given name. */
    public static function register(string $name, callable $setup): self
    {
        $profile = new Profile();
        $mp = new self($profile);
        $setup($mp);
        Profile::register('mobilemessage', $name, $profile);
        self::$registry[$name] = $mp;
        return $mp;
    }

    /**
     * Sender numbers the account has already verified (type "own"), e.g. the
     * test user's own mobile.
     *
     * @param list<string> $numbers International form, e.g. '61491570157'
     */
    public function preRegisterNumbers(array $numbers): void
    {
        foreach ($numbers as $n) {
            $this->senders[] = ['sender' => $n, 'type' => 'own'];
        }
    }

    /**
     * Alphanumeric Sender IDs on the account (type "alpha").
     *
     * @param list<string> $ids e.g. ['Jethro']
     */
    public function setSenderIds(array $ids): void
    {
        foreach ($ids as $id) {
            $this->senders[] = ['sender' => $id, 'type' => 'alpha'];
        }
    }

    /** @param list<string> $numbers International form, e.g. '61491570159' */
    public function setOptOuts(array $numbers): void
    {
        $this->optOuts = $numbers;
    }

    public function setBalance(int $n): void
    {
        $this->profile->balance = $n;
    }

    /**
     * Reject every send with the API's error envelope. The live API answers
     * errors with a 4xx and {"error": "…"}; the status and wording for
     * insufficient credit specifically were not recorded.
     */
    public function rejectAllSends(string $msg, int $status = 400): void
    {
        $this->profile->endpoint('POST', '/v1/messages')
            ->returnJSON(['error' => $msg])
            ->returnStatus($status);
    }
}
