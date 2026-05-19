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
 *    cheap check ({@see isConfigured()}) that does not touch external services.
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
     * The 'success' field is about operational health, not config existence.
     * Subclasses should use a message like "Connected" / "Not available" rather
     * than "Configured" / "Not configured" to avoid redundancy with the Configured line.
     *
     * @return array{success: bool, message: string, details?: array<string, string>}
     */
    abstract protected function getStatus(): array;

    public function run(): void
    {
        if (!$GLOBALS['user_system']->havePerm(PERM_SYSADMIN)) {
            return;
        }

        ?>
        <p class="status-panel-help"><?php echo $this->getHelpText(); ?></p>
        <?php

        $feature = $this->linkedFeature();
        if ($feature !== null) {
            $enabled = $GLOBALS['system']->featureEnabled($feature);
            $icon = $enabled
                ? '<span style="color:#468847">&#10003;</span>'
                : '<span style="color:#b94a48">&#10007;</span>';
            $label = $enabled ? 'Enabled' : 'Disabled';
            ?>
            <p><?php echo $icon; ?> <?php echo $label; ?>
                <a href="<?php echo baseurl_relative(); ?>/?view=admin__system_configuration#ENABLED_FEATURES"><i>(link)</i></a>
            </p>
            <?php
        }

        $configuredResult = $this->isConfigured();
        $configured = $configuredResult->isSuccess();
        $icon = $configured
                ? '<span style="color:#468847">&#10003;</span> Configured'
                : '<span style="color:#b94a48">&#10007;</span> Not configured';
        ?>
        <p><?php echo $icon; ?> <?php
        if (!$configured) {
            echo ' &mdash; ' . ents($configuredResult->getError());
        }
        ?></p>
        <?php

        if ($configured) {
            $status = $this->getStatus();
            $icon = $status['success']
                    ? '<span style="color:#468847">&#10003;</span>'
                    : '<span style="color:#b94a48">&#10007;</span>';
            ?>
            <p>
            <?php
            echo $icon.' ';
            echo ents($status['message']);
            if (!empty($status['details'])) {
                $id = 'status-panel-details-' . strtolower(substr(static::class, strlen('Call_Admin_Statuspanel_')));
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
        }
    }
}
