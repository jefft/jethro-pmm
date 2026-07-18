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
            case $method === 'GET' && preg_match('#^/sms/([^/]+)$#', $path, $m):
                $this->handleGetSmsV5($ctx, $m[1]);
            case $method === 'DELETE' && preg_match('#^/sms/([^/]+)$#', $path, $m):
                $this->handleDeleteSmsV5($ctx, $m[1]);
            case $method === 'POST' && $path === '/senderid':
                $this->handlePostSenderIdV5($ctx);
            case $method === 'GET' && $path === '/senderid':
                $this->handleGetSenderIdV5($ctx);

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
            'balance' => ['credits' => $balance],
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

        $scheduledAt = ($schedule !== null && $schedule > 0)
            ? (new \DateTimeImmutable())->setTimestamp($schedule)
            : null;

        $numbers = array_filter(array_map('trim', explode(',', (string)$to)));
        $results = [];
        foreach ($numbers as $number) {
            $remoteId = '5cv5_' . \SmsMockServer\Util::randomHex(6);
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
            $ctx->store->insertMessage(
                $ctx->profileName, '5centsms', $remoteId,
                $sender, $number, $message,
                $createdAt,
                scheduledSendAt: $scheduledAt,
                forcedStatus: $pending->forcedStatus(),
                forcedReason: $pending->forcedReason(),
            );

            [$code, $text] = $this->v5StatusCode($ctx->state, $pending, $ctx->now);
            $results[] = [
                'destination' => $number,
                'id' => $remoteId,
                'status' => $code,
                'status_text' => $text,
                'credits' => 1,
            ];
        }

        \SmsMockServer\Util::json(200, ['messages' => $results]);
    }


    // ── v5 Get SMS ─────────────────────────────────────────────────────

    /** GET /sms/{id} handler for FiveCentSmsV5Provider format. */
    private function handleGetSmsV5(Ctx $ctx, string $remoteId): void
    {
        $row = $ctx->store->getMessageByRemoteId('5centsms', $remoteId);
        if ($row === null) {
            \SmsMockServer\Util::json(404, ['error' => 'SMS not found']);
        }

        $status = $ctx->state->deriveMessage($row, $ctx->now);
        [$code, $text] = $this->statusCode($status, false);
        $deliveryTs = $ctx->state->deliveryTs($status, $row['created_at'], $ctx->now);

        \SmsMockServer\Util::json(200, [
            'message' => [
                'id' => $remoteId,
                'status' => $code,
                'status_text' => $text,
                'delivery_timestamp' => $deliveryTs,
                'send_timestamp' => strtotime($row['created_at']),
            ],
        ]);
    }

    // ── v5 Delete SMS ──────────────────────────────────────────────────

    /** DELETE /sms/{id} handler for FiveCentSmsV5Provider format. */
    private function handleDeleteSmsV5(Ctx $ctx, string $remoteId): void
    {
        $row = $ctx->store->getMessageByRemoteId('5centsms', $remoteId);
        if ($row === null) {
            \SmsMockServer\Util::json(404, ['error' => 'SMS not found']);
        }

        $ctx->store->markMessageCancelled((int) $row['id'], $ctx->now);
        \SmsMockServer\Util::json(200, [
            'messages' => [
                'id' => $remoteId,
                'status' => 1007,       // CANCELLED
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

    /** GET /senderid handler for FiveCentSmsV5Provider format. */
    private function handleGetSenderIdV5(Ctx $ctx): void
    {
        $rows = $ctx->store->listRegistrationsForScope($ctx->profileName, '5centsms');
        $out = [];
        foreach ($rows as $row) {
            if ($row['kind'] !== 'senderid') continue;
            $approval = $ctx->state->deriveApproval($row, $ctx->now);
            $out[] = [
                'senderid' => $row['value'],
                'status' => $approval,
            ];
        }
        \SmsMockServer\Util::json(200, ['senderids' => $out]);
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
