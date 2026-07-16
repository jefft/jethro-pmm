<?php

/**
 * Status panel for the SMS URL Shortener section of the system configuration page.
 * Fetched via AJAX from ?call=admin_statuspanel_urlshortener_api_key.
 *
 * Attached to the URLSHORTENER_API_KEY setting.  Auto-discovered by
 * {@see View_Admin__System_Configuration::printStatusPanel()} via the
 * longest-match naming convention: the symbol URLSHORTENER_API_KEY lowercases
 * to urlshortener_api_key, which matches this file.
 *
 * Shows:
 *  1. Configured — is URLSHORTENER defined?
 *  2. (If configured) Status — is an API key registered?  Operational?
 *  3. Operation: "Register with jethro.au URL shortener" — OAuth signup flow
 *
 * @see Call_Admin_Statuspanel_Operation_Urlshortener_Api_Key
 */

require_once __DIR__ . '/call_admin_statuspanel.class.php';

class Call_Admin_Statuspanel_Urlshortener_Api_Key extends Call_Admin_Statuspanel
{
    public function linkedFeature(): ?string
    {
        return null;
    }

    protected function isConfigured(): \Result
    {
        $url = ifdef('URLSHORTENER', '');
        if ($url === '') {
            return \Result::failure([
                'message' => 'No URL shortener base URL has been set.',
                'details' => 'Define the <code>URLSHORTENER</code> setting '
                    . '(e.g. <code>https://jethro.au</code>) in conf.php '
                    . 'to enable automatic URL shortening in SMS messages.',
            ]);
        }
        if (!preg_match('#^https?://#', $url)) {
            return \Result::failure([
                'message' => 'The URL shortener base URL is not a valid URL.',
                'details' => 'The <code>URLSHORTENER</code> setting must start with '
                    . '<code>http://</code> or <code>https://</code>. '
                    . 'Current value: <code>' . ents($url) . '</code>.',
            ]);
        }
        return \Result::success(true);
    }

    protected function getHelpText(): string
    {
        return 'Long URLs in SMS messages can be automatically shortened '
            . 'via an external URL shortening service, saving characters and cost.';
    }

    protected function getStatus(): array
    {
        $apiKey       = ifdef('URLSHORTENER_API_KEY', '');
        $shortenerUrl = ifdef('URLSHORTENER', '');

        if ($apiKey === '') {
            return [
                'success' => false,
                'message' => 'No API key registered. '
                    . 'Click "Register with jethro.au URL shortener" below to obtain one.',
            ];
        }

        // Validate the key against the live API.
        $validateUrl = rtrim($shortenerUrl, '/') . '/api/validate';
        $ch = curl_init($validateUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT        => 5,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error   = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'message' => 'Could not reach ' . ents($shortenerUrl) . ': ' . ents($error),
            ];
        }

        $data = json_decode($body, true);

        if ($httpCode === 401 || !is_array($data) || empty($data['valid'])) {
            return [
                'success' => false,
                'message' => 'The registered API key is invalid — it was rejected by '
                    . ents($shortenerUrl) . '. Click "Reregister" below to obtain a new one.',
            ];
        }

        $type     = $data['type'] ?? 'unknown';
        $created  = $data['created'] ?? '';
        $prefix   = $data['tokenPrefix'] ?? (substr($apiKey, 0, 8) . '…');
        $provider = $data['provider'] ?? '';

        $message = 'Operational — API key validated by ' . ents($shortenerUrl);
        $details = [
            'Shortener URL' => ents($shortenerUrl),
            'API key'       => $prefix,
        ];
        if ($type !== 'create') {
            $details['Type'] = $type;
        }
        $campaign = $data['createdByCampaign'] ?? '';
        if ($campaign !== '') {
            $details['Registered to'] = ents($campaign);
        }
        if ($provider) {
            $message .= ' (' . ents($provider) . ')';
            $details['Validated by'] = ucfirst($provider);
        }
        if ($created) {
            $details['Created'] = $created;
        }

        return [
            'success' => true,
            'message' => $message,
            'details' => $details,
        ];
    }

    /** @return array<string, string>  method => label */
    protected function getOperations(): array
    {
        $label = ifdef('URLSHORTENER_API_KEY', '') !== ''
            ? 'Reregister'
            : 'Register';

        $ops = ['register' => $label];

        if (ifdef('URLSHORTENER_API_KEY', '') !== '') {
            $ops['deregister'] = 'Deregister';
        }

        return $ops;
    }
}
