<?php

namespace SmsMockServer\Provider;

/**
 * FiveCentSms v5 provider simulator.
 */
final class FiveCentSms implements ProviderInterface
{
    public function name(): string { return '5centsms'; }

    public function handle(Ctx $ctx): void
    {
        header('Content-Type: application/json');
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_SERVER['PATH_INFO'] ?? '/';

        // Endpoint override check
        if ($ctx->profileHooks !== null) {
            $key = strtoupper($method) . ' ' . $path;
            $ep = $ctx->profileHooks->endpoints[$key] ?? null;
            if ($ep !== null) {
                match ($ep->action) {
                    \SmsMockServer\Endpoint::ACTION_RETURN_JSON => \SmsMockServer\Util::json($ep->status, $ep->data),
                    \SmsMockServer\Endpoint::ACTION_RETURN_JSON_FUNC => $ep->jsonFunc
                        ? \SmsMockServer\Util::json($ep->status, ($ep->jsonFunc)(new \SmsMockServer\ProviderRequest(
                            body: file_get_contents('php://input'), query: $_GET,
                        )))
                        : \SmsMockServer\Util::json(200, ['error' => 'no jsonFunc']),
                    \SmsMockServer\Endpoint::ACTION_HANDLER => ($ep->handler)(),
                    \SmsMockServer\Endpoint::ACTION_PASSTHROUGH => null,
                };
                exit;
            }
        }

        // Route
        switch (true) {
            // ── v5 routes (bare paths, no /api/v1/ prefix) ────────────
            case $method === 'POST' && $path === '/sms':
                $this->handleSendV5($ctx);
            case $method === 'GET' && $path === '/sms':
                $this->handleListSmsV5($ctx);
            case $method === 'GET' && preg_match('#^/sms/([^/]+)$#', $path, $m):
                $this->handleGetSmsV5($ctx, $m[1]);
            case $method === 'DELETE' && preg_match('#^/sms/([^/]+)$#', $path, $m):
                $this->handleDeleteSmsV5($ctx, $m[1]);
            case $method === 'POST' && $path === '/senderid':
                $this->handlePostSenderIdV5($ctx);
            case $method === 'GET' && $path === '/senderid':
                $this->handleGetSenderIdV5($ctx);
            case $method === 'GET' && $path === '/optouts':
                $this->handleOptOutsV5($ctx);
            case $method === 'DELETE' && preg_match('#^/optouts/([^/]+)$#', $path, $m):
                $this->handleDeleteOptOutV5($ctx, $m[1]);

            case $method === 'POST' && $path === '/api/v1/sendSms':
                $this->handleSend($ctx);
            case $method === 'GET' && preg_match('#^/api/v1/sms/([^/]+)$#', $path, $m):
                $this->handleGetSms($ctx, $m[1]);
            case $method === 'DELETE' && preg_match('#^/api/v1/sms/([^/]+)$#', $path, $m):
                $this->handleDeleteSms($ctx, $m[1]);
            case $method === 'GET' && $path === '/api/v1/sms':
                $this->handleListSms($ctx);
            case $method === 'POST' && $path === '/api/v1/senderid':
                $this->handlePostSenderId($ctx);
            case $method === 'GET' && $path === '/api/v1/senderid':
                $this->handleGetSenderId($ctx);
            case $method === 'DELETE' && preg_match('#^/api/v1/senderid/([^/]+)$#', $path, $m):
                $this->handleDeleteSenderId($ctx, $m[1]);
            case $method === 'GET' && $path === '/api/v1/balance':
            case $method === 'GET' && $path === '/balance':
                $this->handleBalanceV5($ctx);
            case $method === 'GET' && $path === '/api/v1/optouts':
                $this->handleOptOuts($ctx);
            case $method === 'DELETE' && $path === '/api/v1/optouts':
                $this->handleDeleteOptOuts($ctx);
            default:
                \SmsMockServer\Util::json(404, ['error' => 'unknown endpoint']);
        }
    }

    // ── Send ───────────────────────────────────────────────────────────

    private function handleSend(Ctx $ctx): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $numbers = $body['numbers'] ?? [];
        $message = $body['message'] ?? '';
        $senderId = $body['sender_id'] ?? '';

        $results = [];
        foreach ($numbers as $number) {
            $remoteId = '5c_' . \SmsMockServer\Util::randomHex(6);
            $pending = new \SmsMockServer\PendingSMS();
            $pending->provider = '5centsms';
            $pending->sender = $senderId;
            $pending->destination = (string) $number;
            $pending->body = $message;

            if ($ctx->profileHooks?->onSendHook !== null) {
                ($ctx->profileHooks->onSendHook)($pending);
            }

            $ctx->store->insertMessage(
                $ctx->profileName, '5centsms', $remoteId,
                $senderId, (string) $number, $message,
                $ctx->now,
                forcedStatus: $pending->forcedStatus(),
                forcedReason: $pending->forcedReason(),
            );

            $results[] = [
                'number' => $number,
                'id' => $remoteId,
                'status' => 'sent',
            ];
        }

        \SmsMockServer\Util::json(200, [
            'error_code' => 0,
            'error_msg' => 'Success',
            'results' => $results,
        ]);
    }

    // ── Get SMS ────────────────────────────────────────────────────────

    private function handleGetSms(Ctx $ctx, string $remoteId): void
    {
        $row = $ctx->store->getMessageByRemoteId('5centsms', $remoteId);
        if ($row === null) {
            \SmsMockServer\Util::json(404, ['error_code' => 1, 'error_msg' => 'SMS not found']);
        }

        $status = $ctx->state->deriveMessage($row, $ctx->now);
        [$code, $text] = $this->statusCode($status, false);
        $deliveryTs = $ctx->state->deliveryTs($status, $row['created_at'], $ctx->now);

        \SmsMockServer\Util::json(200, [
            'error_code' => 0,
            'error_msg' => 'Success',
            'sms' => [[
                'id' => $remoteId,
                'status_code' => $code,
                'status_text' => $text,
                'delivery_ts' => $deliveryTs,
            ]],
        ]);
    }

    // ── Delete SMS ─────────────────────────────────────────────────────

    private function handleDeleteSms(Ctx $ctx, string $remoteId): void
    {
        $row = $ctx->store->getMessageByRemoteId('5centsms', $remoteId);
        if ($row === null) {
            \SmsMockServer\Util::json(200, ['error_code' => 1, 'error_msg' => 'SMS not found']);
        }

        // Mark as cancelled
        $ctx->store->markMessageCancelled((int) $row['id'], $ctx->now);
        \SmsMockServer\Util::json(200, ['error_code' => 0, 'error_msg' => 'Deleted']);
    }

    // ── List SMS ───────────────────────────────────────────────────────

    private function handleListSms(Ctx $ctx): void
    {
        $rows = $ctx->store->listMessagesForScope($ctx->profileName, '5centsms');
        $out = [];
        foreach ($rows as $row) {
            $status = $ctx->state->deriveMessage($row, $ctx->now);
            [$code, $text] = $this->statusCode($status, false);
            $deliveryTs = $ctx->state->deliveryTs($status, $row['created_at'], $ctx->now);
            $out[] = [
                'id' => $row['remote_id'],
                'status_code' => $code,
                'status_text' => $text,
                'delivery_ts' => $deliveryTs,
            ];
        }
        \SmsMockServer\Util::json(200, [
            'error_code' => 0,
            'error_msg' => 'Success',
            'sms' => $out,
        ]);
    }

    // ── Sender ID Registration ─────────────────────────────────────────

    private function handlePostSenderId(Ctx $ctx): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $senderId = $body['sender_id'] ?? '';

        if ($senderId === '') {
            \SmsMockServer\Util::json(200, ['error_code' => 1, 'error_msg' => 'Missing sender_id']);
        }

        // Idempotency check
        $existing = $ctx->store->getRegistration('5centsms', $ctx->profileName, 'senderid', $senderId);
        if ($existing !== null) {
            $approval = $ctx->state->deriveApproval($existing, $ctx->now);
            if ($approval === \SmsMockServer\State::APPROVAL_APPROVED) {
                \SmsMockServer\Util::json(200, [
                    'error_code' => 1,
                    'error_msg' => 'Sender ID already registered',
                ]);
            }
        }

        $pending = new \SmsMockServer\PendingRegistration();
        $pending->provider = '5centsms';
        $pending->kind = 'senderid';
        $pending->value = $senderId;
        $pending->displayName = $senderId;
        $pending->rawBody = json_encode($body);

        if ($ctx->profileHooks?->onRegisterHook !== null) {
            ($ctx->profileHooks->onRegisterHook)($pending);
        }

        $action = $pending->action();
        switch ($action) {
            case \SmsMockServer\PendingRegistration::ACTION_REJECT:
                \SmsMockServer\Util::json(200, [
                    'error_code' => 1,
                    'error_msg' => $pending->rejectReason(),
                ]);
            case \SmsMockServer\PendingRegistration::ACTION_APPROVE:
                $ctx->store->upsertRegistration(
                    $ctx->profileName, '5centsms', 'senderid', $senderId, $senderId,
                    approvedAt: $ctx->now,
                    rawRequest: json_encode($body),
                    createdAt: $ctx->now,
                );
                \SmsMockServer\Util::json(200, ['error_code' => 0, 'error_msg' => 'Registered']);
            default:
                $ctx->store->upsertRegistration(
                    $ctx->profileName, '5centsms', 'senderid', $senderId, $senderId,
                    rawRequest: json_encode($body),
                    createdAt: $ctx->now,
                );
                \SmsMockServer\Util::json(200, ['error_code' => 0, 'error_msg' => 'Registered']);
        }
    }

    // ── Get Sender IDs ─────────────────────────────────────────────────

    private function handleGetSenderId(Ctx $ctx): void
    {
        $rows = $ctx->store->listRegistrationsForScope($ctx->profileName, '5centsms');
        $out = [];
        foreach ($rows as $row) {
            if ($row['kind'] !== 'senderid') continue;
            $approval = $ctx->state->deriveApproval($row, $ctx->now);
            $out[] = [
                'sender_id' => $row['value'],
                'status' => $approval,
                'created_at' => $row['created_at'],
            ];
        }
        \SmsMockServer\Util::json(200, [
            'error_code' => 0,
            'error_msg' => 'Success',
            'sender_ids' => $out,
        ]);
    }

    // ── Delete Sender ID ───────────────────────────────────────────────

    private function handleDeleteSenderId(Ctx $ctx, string $senderId): void
    {
        $row = $ctx->store->getRegistration('5centsms', $ctx->profileName, 'senderid', $senderId);
        if ($row === null) {
            \SmsMockServer\Util::json(200, ['error_code' => 1, 'error_msg' => 'Sender ID not found']);
        }
        $ctx->store->deleteRegistration((int) $row['id']);
        \SmsMockServer\Util::json(200, ['error_code' => 0, 'error_msg' => 'Deleted']);
    }

    // ── Balance ────────────────────────────────────────────────────────

    private function handleBalance(Ctx $ctx): void
    {
        $balance = $ctx->profileHooks?->balance ?? 5000;
        \SmsMockServer\Util::json(200, [
            'error_code' => 0,
            'error_msg' => 'Success',
            'balance' => $balance,
        ]);
    }

    /** Balance endpoint for FiveCentSmsV5Provider — returns {balance: {credits: N}} format. */
    private function handleBalanceV5(Ctx $ctx): void
    {
        $balance = $ctx->profileHooks?->balance ?? 5000;
        \SmsMockServer\Util::json(200, [
            // Live shape (recorded 2026-10): postpaid flag beside the credits.
            'error' => '',
            'balance' => ['postpaid' => false, 'credits' => $balance],
        ]);
    }

    // ── v5 Send ────────────────────────────────────────────────────────

    /** POST /sms handler for FiveCentSmsV5Provider format. */
    private function handleSendV5(Ctx $ctx): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $to = $body['to'] ?? '';
        $message = $body['message'] ?? '';
        $sender = $body['sender'] ?? '';
        $schedule = isset($body['schedule']) ? (int)$body['schedule'] : null;

        // Recorded live: a request without recipients is rejected outright.
        if (trim((string) $to) === '') {
            \SmsMockServer\Util::json(400, ['error' => 'Failed (Invalid Destination)']);
        }

        $scheduledAt = ($schedule !== null && $schedule > 0)
            ? (new \DateTimeImmutable())->setTimestamp($schedule)
            : null;

        $numbers = array_filter(array_map('trim', explode(',', (string)$to)));
        $results = [];
        foreach ($numbers as $number) {
            // Live IDs are 24 hex characters.
            $remoteId = \SmsMockServer\Util::randomHex(12);
            $pending = new \SmsMockServer\PendingSMS();
            $pending->provider = '5centsms';
            $pending->sender = $sender;
            $pending->destination = $number;
            $pending->body = $message;
            $pending->scheduledSendAt = $scheduledAt;

            if ($ctx->profileHooks?->onSendHook !== null) {
                ($ctx->profileHooks->onSendHook)($pending);
            }

            $createdAt = $scheduledAt ?? $ctx->now;
            // Live stores recipient and sender in international form; lookups
            // and history return that, while the send reply echoes the request.
            $ctx->store->insertMessage(
                $ctx->profileName, '5centsms', $remoteId,
                self::international($sender), self::international($number), $message,
                $createdAt,
                scheduledSendAt: $scheduledAt,
                forcedStatus: $pending->forcedStatus(),
                forcedReason: $pending->forcedReason(),
            );

            [$code, $text] = $this->v5StatusCode($ctx->state, $pending, $ctx->now);
            $results[] = array_filter([
                'destination' => $number,
                'id' => $remoteId,
                'status' => $code,
                'status_text' => $text,
                'credits' => 1,
                // Recorded live: a scheduled send echoes its schedule time.
                'schedule' => $scheduledAt?->getTimestamp(),
            ], fn($v) => $v !== null);
        }

        \SmsMockServer\Util::json(200, ['error' => '', 'messages' => $results]);
    }

    /** Australian local numbers become 614…; sender IDs pass through. */
    private static function international(string $n): string
    {
        $digits = preg_replace('/^\+/', '', trim($n));
        return preg_match('/^0\d{9}$/', $digits) ? '61' . substr($digits, 1) : $digits;
    }


    // ── v5 Get SMS ─────────────────────────────────────────────────────

    /** GET /sms/{id} handler for FiveCentSmsV5Provider format.
     *  Live shape (probed 2026-10): {error:"", message:{destination, sender,
     *  id, status:int, status_text, message_text, credits, send_timestamp,
     *  delivery_timestamp("" until delivered), delivery_network,
     *  customer_data}}. */
    private function handleGetSmsV5(Ctx $ctx, string $remoteId): void
    {
        $row = $ctx->store->getMessageByRemoteId('5centsms', $remoteId);
        if ($row === null) {
            // Recorded live: an unknown ID is answered with this, not a 404.
            \SmsMockServer\Util::json(400, ['error' => 'Failed (Unauthorised)']);
        }

        \SmsMockServer\Util::json(200, [
            'error' => '',
            'message' => $this->v5MessageItem($ctx, $row),
        ]);
    }

    // ── v5 List SMS ────────────────────────────────────────────────────

    /** GET /sms handler for FiveCentSmsV5Provider format.
     *  Live shape (recorded 2026-10): {count, error:"", messages:[item…],
     *  next_page:"/api/v5/sms?after=<cursor>"}, one page of 100; the cursor is
     *  the newest-last id, and an empty `after` marks the last page. */
    private function handleListSmsV5(Ctx $ctx): void
    {
        $rows = $ctx->store->listMessagesForScope($ctx->profileName, '5centsms');
        $after = (string) ($_GET['after'] ?? '');
        if ($after !== '') {
            $i = array_search($after, array_column($rows, 'remote_id'), true);
            $rows = $i === false ? [] : array_slice($rows, $i + 1);
        }
        $page = array_slice($rows, 0, 100);
        $hasMore = count($rows) > 100;
        \SmsMockServer\Util::json(200, [
            'count' => count($page),
            'error' => '',
            'messages' => array_map(fn(array $row) => $this->v5MessageItem($ctx, $row), $page),
            'next_page' => '/api/v5/sms?after=' . ($hasMore ? $page[count($page) - 1]['remote_id'] : ''),
        ]);
    }

    /** One message as the v5 GET /sms endpoints return it. Status codes are v5's (1001 sent, 1002 delivered, …) — probed live; NOT the v4-style 1/2/3 of statusCode(). @param array<string,mixed> $row @return array<string,mixed> */
    private function v5MessageItem(Ctx $ctx, array $row): array
    {
        $status = $ctx->state->deriveMessage($row, $ctx->now);
        [$code, $text] = match ($status) {
            \SmsMockServer\State::MSG_DELIVERED => [1002, 'Delivered'],
            \SmsMockServer\State::MSG_SENT      => [1001, 'Sent'],
            \SmsMockServer\State::MSG_FAILED    => [1003, 'Failed'],
            \SmsMockServer\State::MSG_SCHEDULED => [1005, 'Scheduled'],
            \SmsMockServer\State::MSG_CANCELLED => [1007, 'Cancelled'],
            default                             => [1003, 'Failed'],
        };
        $deliveryTs = $status === \SmsMockServer\State::MSG_DELIVERED
            ? $ctx->state->deliveryTs($status, $row['created_at'], $ctx->now)
            : null;
        return [
            'destination'        => $row['destination'],
            'sender'             => $row['sender'],
            'id'                 => $row['remote_id'],
            'status'             => $code,
            'status_text'        => $text,
            // Recorded live: a cancelled message's text is cleared.
            'message_text'       => $status === \SmsMockServer\State::MSG_CANCELLED ? '' : $row['body'],
            'credits'            => 1,
            'send_timestamp'     => strtotime($row['created_at']),
            'delivery_timestamp' => $deliveryTs ?? '',
            'delivery_network'   => '',
            'customer_data'      => '',
        ];
    }

    // ── v5 Delete SMS ──────────────────────────────────────────────────

    /** DELETE /sms/{id} handler for FiveCentSmsV5Provider format.
     *  Recorded live: cancelling a scheduled message answers with a string
     *  status; cancelling it again is not an error but a DELETED stub. */
    private function handleDeleteSmsV5(Ctx $ctx, string $remoteId): void
    {
        $row = $ctx->store->getMessageByRemoteId('5centsms', $remoteId);
        if ($row === null) {
            \SmsMockServer\Util::json(404, ['error' => 'SMS not found']);
        }

        $status = $ctx->state->deriveMessage($row, $ctx->now);
        if ($status === \SmsMockServer\State::MSG_CANCELLED) {
            \SmsMockServer\Util::json(200, [
                'error' => '',
                'messages' => [
                    'destination' => '',
                    'id' => $remoteId,
                    'status' => 1007,
                    'status_text' => 'Cancelled',
                    'message_text' => 'DELETED',
                ],
            ]);
        }
        if ($status !== \SmsMockServer\State::MSG_SCHEDULED) {
            \SmsMockServer\Util::json(400, ['error' => 'Message not found or already delivered.']);
        }

        $ctx->store->markMessageCancelled((int) $row['id'], $ctx->now);
        \SmsMockServer\Util::json(200, [
            'error' => '',
            'messages' => [
                'destination' => $row['destination'],
                'id' => $remoteId,
                'status' => '1007',       // Recorded live: a string here, unlike the lookups.
                'status_text' => 'Cancelled',
            ],
        ]);
    }

    // ── v5 Sender ID Registration ──────────────────────────────────────

    /** POST /senderid handler for FiveCentSmsV5Provider format. */
    private function handlePostSenderIdV5(Ctx $ctx): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $senderId = $body['senderid'] ?? '';

        if ($senderId === '') {
            \SmsMockServer\Util::json(200, ['error' => 'Missing senderid']);
        }

        // Idempotency check
        $existing = $ctx->store->getRegistration('5centsms', $ctx->profileName, 'senderid', $senderId);
        if ($existing !== null) {
            $approval = $ctx->state->deriveApproval($existing, $ctx->now);
            if ($approval === \SmsMockServer\State::APPROVAL_APPROVED) {
                \SmsMockServer\Util::json(200, ['error' => 'Sender ID already registered']);
            }
        }

        $pending = new \SmsMockServer\PendingRegistration();
        $pending->provider = '5centsms';
        $pending->kind = 'senderid';
        $pending->value = $senderId;
        $pending->displayName = $senderId;
        $pending->rawBody = json_encode($body);

        if ($ctx->profileHooks?->onRegisterHook !== null) {
            ($ctx->profileHooks->onRegisterHook)($pending);
        }

        $action = $pending->action();
        switch ($action) {
            case \SmsMockServer\PendingRegistration::ACTION_REJECT:
                \SmsMockServer\Util::json(200, ['error' => $pending->rejectReason()]);
            case \SmsMockServer\PendingRegistration::ACTION_APPROVE:
                $ctx->store->upsertRegistration(
                    $ctx->profileName, '5centsms', 'senderid', $senderId, $senderId,
                    approvedAt: $ctx->now,
                    rawRequest: json_encode($body),
                    createdAt: $ctx->now,
                );
                \SmsMockServer\Util::json(200, ['senderid' => $senderId, 'status' => 'approved']);
            default:
                $ctx->store->upsertRegistration(
                    $ctx->profileName, '5centsms', 'senderid', $senderId, $senderId,
                    rawRequest: json_encode($body),
                    createdAt: $ctx->now,
                );
                \SmsMockServer\Util::json(200, ['senderid' => $senderId, 'status' => 'approved']);
        }
    }

    // ── v5 Get Sender IDs ──────────────────────────────────────────────

    /** GET /senderid handler for FiveCentSmsV5Provider format: the profile's
     *  approved senders, then those registered through POST /senderid. */
    private function handleGetSenderIdV5(Ctx $ctx): void
    {
        $out = [];
        foreach (FiveCentSmsProfile::get($ctx->profileName)?->senders ?? [] as $sender) {
            $out[$sender['senderid']] = self::senderIdItem($sender);
        }
        $rows = $ctx->store->listRegistrationsForScope($ctx->profileName, '5centsms');
        foreach ($rows as $row) {
            if ($row['kind'] !== 'senderid') continue;
            $out[$row['value']] ??= self::senderIdItem([
                'senderid' => $row['value'],
                'status' => $ctx->state->deriveApproval($row, $ctx->now),
            ]);
        }
        \SmsMockServer\Util::json(200, ['error' => '', 'senderids' => array_values($out)]);
    }

    /** One GET /senderid item. Recorded live: items carry an id and a shared
     *  flag; acma_approved ones also carry acma_details_id and
     *  acma_additional_details. */
    private static function senderIdItem(array $item): array
    {
        return $item + [
            'id' => substr(md5((string) $item['senderid']), 0, 24),
            'shared' => false,
        ];
    }

    /** DELETE /optouts/{number}. Recorded live: 400 "Number not found" for a
     *  number that isn't listed; the documented success reply is below. */
    private function handleDeleteOptOutV5(Ctx $ctx, string $number): void
    {
        $number = self::international(urldecode($number));
        $listed = \in_array($number, FiveCentSmsProfile::get($ctx->profileName)?->optOuts ?? [], true);
        if (!$listed) {
            \SmsMockServer\Util::json(400, ['error' => 'Number not found']);
        }
        \SmsMockServer\Util::json(200, ['error' => '', 'message' => 'Success']);
    }

    /** GET /optouts?after= handler, recorded live shape (see
     *  docs/docs/developer/reference/sms/_5centsmsv5/optouts/GET.md). One
     *  page holds every entry; its next_page cursor leads to the terminal
     *  page (count 0), as the live API's does. */
    private function handleOptOutsV5(Ctx $ctx): void
    {
        $numbers = [];
        if (($_GET['after'] ?? '') === '') {
            foreach (FiveCentSmsProfile::get($ctx->profileName)?->optOuts ?? [] as $number) {
                $numbers[] = [
                    'id' => substr(md5($number), 0, 24),
                    'number' => $number,
                    'timestamp' => $ctx->now->getTimestamp(),
                ];
            }
        }
        $last = $numbers === [] ? '' : $numbers[array_key_last($numbers)]['id'];
        \SmsMockServer\Util::json(200, [
            'error' => '',
            'numbers' => $numbers,
            'count' => \count($numbers),
            'next_page' => 'api/v5/optouts?after=' . $last,
        ]);
    }



    // ── OptOuts ────────────────────────────────────────────────────────

    private function handleOptOuts(Ctx $ctx): void
    {
        \SmsMockServer\Util::json(200, [
            'error_code' => 0,
            'error_msg' => 'Success',
            'optouts' => [],
        ]);
    }

    private function handleDeleteOptOuts(Ctx $ctx): void
    {
        \SmsMockServer\Util::json(200, ['error_code' => 0, 'error_msg' => 'Deleted']);
    }

    // ── Status mapping ─────────────────────────────────────────────────

    /** @return array{int, string} */
    private function statusCode(string $status, bool $test): array
    {
        return match ($status) {
            \SmsMockServer\State::MSG_DELIVERED => [1, 'Delivered'],
            \SmsMockServer\State::MSG_SENT      => [2, 'Sent'],
            \SmsMockServer\State::MSG_FAILED    => [3, 'Failed'],
            \SmsMockServer\State::MSG_SCHEDULED => [2, 'Sent'],
            \SmsMockServer\State::MSG_CANCELLED => [3, 'Failed'],
            \SmsMockServer\State::MSG_UNKNOWN   => [3, 'Failed'],
            default                            => [2, 'Sent'],
        };
    }

    // ── v5 Status mapping ──────────────────────────────────────────────

    /**
     * Map a PendingSMS at creation time to a v5 status code and text.
     * Uses the State machine's logic without a DB round-trip.
     * @return array{int, string}
     */
    private function v5StatusCode(\SmsMockServer\State $state, \SmsMockServer\PendingSMS $pending, \DateTimeImmutable $now): array
    {
        $forced = $pending->forcedStatus();
        if ($forced !== null && $forced !== '') {
            return match ($forced) {
                \SmsMockServer\State::MSG_FAILED    => [1003, 'Failed'],
                \SmsMockServer\State::MSG_CANCELLED => [1007, 'Cancelled'],
                default                             => [1003, 'Failed'],
            };
        }

        if ($pending->scheduledSendAt !== null && $now < $pending->scheduledSendAt) {
            return [1005, 'Scheduled'];
        }

        // Default: SENT (delivery delay window starts now)
        return [1001, 'Sent'];
    }

}
