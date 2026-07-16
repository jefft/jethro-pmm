<?php

/**
 * SSE endpoint backing the Datastar-driven SMS bulk/modal composer.
 *
 * On debounced textarea input (and recipient/sms_type changes), the client
 * POSTs the form here. This Call recomputes the SMS cost/segment/preview
 * business logic SERVER-SIDE (the single source of truth) and streams back:
 *   - the rendered #sms-statusline-bulk / #sms-statusline element,
 *   - the rendered #sms-preview-panel element, and
 *   - the $smsSendBlocked / $smsBlockReason signals that gate the Send button.
 *
 * Only the live character count stays client-side (a trivial Datastar
 * signal). All maths lives in jethro-sms/src/sms_statusline.php.
 *
 * The send / note-creation flow is unchanged — that still POSTs ?call=sms and
 * renders JSON (see calls/call_sms.class.php). This endpoint only powers the
 * live preview/statusline.
 *
 * See docs/docs/developer/reference/sms/SMS_DATASTAR.md for the full
 * architecture and SSE protocol.
 *
 * @see \Sms\renderStatusline()
 * @see \Sms\renderPreviewPanel()
 * @see \Jethro\sseStart()
 */
class Call_SMS_Statusline extends Call
{
    public function run(): void
    {
        // Enforce PERM_SENDSMS exactly like Call_SMS.
        if (!$GLOBALS['user_system']->havePerm(PERM_SENDSMS)) {
            http_response_code(403);
            return;
        }

        require_once JETHRO_ROOT . '/include/jethro_sms.php';
        require_once JETHRO_ROOT . '/include/sse.php';

        $message = array_get($_POST, 'message', '');

        // Per-recipient message overrides from the preview panel — same
        // parsing as the send/preview path in call_sms.class.php.
        $overrides = [];
        if (!empty($_POST['message_overrides']) && is_array($_POST['message_overrides'])) {
            foreach ($_POST['message_overrides'] as $pid => $msg) {
                if (is_string($msg) && $msg !== '') {
                    $overrides[(int)$pid] = $msg;
                }
            }
        }

        $smsReq = \Jethro\Sms\getRecipientsFromRequest();
        $cfg = \Jethro\Sms\makeStatuslineConfig();

        // Build per-recipient preview rows (token expansion + real recipient
        // set) via the existing preview path. Empty when there is no message
        // or no sendable recipients — renderStatusline then falls back to a
        // raw-text estimate.
        $deliveries = [];
        if (trim((string)$message) !== '' && $smsReq->recipients) {
            $sender = \Jethro\Sms\getSenderFromRequest();
            if ($sender !== null) {
                $res = \Jethro\Sms\sendSms($message, $smsReq->recipients, sender: $sender, preview: true);
                if ($res->isSuccess()) {
                    $deliveries = self::toPreviewRows($res->getValue(), $smsReq, (string)$message);
                }
            }
        }

        // Apply overrides into the rows used for the cost basis + panel: the
        // edited text is what will actually be sent to that recipient.
        if ($overrides !== []) {
            foreach ($deliveries as &$row) {
                $pid = $row['personId'] ?? null;
                if ($pid !== null && array_key_exists($pid, $overrides)) {
                    $row['message'] = $overrides[$pid];
                }
            }
            unset($row);
        }

        $actualCount = count($smsReq->recipients ?? []);
        $status = \Sms\renderStatusline((string)$message, $deliveries, $cfg, $actualCount);
        $panel = \Sms\renderPreviewPanel($deliveries, $overrides);

        // Schedule time validation: if send_at exceeds the provider's max
        // deferred send delay, block the Send button with a reason.
        $sendAtRaw = !empty($_POST['send_at']) ? $_POST['send_at'] : null;
        if ($sendAtRaw !== null) {
            $sendAt = strtotime($sendAtRaw);
            if ($sendAt !== false && $sendAt > 0) {
                $providerResult = \Jethro\Sms\getSmsProvider();
                if ($providerResult->isSuccess()) {
                    $maxDelay = $providerResult->getValue()->getDeferredSendMaxDelay();
                    if ($maxDelay !== null && ($sendAt - time()) > $maxDelay) {
                        $status['blocked'] = true;
                        $status['blockReason'] = sprintf(
                            'Cannot schedule more than %s ahead',
                            \_formatDuration($maxDelay)
                        );
                    }
                }
            }
        }

        // The morph target ids depend on which form posted. The bulk form
        // uses the '-bulk' suffix; the modal uses none. The client tells us
        // via the 'statusline_id' / 'preview_id' fields (defaulting to bulk).
        $statuslineId = isset($_POST['statusline_id']) && is_string($_POST['statusline_id'])
            ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['statusline_id'])
            : 'sms-statusline-bulk';
        $previewId = isset($_POST['preview_id']) && is_string($_POST['preview_id'])
            ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['preview_id'])
            : 'sms-preview-panel';

        \Jethro\sseStart();
        \Jethro\ssePatchElements(
            '<span id="' . $statuslineId . '" class="sms-statusline-cost">' . $status['html'] . '</span>'
        );
        \Jethro\ssePatchElements(
            '<div id="' . $previewId . '" class="sms-preview-panel"' . ($panel === '' ? ' style="display:none"' : '') . '>' . $panel . '</div>'
        );
        \Jethro\ssePatchSignals([
            'smsSendBlocked' => $status['blocked'],
            'smsBlockReason' => $status['blockReason'],
        ]);
    }

    /**
     * Convert a preview SmsDeliveryBatch into statusline/preview-panel rows.
     * Mirrors the preview-row construction in calls/call_sms.class.php.
     *
     * @return array<int, array{personId: ?int, name: string, message: string, status: int}>
     */
    private static function toPreviewRows(
        \Sms\SmsDeliveryBatch $batch,
        \Jethro\Sms\SmsRequestRecipients $smsReq,
        string $message,
    ): array {
        $rawRecips = $smsReq->rawPersonRecords;
        $rows = [];
        /** @var \Jethro\Sms\JethroSmsDelivery[] $deliveries */
        $deliveries = $batch->deliveries;
        foreach ($deliveries as $d) {
            $pid = method_exists($d, 'recipientPersonId') ? $d->recipientPersonId() : null;
            $person = $pid !== null ? ($rawRecips[$pid] ?? null) : null;
            $rows[] = [
                'personId' => $pid,
                'name' => $person
                    ? trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''))
                    : $d->recipient()->value,
                'message' => $d->message() ?? $message,
                'status' => $d->status()->value,
            ];
        }
        return $rows;
    }
}
