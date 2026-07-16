<?php

require_once __DIR__ . '/call_admin_statuspanel_operation.class.php';

/**
 * Operation handler for the SMS URL Shortener status panel.
 *
 * GET  responses return the morphable container div containing the operation
 *      form.  The submit button carries a Datastar @post attribute so no
 *      JavaScript form-submit handler is needed.
 *
 * POST responses return the morphable container div containing the result
 *      (success message, or error).  Datastar morphs both responses into the
 *      DOM by container ID.
 *
 * URL: ?call=admin_statuspanel_operation_urlshortener_api_key[&operation=<name>]
 *
 * Registration flow (see jethro-url-shortener PHP_INTEGRATION.md):
 *   1. GET register → show a form with a "Sign in with Google" link to
 *      https://jethro.au/signup?redirect=<callback-url>
 *   2. After OAuth, the worker redirects back to the callback URL with
 *      ?sh_apikey=<token>&sh_email=<email>[&sh_replaced=1]
 *   3. This handler detects sh_apikey, writes URLSHORTENER_API_KEY to
 *      the setting table, and shows a success message.
 *
 * @see Call_Admin_Statuspanel_Urlshortener_Api_Key::getOperations()
 */
class Call_Admin_Statuspanel_Operation_Urlshortener_Api_Key extends Call_Admin_Statuspanel_Operation
{
    /** Base URL of the jethro.au shortener. */
    private const JETHRO_SIGNUP = 'https://jethro.au/signup';

    // ------------------------------------------------------------------
    // Route
    // ------------------------------------------------------------------

    /**
     * Dispatch to the requested URL shortener operation.
     *
     * Calls {@see parent::run()} first for the sysadmin permission check.
     * Each operation method handles both GET (form) and POST (process).
     */
    public function run(): void
    {
        if (!parent::run()) {
            return;
        }

        match ($this->operation()) {
            'register'   => $this->doRegister(),
            'manual'     => $this->doManual(),
            'deregister' => $this->doDeregister(),
            default      => $this->echoContainer('<p class="text-error">Unknown operation.</p>'),
        };
    }

    /**
     * GET — show the signup link, or handle the OAuth callback if returning
     *       from jethro.au with ?sh_apikey=… in the query string.
     */
    private function doRegister(): void
    {
        // If this is a callback from the OAuth signup flow, save the key
        // before rendering anything.
        if (isset($_GET['sh_apikey'])) {
            $this->handleSignupCallback();
            return;
        }

        $this->echoContainer($this->buildRegisterForm());
    }

    /**
     * GET  — show the manual key-entry form.
     * POST — validate and save the submitted API key.
     */
    private function doManual(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->echoContainer($this->buildManualForm());
            return;
        }

        $token = $this->persistApiKey(
            $_POST['api_key'] ?? '',
            '<strong>Invalid key.</strong> The API key must start with <code>cus_</code>.'
        );
        if ($token === null) {
            return;
        }

        $settingsUrl = $this->getSettingsUrl();

        $this->echoContainer(
            '<div class="alert alert-success">'
            . '<strong>API key saved.</strong> '
            . 'URL shortening is now enabled. '
            . '<a href="' . ents($settingsUrl) . '">Return to system settings</a>.'
            . '</div>'
            . '<meta http-equiv="refresh" content="2;url=' . ents($settingsUrl) . '">'
        );
    }

    // ------------------------------------------------------------------
    // OAuth signup callback
    // ------------------------------------------------------------------

    /**
     * Process the OAuth callback from jethro.au (sh_apikey in GET params).
     *
     * Validates the token, saves it, and echoes a success or error container.
     */
    private function handleSignupCallback(): void
    {
        $email = $_GET['sh_email'] ?? 'unknown';

        $token = $this->persistApiKey(
            $_GET['sh_apikey'],
            '<strong>Invalid API key received.</strong> The signup callback did not contain a valid token. '
            . '<a href="?call=' . ents($this->callName()) . '&amp;operation=register">Try again</a>.'
        );
        if ($token === null) {
            return;
        }

        $settingsUrl = $this->getSettingsUrl();
        $masked      = substr($token, 0, 8) . '…';

        $this->echoContainer(
            '<div class="alert alert-success">'
            . '<strong>API key registered for ' . ents($email) . '.</strong> '
            . 'The key <code>' . ents($masked) . '</code> '
            . 'has been saved to your configuration.'
            . '</div>'
            . '<p><a href="' . ents($settingsUrl) . '">'
            . 'Return to system settings</a></p>'
            . '<meta http-equiv="refresh" content="3;url=' . ents($settingsUrl) . '">'
        );
    }

    // ------------------------------------------------------------------
    // Forms
    // ------------------------------------------------------------------

    /**
     * Build the registration form HTML string.
     *
     * Shows a signup link to https://jethro.au/signup with the callback
     * URL pointing back to this handler, so the OAuth flow returns here.
     */
    private function buildRegisterForm(): string
    {
        $callbackUrl = $this->getCallbackUrl();
        $signupUrl   = self::JETHRO_SIGNUP . '?redirect=' . urlencode($callbackUrl);
        $campaign    = defined('SYSTEM_NAME') ? (string) SYSTEM_NAME : '';
        if ($campaign !== '') {
            $signupUrl .= '&campaign=' . urlencode($campaign);
        }

        return <<<HTML
            <div class="alert alert-info">
                <strong>Register with jethro.au:</strong>
                Sign in with Google or GitHub to obtain an API key
                for the jethro.au URL shortener.
                The key will be saved to your configuration automatically.
            </div>
            <p>
                <a class="btn" href="{$signupUrl}" target="_blank"
                   rel="noopener noreferrer">
                   URL Shortener Signup
                </a>
            </p>
            <p class="smallprint">
                After signing in you will be redirected back to this page
                and the API key will be saved automatically.
                If you already have a key,
                <a href="?call={$this->callName()}&amp;operation=manual">enter it manually</a>.
            </p>
            HTML;
    }

    /**
     * Build the manual key-entry form.
     */
    private function buildManualForm(): string
    {
        $callName = ents($this->callName());

        return <<<HTML
            <form method="post"
                  action="?call={$callName}"
                  data-datastar="post">
                <input type="hidden" name="operation" value="manual">
                <div class="control-group">
                    <label class="control-label" for="sh_apikey_manual">
                        jethro.au API key
                    </label>
                    <div class="controls">
                        <input type="text" name="api_key" id="sh_apikey_manual"
                               placeholder="cus_…"
                               pattern="cus_[a-f0-9]{32}"
                               required
                               style="width: 100%; max-width: 30em;">
                        <span class="help-inline">
                            Starts with <code>cus_</code> followed by 32 hex digits.
                        </span>
                    </div>
                </div>
                <div class="control-group">
                    <div class="controls">
                        <button type="submit" class="btn btn-primary">
                            Save API key
                        </button>
                    </div>
                </div>
            </form>
            HTML;
    }

    // ------------------------------------------------------------------
    // Database persistence
    // ------------------------------------------------------------------

    /**
     * Validate the token format and persist it.
     *
     * On failure, echoes the error container (using {@see $invalidFormatError}
     * for format errors, or a fixed message for persistence errors) and returns
     * null.  On success, returns the token.
     *
     * @param string $token              the API key candidate
     * @param string $invalidFormatError inner HTML for the format-error alert
     * @return ?string the token on success, null on failure (error already echoed)
     */
    private function persistApiKey(string $token, string $invalidFormatError): ?string
    {
        if ($token === '' || !str_starts_with($token, 'cus_')) {
            $this->echoContainer('<div class="alert alert-error">' . $invalidFormatError . '</div>');
            return null;
        }

        if (!$this->saveApiKey($token)) {
            $this->echoContainer(
                '<div class="alert alert-error">'
                . '<strong>Could not save the API key.</strong> '
                . 'Check that the database is accessible.'
                . '</div>'
            );
            return null;
        }

        return $token;
    }

    /**
     * Save the API key to the setting database table.
     *
     * Uses Config_Manager::saveSetting() which updates the existing row.
     * If the setting row does not yet exist, it is inserted first.
     * Returns true on success.
     */
    private function saveApiKey(string $token): bool
    {
        $db = $GLOBALS['db'];

        // Ensure the setting row exists.
        $existing = $db->queryOne(
            'SELECT symbol FROM setting WHERE symbol = ' . $db->quote('URLSHORTENER_API_KEY')
        );
        if (!$existing) {
            // queryOne returns a scalar (the first column value).
            $smsShortenRank = (int)$db->queryOne(
                'SELECT `rank` FROM setting WHERE symbol = ' . $db->quote('SMS_SHORTEN_URLS')
            );
            $rank = $smsShortenRank ? $smsShortenRank + 1 : 0;

            $db->exec('INSERT INTO setting
                (`rank`, heading, symbol, note, type, value)
                VALUES ('
                . (int)$rank . ', '
                . $db->quote('SMS URL Shortening') . ', '
                . $db->quote('URLSHORTENER_API_KEY') . ', '
                . $db->quote('API key for the jethro.au URL shortener (obtained via the self-service signup flow)') . ', '
                . $db->quote('text') . ', '
                . $db->quote('')
                . ')'
            );
        }

        Config_Manager::saveSetting('URLSHORTENER_API_KEY', $token);
        return true;
    }

    // ------------------------------------------------------------------
    // Deregister
    // ------------------------------------------------------------------

    /**
     * GET  — show a confirmation form before revoking the key.
     * POST — revoke the key on jethro.au, blank the local setting, show result.
     */
    private function doDeregister(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->echoContainer($this->buildDeregisterForm());
            return;
        }

        $apiKey = ifdef('URLSHORTENER_API_KEY', '');
        $prefix = $apiKey !== '' ? substr($apiKey, 0, 8) . '…' : '';

        // Revoke on the jethro.au side.
        $revoked = $this->revokeKeyOnShortener($apiKey);

        // Blank the local setting regardless of remote outcome —
        // the key is useless to us now.
        $this->blankApiKey();

        $settingsUrl = $this->getSettingsUrl();

        if ($revoked) {
            $this->echoContainer(
                '<div class="alert alert-success">'
                . '<strong>Deregistered.</strong> '
                . 'The key <code>' . ents($prefix) . '</code> has been revoked '
                . 'and removed from your configuration.'
                . '</div>'
                . '<p><a href="' . ents($settingsUrl) . '">Return to system settings</a></p>'
                . '<meta http-equiv="refresh" content="2;url=' . ents($settingsUrl) . '">'
            );
        } else {
            $this->echoContainer(
                '<div class="alert alert-warning">'
                . '<strong>Partially deregistered.</strong> '
                . 'The key <code>' . ents($prefix) . '</code> has been removed '
                . 'from your configuration, but revoking it on jethro.au failed. '
                . 'It may already have been revoked.'
                . '</div>'
                . '<p><a href="' . ents($settingsUrl) . '">Return to system settings</a></p>'
            );
        }
    }

    /**
     * Build the deregistration confirmation form.
     */
    private function buildDeregisterForm(): string
    {
        $callName = ents($this->callName());
        $apiKey   = ifdef('URLSHORTENER_API_KEY', '');
        $prefix   = $apiKey !== '' ? substr($apiKey, 0, 8) . '…' : '';

        return <<<HTML
            <div class="alert alert-warning">
                <strong>Deregister from jethro.au?</strong>
                This will:
                <ul>
                    <li>Revoke the API key <code>{$prefix}</code> on jethro.au</li>
                    <li>Remove your email and organisation name from any
                        shortened URLs you created (the URLs themselves will
                        keep working)</li>
                    <li>Remove the key from this configuration —
                        URL shortening in SMS messages will be disabled</li>
                </ul>
            </div>
            <form method="post"
                  action="?call={$callName}"
                  data-datastar="post">
                <input type="hidden" name="operation" value="deregister">
                <button type="submit" class="btn btn-danger">
                    Yes, deregister
                </button>
                <a href="?call={$callName}&amp;operation=deregister"
                   class="btn" style="margin-left: 0.5em;">
                    Cancel
                </a>
            </form>
            HTML;
    }

    /**
     * Call DELETE /api/keys/mine on jethro.au to revoke the key.
     * Returns true if the key was revoked (or was already gone).
     */
    private function revokeKeyOnShortener(string $apiKey): bool
    {
        if ($apiKey === '') return false;

        $shortenerUrl = ifdef('URLSHORTENER', '');
        if ($shortenerUrl === '') return false;

        $ch = curl_init(rtrim($shortenerUrl, '/') . '/api/keys/mine');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 200 = revoked, 404 = already gone — both are success.
        return $httpCode === 200 || $httpCode === 404;
    }

    /**
     * Blank the URLSHORTENER_API_KEY setting (set to empty string, don't delete row).
     */
    private function blankApiKey(): void
    {
        Config_Manager::saveSetting('URLSHORTENER_API_KEY', '');
    }

    // ------------------------------------------------------------------
    // URL helpers
    // ------------------------------------------------------------------

    /**
     * The absolute URL that the OAuth flow redirects back to after signup.
     *
     * This is the call endpoint itself, so the sh_apikey parameter is
     * available in GET when the handler runs next.
     */
    private function getCallbackUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            ? 'https' : 'http';
        $host = $_SERVER['HTTP_X_FORWARDED_HOST']
            ?? $_SERVER['HTTP_HOST']
            ?? $_SERVER['SERVER_NAME'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $query = 'call=' . $this->callName() . '&operation=register';

        return "{$scheme}://{$host}{$path}?{$query}";
    }

    /**
     * The URL of the system configuration page, where the SMS URL shortener
     * status panel is displayed.  Used to redirect after a successful save.
     */
    private function getSettingsUrl(): string
    {
        $path  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $query = 'view=admin__system_configuration';

        return "{$path}?{$query}";
    }
}
