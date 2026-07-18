<?php

namespace SmsMockServer\Provider;

use SmsMockServer\Util;
use SmsMockServer\PendingSMS;
use SmsMockServer\PendingRegistration;
use SmsMockServer\State;

/**
 * Cellcast SMS provider simulator.
 *
 * Implements the Cellcast API surface: send, cancel, account/balance,
 * custom number CRUD, opt-outs, report endpoints.
 */
final class Cellcast implements ProviderInterface
{
    public function name(): string { return 'cellcast'; }

    public function handle(Ctx $ctx): void
    {
        header('Content-Type: application/json');
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_SERVER['PATH_INFO'] ?? '/';

        // Endpoint override check
        $override = $this->tryOverride($ctx, $method, $path);
        if ($override) return;

        switch (true) {
            case $method === 'POST' && $path === '/api/v1/gateway':
                $this->handleSend($ctx);
            case $method === 'POST' && $path === '/api/v1/gateway/cancelScheduleQuickMessage':
                $this->handleCancel($ctx);
            case $method === 'GET' && $path === '/api/v1/apiClient/account':
                $this->handleAccount($ctx);
            case $method === 'GET' && str_starts_with($path, '/api/v1/apiClient/getOptout'):
                $this->handleGetOptout($ctx);
            case $method === 'GET' && $path === '/api/v1/customNumber':
                $this->handleGetCustomNumber($ctx);
            case $method === 'POST' && $path === '/api/v1/customNumber/add':
                $this->handleAddCustomNumber($ctx);
            case $method === 'POST' && $path === '/api/v1/customNumber/verifyCustomNumber':
                $this->handleVerifyCustomNumber($ctx);
            case $method === 'POST' && $path === '/api/v1/business/add':
                $this->handleBusinessAdd($ctx);
            case $method === 'GET' && preg_match('#^/api/v2/report/message/([^/]+)$#', $path, $m):
                $this->handleReportMessage($ctx, $m[1]);
            case $method === 'GET' && $path === '/api/v2/report/message':
                $this->handleReportMessages($ctx);
            default:
                Util::json(404, ['status' => false, 'message' => 'unknown endpoint']);
        }
    }

    // ── Override ───────────────────────────────────────────────────────

    private function tryOverride(Ctx $ctx, string $method, string $path): bool
    {
        if ($ctx->profileHooks === null) return false;
        $key = strtoupper($method) . ' ' . $path;
        $ep = $ctx->profileHooks->endpoints[$key] ?? null;
        if ($ep === null) return false;

        switch ($ep->action) {
            case \SmsMockServer\Endpoint::ACTION_RETURN_JSON:
                Util::json($ep->status, $ep->data);
            case \SmsMockServer\Endpoint::ACTION_RETURN_JSON_FUNC:
                if ($ep->jsonFunc !== null) {
                    $req = new \SmsMockServer\ProviderRequest(
                        body: file_get_contents('php://input'),
                        query: $_GET,
                    );
                    Util::json($ep->status, ($ep->jsonFunc)($req));
                }
                Util::json(200, ['error' => 'no jsonFunc configured']);
            case \SmsMockServer\Endpoint::ACTION_HANDLER:
                if ($ep->handler !== null) {
                    ($ep->handler)();
                    exit;
                }
                Util::json(200, ['error' => 'no handler configured']);
            case \SmsMockServer\Endpoint::ACTION_PASSTHROUGH:
                return false;
        }
        return true;
    }

    // ── Send ───────────────────────────────────────────────────────────

    private function handleSend(Ctx $ctx): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $message = $body['message'] ?? '';
        $contacts = $body['contacts'] ?? [];
        $sender = $body['sender'] ?? '';
        $scheduleAt = Util::parseScheduleAt($body['scheduleAt'] ?? null);

        $queueResponse = [];
        foreach ($contacts as $dest) {
            $remoteId = 'cc_' . Util::randomHex(6);
            $pending = new PendingSMS();
            $pending->sender = $sender;
            $pending->destination = (string) $dest;
            $pending->body = $message;
            $pending->scheduledSendAt = $scheduleAt;

            if ($ctx->profileHooks?->onSendHook !== null) {
                ($ctx->profileHooks->onSendHook)($pending);
            }

            $createdAt = $scheduleAt ?? $ctx->now;
            $ctx->store->insertMessage(
                $ctx->profileName, 'cellcast', $remoteId,
                $sender, (string) $dest, $message,
                $createdAt, $scheduleAt,
                $pending->forcedStatus(), $pending->forcedReason(),
            );

            $status = $scheduleAt !== null ? 'queued' : 'sent';
            $queueResponse[] = [
                'Number' => $dest,
                'MessageId' => $remoteId,
                'jobInfo' => ['data' => ['messageData' => ['status' => $status]]],
            ];
        }

        $resp = [
            'status' => true,
            'message' => 'SMS Sent Successfully',
            'data' => ['queueResponse' => $queueResponse],
        ];
        if ($scheduleAt !== null) {
            $resp['data']['scheduleAt'] = $scheduleAt->format('Y-m-d H:i:s');
        }
        Util::json(200, $resp);
    }

    // ── Cancel ─────────────────────────────────────────────────────────

    private function handleCancel(Ctx $ctx): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $messageId = $body['messageId'] ?? '';

        $row = $ctx->store->getMessageByRemoteId('cellcast', $messageId);
        if ($row === null) {
            Util::json(200, [
                'status' => false, 'message' => 'message not found',
                'data' => [], 'error' => ['error' => 'message not found'],
            ]);
        }

        $status = $ctx->state->deriveMessage($row, $ctx->now);
        if ($status === State::MSG_CANCELLED) {
            Util::json(200, [
                'status' => false, 'message' => 'message not found',
                'data' => [], 'error' => ['error' => 'message not found'],
            ]);
        }

        if ($ctx->profileHooks?->onCancelHook !== null) {
            ($ctx->profileHooks->onCancelHook)($row);
        }

        $ctx->store->markMessageCancelled((int) $row['id'], $ctx->now);
        Util::json(200, [
            'status' => true, 'message' => 'Job removed successfully',
            'data' => ['message' => 'Job removed successfully'],
        ]);
    }

    // ── Account / Balance ──────────────────────────────────────────────

    private function handleAccount(Ctx $ctx): void
    {
        $balance = $ctx->profileHooks?->balance ?? 12345;
        Util::json(200, [
            'meta' => ['code' => 200, 'status' => 'success'],
            'data' => ['sms_balance' => $balance],
        ]);
    }

    // ── GetOptout ──────────────────────────────────────────────────────

    private function handleGetOptout(Ctx $ctx): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $size = max(1, (int) ($_GET['size'] ?? 100));

        $items = [];
        $cp = CellcastProfile::get($ctx->profileName);
        if ($cp !== null) {
            foreach ($cp->optOuts as $it) {
                $items[] = [
                    'number' => $it['number'],
                    'first_name' => $it['first_name'] ?? '',
                    'last_name' => $it['last_name'] ?? '',
                    'full_name' => $it['full_name'] ?? '',
                    'email' => '',
                    'birthday' => '',
                    'address' => '',
                    'postalcode' => '',
                    'gender' => '',
                    'post_code' => '',
                    'date_of_birth' => '',
                ];
            }
        }

        $total = count($items);
        $totalPages = max(1, (int) ceil($total / $size));

        Util::json(200, [
            'meta' => ['code' => 200, 'status' => 'SUCCESS'],
            'message' => "You have $total optout contact(s)",
            'data' => [
                'items' => $items,
                'total' => $total,
                'limit' => $size,
                'current' => $page,
                'totalPages' => $totalPages,
                'pagingCounter' => ($page - 1) * $size + 1,
                'hasPrevPage' => $page > 1,
                'hasNextPage' => $page < $totalPages,
                'prevPage' => $page > 1 ? $page - 1 : null,
                'nextPage' => $page < $totalPages ? $page + 1 : null,
            ],
            'error' => [],
        ]);
    }

    // ── CustomNumber ───────────────────────────────────────────────────

    private function handleGetCustomNumber(Ctx $ctx): void
    {
        $cp = CellcastProfile::get($ctx->profileName);
        if ($cp !== null && $cp->senders !== []) {
            Util::json(200, ['data' => $cp->senders]);
        }

        // Dynamic mode: defaults + approved registrations
        $defaults = [
            ['number' => 'StJohnsWPH', 'name' => 'St Johns WPH'],
            ['number' => '614915701588', 'name' => 'Church Main'],
            ['number' => '61402000002', 'name' => 'Youth Group'],
        ];

        $out = [];
        $seen = [];
        foreach ($defaults as $d) {
            $seen[$d['number']] = true;
            $out[] = $d;
        }

        $rows = $ctx->store->listRegistrationsForScope($ctx->profileName, 'cellcast');
        foreach ($rows as $row) {
            $approval = $ctx->state->deriveApproval($row, $ctx->now);
            if ($approval !== State::APPROVAL_APPROVED) continue;

            if (isset($seen[$row['value']])) {
                // Registration wins over default; update
                foreach ($out as &$o) {
                    if ($o['number'] === $row['value']) {
                        $o['name'] = $row['display_name'];
                        break;
                    }
                }
                continue;
            }
            $seen[$row['value']] = true;
            $out[] = ['number' => $row['value'], 'name' => $row['display_name']];
        }

        Util::json(200, ['data' => $out]);
    }

    // ── Add CustomNumber ───────────────────────────────────────────────

    private function handleAddCustomNumber(Ctx $ctx): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $number = $body['number'] ?? '';
        $name = $body['name'] ?? '';

        // Idempotency check
        $existing = $ctx->store->getRegistration('cellcast', $ctx->profileName, 'number', $number);
        if ($existing !== null) {
            $approval = $ctx->state->deriveApproval($existing, $ctx->now);
            if ($approval === State::APPROVAL_APPROVED) {
                Util::json(200, ['status' => true, 'message' => 'Number already exist in system']);
            }
        }

        $pending = new PendingRegistration();
        $pending->provider = 'cellcast';
        $pending->kind = 'number';
        $pending->value = $number;
        $pending->displayName = $name;
        $pending->rawBody = json_encode($body);

        if ($ctx->profileHooks?->onRegisterHook !== null) {
            ($ctx->profileHooks->onRegisterHook)($pending);
        }

        $action = $pending->action();
        switch ($action) {
            case PendingRegistration::ACTION_REJECT:
                Util::json(200, ['status' => false, 'message' => $pending->rejectReason()]);
            case PendingRegistration::ACTION_REQUIRE_OTP:
                $ctx->store->upsertRegistration(
                    $ctx->profileName, 'cellcast', 'number', $number, $name,
                    otpCode: $pending->actionData(),
                    rawRequest: json_encode($body),
                    createdAt: $ctx->now,
                );
                Util::json(200, ['status' => true, 'message' => 'OTP sent to your number successfully']);
            case PendingRegistration::ACTION_APPROVE:
                $ctx->store->upsertRegistration(
                    $ctx->profileName, 'cellcast', 'number', $number, $name,
                    approvedAt: $ctx->now,
                    rawRequest: json_encode($body),
                    createdAt: $ctx->now,
                );
                Util::json(200, ['status' => true, 'message' => 'Custom number created']);
            case PendingRegistration::ACTION_APPROVE_AFTER:
                $ctx->store->upsertRegistration(
                    $ctx->profileName, 'cellcast', 'number', $number, $name,
                    rawRequest: json_encode($body),
                    createdAt: $ctx->now,
                );
                Util::json(200, ['status' => true, 'message' => 'Custom number created']);
            case PendingRegistration::ACTION_PENDING_INDEFINITE:
                $ctx->store->upsertRegistration(
                    $ctx->profileName, 'cellcast', 'number', $number, $name,
                    forcedState: 'pending',
                    rawRequest: json_encode($body),
                    createdAt: $ctx->now,
                );
                Util::json(200, ['status' => true, 'message' => 'Custom number created']);
            default: // ACTION_AUTO
                $ctx->store->upsertRegistration(
                    $ctx->profileName, 'cellcast', 'number', $number, $name,
                    rawRequest: json_encode($body),
                    createdAt: $ctx->now,
                );
                Util::json(200, ['status' => true, 'message' => 'Custom number created']);
        }
    }

    // ── Verify CustomNumber (OTP verification) ─────────────────────────

    private function handleVerifyCustomNumber(Ctx $ctx): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $number = $body['number'] ?? '';
        $otp = $body['otp'] ?? '';

        $row = $ctx->store->getRegistration('cellcast', $ctx->profileName, 'number', $number);
        if ($row === null) {
            Util::json(200, ['status' => false, 'message' => 'Number not found']);
        }

        if ($row['otp_code'] === null) {
            Util::json(200, ['status' => false, 'message' => 'No OTP pending for this number']);
        }

        if ($row['otp_code'] !== $otp) {
            Util::json(200, ['status' => false, 'message' => 'Invalid OTP']);
        }

        $ctx->store->markRegistrationOTPVerified((int) $row['id'], $ctx->now);

        // Auto-approve on successful OTP verification
        if ($row['approved_at'] === null && $row['forced_state'] === null) {
            $ctx->store->markRegistrationApproval((int) $row['id'], $ctx->now);
        }

        Util::json(200, ['status' => true, 'message' => 'Number verified successfully (test mode)']);
    }

    // ── Business Add (stub) ────────────────────────────────────────────

    private function handleBusinessAdd(Ctx $ctx): void
    {
        Util::json(200, ['status' => true, 'message' => 'Business added successfully']);
    }

    // ── Report Message ─────────────────────────────────────────────────

    private function handleReportMessage(Ctx $ctx, string $messageId): void
    {
        $row = $ctx->store->getMessageByRemoteId('cellcast', $messageId);
        if ($row === null) {
            Util::json(200, [
                'status' => false,
                'message' => 'message not found',
                'data' => [],
                'error' => ['message' => 'Cast to ObjectId failed'],
            ]);
        }

        $status = $ctx->state->deriveMessage($row, $ctx->now);
        $cellcastStatus = self::cellcastStatusString($status);
        $sendTime = (new \DateTimeImmutable($row['created_at']))->format(\DateTimeInterface::RFC3339);

        Util::json(200, [
            'data' => [
                '_id' => $messageId,
                'status' => $cellcastStatus,
                'send_time' => $sendTime,
                'updatedAt' => $sendTime,
            ],
        ]);
    }

    private function handleReportMessages(Ctx $ctx): void
    {
        $rows = $ctx->store->listMessagesForScope($ctx->profileName, 'cellcast');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, (int) ($_GET['limit'] ?? 10));

        $out = [];
        foreach ($rows as $row) {
            $status = $ctx->state->deriveMessage($row, $ctx->now);
            $cellcastStatus = self::cellcastStatusString($status);
            $scheduleAt = null;
            if ($row['scheduled_send_at'] !== null) {
                $scheduleAt = (new \DateTimeImmutable($row['scheduled_send_at']))->format(\DateTimeInterface::RFC3339);
            }
            $out[] = [
                '_id'       => $row['remote_id'],
                'status'    => $cellcastStatus,
                'message'   => $row['body'],
                'sender'    => $row['sender'],
                'receiver'  => $row['destination'],
                'createdAt' => (new \DateTimeImmutable($row['created_at']))->format(\DateTimeInterface::RFC3339),
                'scheduleAt' => $scheduleAt,
            ];
        }

        $total = count($out);
        $totalPages = max(1, (int) ceil($total / $limit));

        Util::json(200, [
            'status'      => true,
            'message'     => 'Success',
            'data'        => $out,
            'total'       => $total,
            'limit'       => $limit,
            'current'     => $page,
            'totalPages'  => $totalPages,
            'hasPrevPage' => $page > 1,
            'hasNextPage' => $page < $totalPages,
            'prevPage'    => $page > 1 ? $page - 1 : null,
            'nextPage'    => $page < $totalPages ? $page + 1 : null,
        ]);
    }

    private static function cellcastStatusString(string $status): string
    {
        return match ($status) {
            State::MSG_SCHEDULED => 'scheduled',
            State::MSG_SENT      => 'sent',
            State::MSG_DELIVERED => 'delivered',
            State::MSG_FAILED    => 'failed',
            State::MSG_CANCELLED => 'cancelled',
            State::MSG_UNKNOWN   => 'unknown',
            default              => $status,
        };
    }
}
