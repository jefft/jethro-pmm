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

    public static function routes(): array
    {
        return [
            'POST /api/v1/gateway',
            'POST /api/v1/gateway/cancelScheduleQuickMessage',
            'GET /api/v1/apiClient/account',
            'GET /api/v1/apiClient/getOptout',
            'GET /api/v1/customNumber',
            'POST /api/v1/customNumber/add',
            'POST /api/v1/customNumber/verifyCustomNumber',
            'POST /api/v1/business/add',
            'GET /api/v1/business',
            'GET /api/v2/report/message',
            'GET /api/v2/report/message/{id}',
        ];
    }

    public function handle(Ctx $ctx): void
    {
        \SmsMockServer\Util::jsonHeader();
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
            case $method === 'GET' && $path === '/api/v1/business':
                $this->handleGetBusiness($ctx);
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
                        body: $ctx->rawBody(),
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
        $body = $ctx->jsonBody();
        $message = $body['message'] ?? '';
        $contacts = $body['contacts'] ?? [];
        $sender = $body['sender'] ?? '';
        $scheduleAt = Util::parseScheduleAt($body['scheduleAt'] ?? null);

        // Recorded live: one validation error covers a missing message or bad contacts.
        if (trim((string) $message) === '' || !\is_array($contacts) || $contacts === []) {
            Util::json(400, [
                'code' => 400,
                'message' => '"message" is required and Contacts must either start with 61 or +61 and be exactly 11 digits long (e.g., 614xxxxxxxx or +614xxxxxxxx), or be 9 digits without the area code (e.g., 4xxxxxxxx)',
                'stack' => 'APIError: validation failed',
            ]);
        }

        $queueResponse = [];
        foreach ($contacts as $dest) {
            // Live IDs are 24 hex characters.
            $remoteId = Util::randomHex(12);
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

            $queueResponse[] = [
                // Recorded live: Number as sent, Contact in national form.
                'Contact' => self::national((string) $dest),
                'MessageId' => $remoteId,
                'Number' => (string) $dest,
                // The provider reads jobInfo.data.messageData.status to decide
                // SCHEDULED (queued/pending) vs SENT.
                'jobInfo' => ['data' => ['messageData' => [
                    'status' => $scheduleAt !== null ? 'queued' : 'sent',
                ]]],
            ];
        }

        Util::json(200, self::appEnvelope('Request is being processed', [
            'queueResponse' => $queueResponse,
            'message' => 'success register all valid contacts to queue',
            'invalidContacts' => [],
            'unsubscribeContacts' => [],
            'totalValidContact' => \count($queueResponse),
            'totalInvalidContact' => 0,
            'totalUnsubscribeContact' => 0,
        ]));
    }

    // ── Cancel ─────────────────────────────────────────────────────────

    private function handleCancel(Ctx $ctx): void
    {
        $body = $ctx->jsonBody();
        $messageId = $body['messageId'] ?? '';

        $row = $ctx->store->getMessageByRemoteId('cellcast', $messageId);
        // Recorded live: unknown or already-cancelled ids are a 404 status:false.
        if ($row === null || $ctx->state->deriveMessage($row, $ctx->now) === State::MSG_CANCELLED) {
            Util::json(404, self::appEnvelope('message not found', [], status: false, error: ['error' => 'message not found']));
        }

        if ($ctx->profileHooks?->onCancelHook !== null) {
            ($ctx->profileHooks->onCancelHook)($row);
        }

        $ctx->store->markMessageCancelled((int) $row['id'], $ctx->now);
        Util::json(200, self::appEnvelope('Job removed successfully', ['message' => 'Job removed successfully']));
    }

    // ── Account / Balance ──────────────────────────────────────────────

    private function handleAccount(Ctx $ctx): void
    {
        // Recorded live: a fractional sms_balance, and a meta envelope.
        $balance = $ctx->profileHooks?->balance ?? 12345;
        Util::json(200, self::metaEnvelope("Here's your account", [
            'account_name' => 'Mock Account',
            'account_email' => 'mock@example.com',
            'sms_balance' => $balance,
            'mms_balance' => 20,
        ]));
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
            Util::json(200, self::appEnvelope('Custom number listed succesfully', array_map(self::customNumberItem(...), $cp->senders)));
        }

        // Dynamic mode: defaults + the profile's pre-registered numbers + approved registrations
        $defaults = [
            ['number' => '614915701588', 'name' => 'Church Main'],
            ['number' => '61402000002', 'name' => 'Youth Group'],
            ...($cp?->preRegistered ?? []),
        ];

        $out = [];
        $seen = [];
        foreach ($defaults as $d) {
            $seen[$d['number']] = true;
            $out[] = $d;
        }

        $rows = $ctx->store->listRegistrationsForScope($ctx->profileName, 'cellcast');
        foreach ($rows as $row) {
            if ($row['kind'] !== 'number') continue;	// business names are listed by GET /api/v1/business
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

        Util::json(200, self::appEnvelope('Custom number listed succesfully', array_map(self::customNumberItem(...), $out)));
    }

    // ── Add CustomNumber ───────────────────────────────────────────────

    private function handleAddCustomNumber(Ctx $ctx): void
    {
        $body = $ctx->jsonBody();
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
        $body = $ctx->jsonBody();
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

    // ── Business names (Alpha Sender IDs) ──────────────────────────────

    /** Store kind for business-name registrations (cf. 'number' for custom numbers). */
    private const KIND_BUSINESS = 'businessname';

    /**
     * POST /api/v1/business/add — records a registration that then appears in
     * GET /api/v1/business: REQUESTED while pending (the profile's approval
     * delay, or forced pending), ACTIVE once approved. A name the account
     * already has is refused, as real Cellcast does.
     */
    private function handleBusinessAdd(Ctx $ctx): void
    {
        $body = $ctx->jsonBody();
        $name = trim((string) ($body['businessname'] ?? ''));
        if ($name === '') {
            Util::json(200, ['status' => false, 'message' => 'Business Name is required!']);
        }

        $known = array_column($this->businessNames($ctx), 'businessname');
        if (\in_array($name, $known, true)) {
            Util::json(200, ['status' => false, 'message' => 'businessname is already registered']);
        }

        $pending = new PendingRegistration();
        $pending->provider = 'cellcast';
        $pending->kind = self::KIND_BUSINESS;
        $pending->value = $name;
        $pending->displayName = $name;
        $pending->rawBody = json_encode($body);

        if ($ctx->profileHooks?->onRegisterHook !== null) {
            ($ctx->profileHooks->onRegisterHook)($pending);
        }

        $record = fn(array $state) => $ctx->store->upsertRegistration(
            $ctx->profileName, 'cellcast', self::KIND_BUSINESS, $name, $name,
            ...$state + ['rawRequest' => json_encode($body), 'createdAt' => $ctx->now],
        );
        switch ($pending->action()) {
            case PendingRegistration::ACTION_REJECT:
                Util::json(200, ['status' => false, 'message' => $pending->rejectReason()]);
            case PendingRegistration::ACTION_APPROVE:
                $record(['approvedAt' => $ctx->now]);
                break;
            case PendingRegistration::ACTION_PENDING_INDEFINITE:
                $record(['forcedState' => State::APPROVAL_PENDING]);
                break;
            default: // ACTION_AUTO, ACTION_APPROVE_AFTER: approval delay applies
                $record([]);
        }
        Util::json(200, [
            'status' => true,
            'message' => 'Request added successfully for business name!',
            'data' => ['businessname' => $name, 'status' => 'REQUESTED', 'isDeleted' => false],
            'error' => [],
        ]);
    }

    /**
     * GET /api/v1/business — undocumented by Cellcast; shape per
     * jethro-sms/docs/reference/_cellcast/business/GET.md (trimmed to the
     * fields Jethro reads).
     */
    private function handleGetBusiness(Ctx $ctx): void
    {
        Util::json(200, self::appEnvelope('Business listed succesfully', array_map(self::businessItem(...), $this->businessNames($ctx))));
    }

    /**
     * The account's business names: the profile's list (default StJohnsWPH)
     * plus names registered via POST /api/v1/business/add, with Cellcast
     * statuses derived from the registration's approval state.
     *
     * @return list<array{businessname:string,status:string,acmaStatus:string,isDeleted:bool}>
     */
    private function businessNames(Ctx $ctx): array
    {
        $out = CellcastProfile::get($ctx->profileName)?->businessNames
            ?? [['businessname' => 'StJohnsWPH', 'status' => 'ACTIVE', 'acmaStatus' => 'ACMA_APPROVED', 'isDeleted' => false]];
        $seen = array_flip(array_column($out, 'businessname'));

        foreach ($ctx->store->listRegistrationsForScope($ctx->profileName, 'cellcast') as $row) {
            if ($row['kind'] !== self::KIND_BUSINESS || isset($seen[$row['value']])) continue;
            $seen[$row['value']] = true;
            [$status, $acma] = match ($ctx->state->deriveApproval($row, $ctx->now)) {
                State::APPROVAL_APPROVED => ['ACTIVE', 'ACMA_APPROVED'],
                State::APPROVAL_REJECTED => ['REJECTED', 'ACMA_REJECTED'],	// not observed on the real API
                default                  => ['REQUESTED', 'PENDING_CELLCAST_REVIEW'],
            };
            $out[] = ['businessname' => $row['value'], 'status' => $status, 'acmaStatus' => $acma, 'isDeleted' => false];
        }
        return $out;
    }

    // ── Report Message ─────────────────────────────────────────────────

    /**
     * GET /api/v2/report/message/{id}. Real shape (probed 2026-10): the
     * standard envelope around the message; an unknown id is HTTP 500 with
     * status false (Mongo's "Cast to ObjectId failed").
     */
    private function handleReportMessage(Ctx $ctx, string $messageId): void
    {
        $row = $ctx->store->getMessageByRemoteId('cellcast', $messageId);
        if ($row === null) {
            // Recorded live: an unknown id is a 404 status:false.
            Util::json(404, self::appEnvelope('Message not found', [], status: false, error: ['message' => 'Message not found']));
        }
        Util::json(200, self::appEnvelope('Success', self::lookupItem($ctx, $row)));
    }

    /**
     * GET /api/v2/report/message. Real shape (recorded 2026-10): the standard
     * envelope with a paginated `data: {items, total, limit, current,
     * totalPages, …}`.
     */
    private function handleReportMessages(Ctx $ctx): void
    {
        $rows = $ctx->store->listMessagesForScope($ctx->profileName, 'cellcast');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, (int) ($_GET['limit'] ?? 10));

        $total = count($rows);
        $totalPages = max(1, (int) ceil($total / $limit));
        $items = array_map(fn(array $row) => self::listItem($ctx, $row), array_slice($rows, ($page - 1) * $limit, $limit));

        Util::json(200, self::appEnvelope('Success', [
            'items'         => $items,
            'total'         => $total,
            'limit'         => $limit,
            'current'       => $page,
            'totalPages'    => $totalPages,
            'pagingCounter' => ($page - 1) * $limit + 1,
            'hasPrevPage'   => $page > 1,
            'hasNextPage'   => $page < $totalPages,
            'prevPage'      => $page > 1 ? $page - 1 : null,
            'nextPage'      => $page < $totalPages ? $page + 1 : null,
        ]));
    }

    /** One message as GET /api/v2/report/message/{id} returns it (recorded
     *  2026-10): data.send_time and data.updatedAt, plus scheduleAt. */
    private static function lookupItem(Ctx $ctx, array $row): array
    {
        $status = $ctx->state->deriveMessage($row, $ctx->now);
        $sendTime = self::isoMs($row['scheduled_send_at'] ?? $row['created_at']);
        return [
            '_id' => $row['remote_id'],
            'createdAt' => self::isoMs($row['created_at']),
            'creditAmount' => 1.3,
            'message' => $row['body'],
            'receiver' => self::national($row['destination']),
            'replyMessage' => null,
            'replyStop' => false,
            'scheduleAt' => $row['scheduled_send_at'] !== null ? $sendTime : null,
            'send_time' => $sendTime,
            'sender' => $row['sender'],
            'smsDetail' => [
                'deductibleCredit' => 1.3,
                'messagelength' => \strlen($row['body']),
                'smscount' => 1,
            ],
            'status' => self::cellcastStatusString($status),
            'updatedAt' => $sendTime,
        ];
    }

    /** One message as GET /api/v2/report/message lists it (recorded 2026-10). */
    private static function listItem(Ctx $ctx, array $row): array
    {
        $status = $ctx->state->deriveMessage($row, $ctx->now);
        return [
            '_id' => $row['remote_id'],
            'campaign_type' => 'notification',
            'createdAt' => self::isoMs($row['created_at']),
            'creditAmount' => 1.3,
            'gateway_count' => 1,
            'message' => $row['body'],
            'receiver' => self::national($row['destination']),
            'replyMessage' => null,
            'replyStop' => false,
            'request_from' => 'API',
            'request_name' => 'mock',
            'scheduleAt' => $row['scheduled_send_at'] !== null ? self::isoMs($row['scheduled_send_at']) : null,
            'sender' => $row['sender'],
            'smsDetail' => [
                '_id' => substr(md5($row['remote_id']), 0, 24),
                'costpersms' => 0.047,
                'deductibleCredit' => 1.3,
                'messagelength' => \strlen($row['body']),
                'smscount' => 1,
            ],
            'status' => self::cellcastStatusString($status),
        ];
    }

    /**
     * A business name as GET /api/v1/business lists it. Recorded live
     * 2026-10: the full registration document (ACMA fields, company and
     * contact details, status history).
     *
     * @param array{businessname:string,status:string,acmaStatus:string,isDeleted:bool} $b
     * @return array<string, mixed>
     */
    private static function businessItem(array $b): array
    {
        $at = '2026-01-01T00:00:00.000Z';
        $id = substr(md5('business:' . $b['businessname']), 0, 24);
        $user = substr(md5('business-user'), 0, 24);
        return [
            '__v' => 0,
            '_id' => $id,
            'acmaApprovalEmailSentAt' => $at,
            'acmaChatbotId' => '00000000-0000-4000-8000-000000000000',
            'acmaReason' => '',
            'acmaReminderEmailSentAt' => $at,
            'acmaStatus' => $b['acmaStatus'],
            'acmaSubmissionEmailSentAt' => $at,
            'allocatedUsers' => [],
            'approvedBy' => $user,
            'businessname' => $b['businessname'],
            'companyInformation' => [
                'abn' => '00000000000',
                'address' => '1 Mock Street',
                'name' => 'Mock Company',
                'website' => 'https://example.com/',
            ],
            'createdAt' => $at,
            'createdBy' => $user,
            'descriptionInternal' => 'Mock internal description',
            'isDefault' => false,
            'isDeleted' => $b['isDeleted'],
            'lastStatusWebhookAt' => $at,
            'ownership' => true,
            'purposeOfUse' => 'Service SMS',
            'registrationStatus' => 'ACTIVE',
            'registrationStatusLabel' => 'Active',
            'requestedAt' => $at,
            'requestorContact' => [
                'email' => 'mock@example.com',
                'firstName' => 'Mock',
                'lastName' => 'User',
                'phoneNumber' => '0400000001',
                'position' => 'Ministry Worker',
            ],
            'status' => $b['status'],
            'statusHistory' => [
                ['_id' => substr(md5('history-1'), 0, 24), 'changedAt' => $at, 'changedBy' => $user, 'note' => '', 'reason' => '', 'source' => 'admin', 'status' => 'PENDING_ACMA_APPROVAL'],
                ['_id' => substr(md5('history-2'), 0, 24), 'changedAt' => $at, 'changedBy' => $user, 'source' => 'customer', 'status' => 'PENDING_CELLCAST_REVIEW'],
                ['changedAt' => $at, 'reason' => '', 'source' => 'webhook', 'status' => 'ACMA_APPROVED'],
                ['changedAt' => $at, 'reason' => '', 'source' => 'webhook', 'status' => 'PENDING_ACMA_APPROVAL'],
                ['changedAt' => $at, 'reason' => 'Customer has not taken any actions', 'source' => 'webhook', 'status' => 'PENDING_ACMA_APPROVAL'],
                ['changedAt' => $at, 'reason' => 'Customer has verified Sender ID, but it was sent for review', 'source' => 'webhook', 'status' => 'PENDING_ACMA_APPROVAL'],
            ],
            'statusNotes' => '',
            'submittedToAcmaAt' => $at,
            'updatedAt' => $at,
            'user' => $user,
        ];
    }

    /** A custom (sender) number as GET /api/v1/customNumber lists it. Recorded
     *  live: a full Mongo document, not just the number and label.
     *  @param array{number:string,name:string} $n @return array<string, mixed> */
    private static function customNumberItem(array $n): array
    {
        $id = substr(md5($n['number']), 0, 24);
        return [
            '__v' => 0,
            '_id' => $id,
            'allocatedUsers' => [],
            'approvedAt' => '2026-01-01T00:00:00.000Z',
            'createdAt' => '2026-01-01T00:00:00.000Z',
            'createdBy' => $id,
            'deleted' => false,
            'isDefault' => false,
            'name' => $n['name'],
            'number' => $n['number'],
            'status' => 'active',
            'updatedAt' => '2026-01-01T00:00:00.000Z',
            'user' => $id,
        ];
    }

    /** The {meta, message, data, error} envelope of the account/opt-out endpoints. @return array<string, mixed> */
    private static function metaEnvelope(string $message, mixed $data): array
    {
        return ['meta' => ['code' => 200, 'status' => 'SUCCESS'], 'message' => $message, 'data' => $data, 'error' => []];
    }

    /**
     * The app envelope most Cellcast v1/v2 endpoints wrap their data in.
     * Recorded live 2026-10.
     *
     * @return array<string, mixed>
     */
    private static function appEnvelope(string $message, mixed $data, bool $status = true, array $error = []): array
    {
        return [
            'app_type' => 'web',
            'app_version' => '1.0',
            'maintainence' => 0,
            'new_version' => 0,
            'force_update' => 0,
            'invalid_token' => 0,
            'refresh_token' => '',
            'show_message' => 0,
            'is_enc' => false,
            'status' => $status,
            'message' => $message,
            'message_type' => 'toast',
            'data' => $data,
            'error' => $error,
        ];
    }

    /** National form Cellcast reports: 614xxxxxxxx or 04xxxxxxxx → 4xxxxxxxx. */
    private static function national(string $number): string
    {
        $digits = preg_replace('/^\+/', '', trim($number));
        $digits = preg_replace('/^61(?=4\d{8}$)/', '', $digits);
        return preg_replace('/^0(?=4\d{8}$)/', '', $digits);
    }

    /** A timestamp as the live API writes it (millisecond ISO). */
    private static function isoMs(string $timestamp): string
    {
        return (new \DateTimeImmutable($timestamp, new \DateTimeZone(date_default_timezone_get())))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /** The envelope most Cellcast v1/v2 endpoints wrap their data in. @return array<string, mixed> */
    private static function envelope(string $message, mixed $data): array
    {
        return ['status' => true, 'message_type' => 'toast', 'message' => $message, 'data' => $data, 'error' => []];
    }

    private static function cellcastStatusString(string $status): string
    {
        return match ($status) {
            State::MSG_SCHEDULED => 'scheduled',
            State::MSG_SENT      => 'sent',
            State::MSG_DELIVERED => 'delivered',
            State::MSG_FAILED    => 'failed',
            State::MSG_CANCELLED => 'canceled',	// one 'l', as Cellcast spells it (_cellcast/report/message/{id}/GET.md)
            State::MSG_UNKNOWN   => 'unknown',
            default              => $status,
        };
    }
}
