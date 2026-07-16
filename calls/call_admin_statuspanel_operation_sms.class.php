<?php

require_once __DIR__ . '/call_admin_statuspanel_operation.class.php';

/**
 * Operation handler for the SMS Gateway status panel.
 *
 * GET  responses return the morphable container div containing the operation
 *      form.  The submit button carries a Datastar @post attribute so no
 *      JavaScript form-submit handler is needed.
 *
 * POST responses return the morphable container div containing the result
 *      (next wizard step, success message, or error).  Datastar morphs both
 *      responses into the DOM by container ID.
 *
 * URL: ?call=admin_statuspanel_operation_sms[&operation=<name>]
 *
 * @see Call_Admin_Statuspanel_Sms::getOperations()
 */
class Call_Admin_Statuspanel_Operation_Sms extends Call_Admin_Statuspanel_Operation
{
    private function getSmsProvider(): \Sms\SmsProvider
    {
        require_once JETHRO_ROOT . '/include/jethro_sms.php';
        $result = \Jethro\Sms\getSmsProvider();
        if ($result->isFailure()) {
            throw new \RuntimeException($result->getError());
        }
        return $result->getValue();
    }

    /**
     * Dispatch to the requested SMS operation.
     *
     * Calls {@see parent::run()} first for the sysadmin permission check.
     * Each operation method handles both GET (form) and POST (process).
     * Responses are wrapped via {@see echoContainer()} for Datastar morph.
     */
    public function run(): void
    {
        if (!parent::run()) {
            return;
        }

        require_once JETHRO_ROOT . '/include/jethro_sms.php';

        match ($this->operation()) {
            'synchronizeHistory' => $this->doSynchronizeHistory(),
            'registerSenderId'   => $this->doRegisterSenderId(),
            default              => $this->echoContainer('<p class="text-error">Unknown operation.</p>'),
        };
    }

    /**
     * GET  — show the sync-history form.
     * POST — execute a history synchronisation.
     */
    private function doSynchronizeHistory(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->echoContainer($this->buildSyncHistoryForm());
            return;
        }

        $sinceRaw = $_POST['since'] ?? '';
        $since    = $sinceRaw !== '' ? strtotime($sinceRaw) : null;
        $since    = $since !== false ? $since : null;

        try {
            $result = \Jethro\Sms\synchronizeHistory($since);
            $html   = '<div class="alert alert-success">'
                . '<strong>Done.</strong> '
                . 'Imported ' . (int) $result['imported'] . ' deliveries '
                . 'in ' . (int) $result['batches'] . ' new batches'
                . ' (' . (int) $result['skipped'] . ' already existed).'
                . '</div>';
        } catch (\Throwable $e) {
            $html = '<div class="alert alert-error">'
                . '<strong>Error:</strong> ' . ents($e->getMessage())
                . '</div>';
        }

        $this->echoContainer($html);
    }

    /**
     * GET  — start or continue the sender-ID registration wizard.
     * POST — process a registration step submission.
     */
    private function doRegisterSenderId(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            try {
                $result = $this->getSmsProvider()->registerSenderId(null);
            } catch (\RuntimeException $e) {
                $this->echoContainer('<p class="text-error">' . ents($e->getMessage()) . '</p>');
                return;
            }

            if ($result->isFailure()) {
                $this->echoContainer('<p class="text-error">' . ents($result->getError()) . '</p>');
                return;
            }

            $schema = $result->getValue();
            if (!$schema instanceof \Sms\RegistrationStep || $schema->isComplete()) {
                $this->echoContainer('<p>No registration steps required.</p>');
                return;
            }

            $this->echoContainer(
                \Jethro\Sms\renderRegistrationStepHtml($schema, 'registerSenderId', '?call=' . $this->callName())
            );
            return;
        }

        // POST
        $params = $_POST;
        unset($params['operation']);

        try {
            $provider = $this->getSmsProvider();
        } catch (\RuntimeException $e) {
            $this->echoContainer('<p class="text-error">' . ents($e->getMessage()) . '</p>');
            return;
        }

        $senderIdStr = $params['senderid'] ?? null;
        $senderId    = $senderIdStr !== null ? new \Sms\SenderID($senderIdStr) : null;
        unset($params['senderid']);
        $result = $provider->registerSenderId($senderId, $params);

        if ($result->isFailure()) {
            $this->echoContainer('<p class="text-error">' . ents($result->getError()) . '</p>');
            return;
        }

        $step = $result->getValue();
        $this->echoContainer(
            \Jethro\Sms\renderRegistrationStepHtml($step, 'registerSenderId', '?call=' . $this->callName())
        );
    }

    /**
     * Build the sync-history form HTML string.
     *
     * Returns a <form> fragment for {@see echoContainer()} to wrap.
     * The submit button carries a Datastar @post attribute so no JS handler
     * is needed.
     */
    private function buildSyncHistoryForm(): string
    {
        $postUrl  = ents('?call=' . $this->callName());
        $dateVal  = date('Y-m-d\TH:i', time() - 86400);
        return <<<HTML
            <form onsubmit="return false" class="status_panel-op-form" data-operation="synchronizeHistory">
                <input type="hidden" name="operation" value="synchronizeHistory">
                <div class="alert alert-info">
                    <strong>Import SMS History:</strong> Fetches deliveries from the
                    upstream provider and imports any that don't already exist locally.
                    Safe to run multiple times.
                </div>
                <label for="sync-since">From (optional):</label>
                <input type="datetime-local" name="since" id="sync-since" step="86400"
                       value="{$dateVal}"
                       style="width: 250px; display: inline-block; margin: 0 6px;">
                <span class="help-inline">blank = last 24 hours</span>
                <br><br>
                <button type="button" class="btn btn-danger"
                        data-on:click="@post('{$postUrl}', {contentType: 'form'})">
                    <i class="icon-refresh icon-white"></i> Synchronize
                </button>
            </form>
            HTML;
    }

}
