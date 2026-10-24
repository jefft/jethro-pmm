<?php

namespace SmsMockServer\Provider;

use SmsMockServer\State;
use SmsMockServer\Util;

/**
 * MobileMessage (api.mobilemessage.com.au) simulator.
 *
 * Reply shapes are the ones recorded from the live API (2026-10, see
 * jethro-sms/docs/reference/smsarchitecture.md → MobileMessage): HTTP Basic
 * auth, errors as a 4xx with {"error": "…"}, numbers normalised to 614…,
 * timestamps "Y-m-d H:i:s" in UTC without a zone, DELETEs reading a JSON
 * body (query parameters ignored), and a scheduled_for 60 s ahead or less
 * silently sent at once.
 */
final class MobileMessage implements ProviderInterface
{
    private const PROVIDER = 'mobilemessage';

    /** The account's shared default number, as on the live account. */
    private const SHARED_NUMBER = '61485900133';

    public function name(): string { return self::PROVIDER; }

    public static function routes(): array
    {
        return [
            'POST /v1/messages',
            'GET /v1/messages',
            'DELETE /v1/messages',
            'GET /v1/account',
            'GET /v1/senders',
            'POST /v1/senders',
            'GET /v1/unsubscribes',
            'DELETE /v1/unsubscribes',
        ];
    }

    public function handle(Ctx $ctx): void
    {
        \SmsMockServer\Util::jsonHeader();
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_SERVER['PATH_INFO'] ?? '/';

        if (!str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Basic ')) {
            Util::json(401, ['error' => 'Unauthorized']);
        }

        // Endpoint override check; a passthrough override routes normally.
        $ep = $ctx->profileHooks?->endpoints[strtoupper($method) . ' ' . $path] ?? null;
        if ($ep !== null && $ep->action !== \SmsMockServer\Endpoint::ACTION_PASSTHROUGH) {
            match ($ep->action) {
                \SmsMockServer\Endpoint::ACTION_RETURN_JSON => Util::json($ep->status, $ep->data),
                \SmsMockServer\Endpoint::ACTION_RETURN_JSON_FUNC => Util::json($ep->status, ($ep->jsonFunc)(new \SmsMockServer\ProviderRequest(
                    body: $ctx->rawBody(), query: $_GET,
                ))),
                \SmsMockServer\Endpoint::ACTION_HANDLER => ($ep->handler)(),
            };
            exit;
        }

        $body = $ctx->jsonBody();
        match (true) {
            $method === 'POST' && $path === '/v1/messages' => $this->handleSend($ctx, $body),
            $method === 'GET' && $path === '/v1/messages' => isset($_GET['message_id'])
                ? $this->handleLookup($ctx, (string) $_GET['message_id'])
                : $this->handleHistory($ctx),
            $method === 'DELETE' && $path === '/v1/messages' => $this->handleCancel($ctx, $body),
            $method === 'GET' && $path === '/v1/account' => Util::json(200, [
                'status' => 'complete',
                'credit_balance' => $ctx->profileHooks?->balance ?? 5000,
                'credit_price' => null,
            ]),
            $method === 'GET' && $path === '/v1/senders' => $this->handleSenders($ctx),
            $method === 'POST' && $path === '/v1/senders' => $this->handleRegisterSender($ctx, $body),
            $method === 'GET' && $path === '/v1/unsubscribes' => $this->handleUnsubscribes($ctx),
            $method === 'DELETE' && $path === '/v1/unsubscribes' => $this->handleRemoveUnsubscribe($ctx, $body),
            default => Util::json(404, ['error' => 'unknown endpoint']),
        };
    }

    // ── Messages ───────────────────────────────────────────────────────

    /** POST /v1/messages: one result per MessageInput, in request order. */
    private function handleSend(Ctx $ctx, array $body): never
    {
        $messages = $body['messages'] ?? null;
        if (!\is_array($messages) || $messages === []) {
            Util::json(400, ['error' => 'Missing or invalid "messages" parameter. It should be an array of messages.']);
        }

        $results = [];
        foreach ($messages as $m) {
            $to = self::international((string) ($m['to'] ?? ''));
            $sender = self::international((string) ($m['sender'] ?? ''));
            $text = (string) ($m['message'] ?? '');
            $scheduledAt = null;
            if (($m['scheduled_for'] ?? '') !== '') {
                $scheduledAt = (new \DateTimeImmutable((string) $m['scheduled_for']))
                    ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                // Live behaviour: 60 s ahead or less is sent at once, silently.
                if ($scheduledAt->getTimestamp() <= $ctx->now->getTimestamp() + 60) {
                    $scheduledAt = null;
                }
            }

            $remoteId = self::uuid();
            $ctx->store->insertMessage(
                $ctx->profileName, self::PROVIDER, $remoteId,
                $sender, $to, $text,
                $ctx->now,
                scheduledSendAt: $scheduledAt,
            );
            // `encoding` (live: "ucs2" for unicode content, absent when
            // enable_unicode is off) is omitted: Jethro doesn't read it.
            $results[] = [
                'to' => $to,
                'message' => $text,
                'sender' => $sender,
                'custom_ref' => $m['custom_ref'] ?? null,
                'status' => 'success',
                'cost' => 1,
                'message_id' => $remoteId,
                'conversation' => 0,
                'scheduled_for' => $scheduledAt === null ? null : self::utc($scheduledAt),
            ];
        }
        Util::json(200, [
            'status' => 'complete',
            // Echo the request's send options (false unless asked for).
            'ignore_unsubscribes' => (bool) ($body['ignore_unsubscribes'] ?? false),
            'shorten_urls' => (bool) ($body['shorten_urls'] ?? false),
            'total_cost' => array_sum(array_column($results, 'cost')),
            'results' => $results,
        ]);
    }

    /** GET /v1/messages?message_id=: the message is results[0]. */
    private function handleLookup(Ctx $ctx, string $messageId): never
    {
        $row = $ctx->store->getMessageByRemoteId(self::PROVIDER, $messageId);
        if ($row === null) {
            Util::json(404, ['error' => 'No message(s) found with the given ID or reference.']);
        }
        $status = $ctx->state->deriveMessage($row, $ctx->now);
        Util::json(200, ['status' => 'complete', 'results' => [[
            'to' => $row['destination'],
            'message' => $row['body'],
            'sender' => $row['sender'],
            'custom_ref' => null,
            'status' => self::apiStatus($status),
            'cost' => $status === State::MSG_CANCELLED ? '0.00' : '1.00',
            'message_id' => $row['remote_id'],
            'requested_at' => self::utc(new \DateTimeImmutable($row['created_at'])),
            'scheduled_for' => self::utcOrNull($row['scheduled_send_at']),
        ]]]);
    }

    /** GET /v1/messages?from=&to=&limit=&offset=: account history, newest
     *  first; from/to are UTC days, `to` exclusive. */
    private function handleHistory(Ctx $ctx): never
    {
        $from = (string) ($_GET['from'] ?? '');
        $to = (string) ($_GET['to'] ?? '');
        $items = [];
        foreach ($ctx->store->listMessagesForScope($ctx->profileName, self::PROVIDER) as $row) {
            $requestedAt = self::utc(new \DateTimeImmutable($row['created_at']));
            $day = substr($requestedAt, 0, 10);
            if (($from !== '' && $day < $from) || ($to !== '' && $day >= $to)) continue;
            $status = $ctx->state->deriveMessage($row, $ctx->now);
            $items[] = [
                'message_id' => $row['remote_id'],
                'recipient_number' => $row['destination'],
                'sender_id' => $row['sender'],
                'message_content' => $row['body'],
                'status' => self::apiStatus($status),
                'cost' => $status === State::MSG_CANCELLED ? '0.00' : '1.00',
                'custom_ref' => null,
                'requested_at' => $requestedAt,
                'scheduled_for' => self::utcOrNull($row['scheduled_send_at']),
            ];
        }
        Util::json(200, self::page($items));
    }

    /** DELETE /v1/messages {"message_id"}: only a still-scheduled message cancels. */
    private function handleCancel(Ctx $ctx, array $body): never
    {
        $messageId = (string) ($body['message_id'] ?? '');
        if ($messageId === '') {
            Util::json(400, ['error' => '"message_id", "custom_ref", or "send_id" is required.']);
        }
        $row = $ctx->store->getMessageByRemoteId(self::PROVIDER, $messageId);
        if ($row === null) {
            // Live wording differs from the lookup's 404.
            Util::json(404, ['error' => 'Message not found.']);
        }
        if ($ctx->state->deriveMessage($row, $ctx->now) !== State::MSG_SCHEDULED) {
            // Live wording; the status code was not recorded.
            Util::json(409, ['error' => 'Message is not in a scheduled state.']);
        }
        $ctx->store->markMessageCancelled((int) $row['id'], $ctx->now);
        Util::json(200, [
            'status' => 'complete',
            'message_id' => $messageId,
            'cancelled' => true,
            'credits_refunded' => 1,
        ]);
    }

    // ── Senders ────────────────────────────────────────────────────────

    /** GET /v1/senders: not paginated live; the shared number, the
     *  profile's senders, then numbers verified through POST /v1/senders. */
    private function handleSenders(Ctx $ctx): never
    {
        $out = [self::SHARED_NUMBER => ['sender' => self::SHARED_NUMBER, 'type' => 'shared', 'label' => 'Shared Number', 'is_default' => true]];
        foreach (MobileMessageProfile::get($ctx->profileName)?->senders ?? [] as $s) {
            $out[$s['sender']] = $s + ['is_default' => false];
        }
        foreach ($ctx->store->listRegistrationsForScope($ctx->profileName, self::PROVIDER) as $row) {
            if ($ctx->state->deriveApproval($row, $ctx->now) !== State::APPROVAL_APPROVED) continue;
            $out[$row['value']] ??= ['sender' => $row['value'], 'type' => 'own', 'label' => $row['display_name'], 'is_default' => false];
        }
        Util::json(200, ['status' => 'complete', 'results' => array_values($out)]);
    }

    /** POST /v1/senders {"number", "label"?}: texts the number a verification
     *  link; the number is listed once verified (approvalDelay). Refusals are
     *  recorded live (2026-10): a body without "number" is a 400, and a
     *  number already on the account (shared, profile or verified) is a 409.
     *  The success body was not recorded live; Jethro reads only an error. */
    private function handleRegisterSender(Ctx $ctx, array $body): never
    {
        $number = self::international((string) ($body['number'] ?? ''));
        if ($number === '') {
            Util::json(400, ['error' => '"number" is required.']);
        }
        $active = [self::SHARED_NUMBER];
        foreach (MobileMessageProfile::get($ctx->profileName)?->senders ?? [] as $s) {
            $active[] = $s['sender'];
        }
        foreach ($ctx->store->listRegistrationsForScope($ctx->profileName, self::PROVIDER) as $row) {
            if ($ctx->state->deriveApproval($row, $ctx->now) === State::APPROVAL_APPROVED) {
                $active[] = $row['value'];
            }
        }
        if (\in_array($number, $active, true)) {
            Util::json(409, ['error' => 'This number is already an active sender on your account.']);
        }
        $ctx->store->upsertRegistration(
            $ctx->profileName, self::PROVIDER, 'number', $number, (string) ($body['label'] ?? $number),
            rawRequest: json_encode($body),
            createdAt: $ctx->now,
        );
        Util::json(200, ['status' => 'complete', 'sender' => $number]);
    }

    // ── Unsubscribes ───────────────────────────────────────────────────

    private function handleUnsubscribes(Ctx $ctx): never
    {
        $items = array_map(
            fn(string $n) => ['number' => $n, 'updated_at' => self::utc($ctx->now)],
            MobileMessageProfile::get($ctx->profileName)?->optOuts ?? [],
        );
        Util::json(200, self::page($items));
    }

    /** DELETE /v1/unsubscribes {"number"}: `removed` is false when the number
     *  wasn't listed. Profile opt-outs are static, so nothing is persisted. */
    private function handleRemoveUnsubscribe(Ctx $ctx, array $body): never
    {
        $number = self::international((string) ($body['number'] ?? ''));
        if ($number === '') {
            Util::json(400, ['error' => '"number" is required.']);
        }
        Util::json(200, [
            'status' => 'complete',
            'number' => $number,
            'removed' => \in_array($number, MobileMessageProfile::get($ctx->profileName)?->optOuts ?? [], true),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /** The {total, limit, offset, results} envelope of the list endpoints. */
    private static function page(array $items): array
    {
        $limit = (int) ($_GET['limit'] ?? 50);
        $offset = (int) ($_GET['offset'] ?? 0);
        return [
            'status' => 'complete',
            'total' => \count($items),
            'limit' => $limit,
            'offset' => $offset,
            'results' => \array_slice($items, $offset, $limit),
        ];
    }

    private static function apiStatus(string $status): string
    {
        return match ($status) {
            State::MSG_SCHEDULED => 'scheduled',
            State::MSG_SENT => 'sent',
            State::MSG_DELIVERED => 'delivered',
            State::MSG_CANCELLED => 'cancelled',
            default => 'failed',
        };
    }

    /** Australian local numbers become 614…; alphanumeric senders pass through. */
    private static function international(string $n): string
    {
        $digits = preg_replace('/^\+/', '', trim($n));
        return preg_match('/^0\d{9}$/', $digits) ? '61' . substr($digits, 1) : $digits;
    }

    private static function utc(\DateTimeImmutable $t): string
    {
        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function utcOrNull(?string $storedTimestamp): ?string
    {
        return $storedTimestamp === null ? null : self::utc(new \DateTimeImmutable($storedTimestamp));
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0f) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
