<?php

require_once __DIR__ . '/../include/general.php';

/**
 * Abstract base for admin status panel calls.
 *
 * Displays two or three lines for each feature on the system configuration page:
 *
 * 1. Enabled/Disabled (if {@see linkedFeature()} returns a feature flag name) —
 *    is the feature toggled on? Links to the ENABLED_FEATURES section.
 *
 * 2. Configured — are the required credentials/constants in place? This is a
 *    cheap check ({@see self::isConfigured()}) that does not touch external services.
 *
 * 3. (If configured) Status message with optional collapsible details — is the feature actually
 *    working right now? This is a live operational check ({@see getStatus()})
 *    that may contact external providers, fetch balances, test API calls, etc.
 *    The 'success' field is about operational health, not config existence.
 */
abstract class Call_Admin_Statuspanel extends Call
{
    /**
     * Feature flag name for the Enabled/Disabled line.
     *
     * @return string|null null means "not applicable" (no Enabled line shown);
     *                     override to return a feature flag name e.g. 'SMS'.
     */
    public function linkedFeature(): ?string
    {
        return null;
    }

    /**
     * Whether the required credentials/constants are in place.
     *
     * This is a cheap check — do not contact external services here.
     *
     * @return \Result<bool, string> bool result, plus (if not configured) a HTML explanation as to why not (e.g. missing setting).)
     */
    abstract protected function isConfigured(): \Result;

    /** Help text (HTML) displayed above the status. */
    abstract protected function getHelpText(): string;

    /**
     * Live operational check — is the feature actually working right now?
     *
     * The 'success' field is about operational health, not config existence. E.g. a SMS provider might be configured correctly (isConfigured succeeds), but its API might be offline (getStatus returns false).
     * Subclasses should use a message like "Connected" / "Not available" rather
     * than "Configured" / "Not configured" to avoid redundancy with the Configured line.
     * 'message' is an overall summary. 'details' is key:value pairs displayed in a table.
     *
     * @return array{success: bool, message: string, details?: array<string, string>}
     */
    abstract protected function getStatus(): array;

    /**
     * Operations the admin can initiate from this status panel.
     *
     * Each entry maps an operation name to a human-readable button label.
     * When non-empty, the status panel renders a row of buttons; clicking a
     * button fires a Datastar @get to the operation handler which returns the
     * form HTML; submitting the form triggers a Datastar @post to the same
     * handler which returns the result HTML — both morphed into the container
     * by ID.
     *
     * The operation handler URL is derived from the status panel class name:
     *
     *   Call_Admin_Statuspanel_Sms →
     *   ?call=admin_statuspanel_operation_sms
     *
     * @return array<string, string>  operation name => label
     */
    protected function getOperations(): array
    {
        return [];
    }

    public function run(): void
    {
        if (!$GLOBALS['user_system']->havePerm(PERM_SYSADMIN)) {
            return;
        }

        $suffix = strtolower(substr(static::class, strlen('Call_Admin_Statuspanel_')));
        ?>
        <div id="status-panel-<?php echo ents($suffix); ?>" class="status-panel">
        <p class="status-panel-help"><?php echo $this->getHelpText(); ?></p>
        <?php

        $feature = $this->linkedFeature();
        if ($feature !== null) {
            $enabled = $GLOBALS['system']->featureEnabled($feature);
            ?>
            <p><?php echo $this->statusIcon($enabled); ?> <?php echo $enabled ? 'Enabled' : 'Disabled'; ?>
                <a href="<?php echo baseurl_relative(); ?>/?view=admin__system_configuration#ENABLED_FEATURES"><i>(link)</i></a>
            </p>
            <?php
        }

        $configuredResult = $this->isConfigured();
        $configured = $configuredResult->isSuccess();
        ?>
        <p><?php echo $this->statusIcon($configured); ?>
        <?php echo $configured ? 'Configured' : 'Not configured'; ?>
        <?php
        if (!$configured) {
            $error = $configuredResult->getError();
            echo ' &mdash; ' . (is_array($error) ? $error['message'] : $error);
            if (is_array($error) && !empty($error['details'])) {
                $id = 'config-panel-details-' . $suffix;
                ?>
                <a href="#" data-toggle="collapse" data-target="#<?php echo $id; ?>" onclick="return false"><i>(details)</i></a>
                <div id="<?php echo $id; ?>" class="collapse status-panel-details">
                    <?php echo $error['details']; ?>
                </div>
                <?php
            }
        } else {
            $status = $this->getStatus();
            ?>
            <p>
            <?php
            echo $this->statusIcon($status['success']) . ' ';
            echo ents($status['message']);
            if (!empty($status['details'])) {
                $id = 'status-panel-details-' . $suffix;
                ?>
                <a href="#" data-toggle="collapse" data-target="#<?php echo $id; ?>" onclick="return false"><i>(details)</i></a>
                <div id="<?php echo $id; ?>" class="collapse status-panel-details form-horizontal">
                    <?php foreach ($status['details'] as $label => $value): ?>
                        <div class="control-group">
                            <label class="control-label"><?php echo ents($label); ?></label>
                            <div class="controls"><?php echo $value; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php } ?>
            </p>
            <?php

            // Operation buttons
            $operations = $this->getOperations();
            if ($operations !== []) {
                $opCall = 'admin_statuspanel_operation_' . $suffix;
                $opId = 'status_panel-ops-' . $suffix;
                ?>
                <div class="status_panel-operations" id="<?php echo ents($opId); ?>">
                    <?php foreach ($operations as $method => $label): ?>
                        <a href="javascript:void()"
                            class="status_panel-op-btn"
                            data-on:click="@get('?call=<?php echo ents($opCall); ?>&operation=<?php echo ents($method); ?>')">
                            <i class="icon-plus-sign"></i><?php echo ents($label); ?>
                        </a>
                    <?php endforeach; ?>
                    <div class="status_panel-op-container" id="<?php echo ents($opId); ?>-container"></div>
                </div>
                <?php
            }
        }
        ?>
        </div>
        <?php
    }

    /**
     * Green check or red cross icon for use in status lines.
     *
     * @param bool $ok true → ✓ (green), false → ✗ (red)
     */
    private function statusIcon(bool $ok): string
    {
        return $ok
            ? '<span style="color:#468847">✓</span>'
            : '<span style="color:#b94a48">✗</span>';
    }
}
