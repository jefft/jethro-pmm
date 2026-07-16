<?php

/**
 * Admin operation: import SMS history from upstream provider.
 *
 * GET  ?call=admin_sms_sync_history           — confirmation form
 * POST ?call=admin_sms_sync_history&since=... — execute import
 *
 * Requires PERM_SYSADMIN.
 */
class Call_Admin_Sms_Sync_History extends Call
{
    function run(): void
    {
        if (!$GLOBALS['user_system']->havePerm(PERM_SYSADMIN)) {
            return;
        }

        require_once JETHRO_ROOT . '/include/jethro_sms.php';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePost();
        } else {
            $this->handleGet();
        }
    }

    private function handleGet(): void
    {
        ?>
        <div class="alert alert-info">
            <strong>Import SMS History:</strong> Fetches deliveries from the
            upstream provider and imports any that don't already exist locally.
            Safe to run multiple times — already-imported batches are skipped.
        </div>
        <form method="post" action="<?php echo ents(build_url(['call' => 'admin_sms_sync_history'])); ?>" class="form-horizontal">
            <div class="control-group">
                <label class="control-label" for="sync-since">From (optional)</label>
                <div class="controls">
                    <input type="datetime-local" name="since" id="sync-since" step="86400"
                           value="<?php echo date('Y-m-d\TH:i', time() - 86400); ?>"
                           style="width: 250px;">
                    <span class="help-block">Leave blank for last 24 hours.</span>
                </div>
            </div>
            <div class="control-group">
                <div class="controls">
                    <button type="submit" class="btn btn-danger">
                        <i class="icon-refresh icon-white"></i> Synchronize History
                    </button>
                </div>
            </div>
        </form>
        <?php
    }

    private function handlePost(): void
    {
        $sinceRaw = $_POST['since'] ?? '';
        $since = $sinceRaw !== '' ? strtotime($sinceRaw) : null;
        $since = $since !== false ? $since : null;

        try {
            $result = \Jethro\Sms\synchronizeHistory($since);
            ?>
            <div class="alert alert-success">
                <strong>Done.</strong>
                Imported <?php echo (int)$result['imported']; ?> deliveries
                in <?php echo (int)$result['batches']; ?> new batches
                (<?php echo (int)$result['skipped']; ?> already existed).
            </div>
            <?php
        } catch (\Throwable $e) {
            ?>
            <div class="alert alert-error">
                <strong>Error:</strong> <?php echo ents($e->getMessage()); ?>
            </div>
            <?php
        }
    }
}
