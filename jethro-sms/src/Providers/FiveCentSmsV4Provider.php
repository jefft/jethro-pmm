<?php

declare(strict_types=1);

namespace Sms\Providers;
use Sms\SmsCapability;
use Sms\SmsSettings;


/**
 * SMS provider for the 5centsms.com.au v4 API.
 *
 * Deprecated in favour of {@link FiveCentSmsV5Provider}.
 *
 * Extends TemplateSmsProvider with v4-specific defaults so callers only
 * need to supply authentication (headerTemplate). All other fields
 * default to the values documented in conf.php.sample.
 *
 * Defaults:
 *   url             = https://www.5centsms.com.au/api/v4/sms
 *   postTemplate    = sender=_USER_MOBILE_&to=_RECIPIENTS_COMMAS_&message=_MESSAGE_
 *   responseOkRegex = per-recipient status check for 5centsms v4 JSON response
 *   responseIdRegex = extract message ID from v4 response
 *   balanceUrl      = https://www.5centsms.com.au/api/v4/account
 *
 * Settings read by fromSettings():
 *   When $tfa is false:
 *     SMS_HTTP_URL          → falls back to v4 default
 *     SMS_HTTP_POST_TEMPLATE → falls back to v4 default
 *     ... (same settings as TemplateSmsProvider)
 *   When $tfa is true:
 *     2FA_SMS_* settings tried first, then SMS_HTTP_* settings,
 *     then v4 defaults.
 *
 * @param bool $tfa When true, reads 2FA_SMS_* settings first.
 * @param string|null $baseUrl When non-null and URL is a bare path, resolves it to an absolute URL.
 */
class FiveCentSmsV4Provider extends TemplateSmsProvider
{
    public static function fromSettings(SmsSettings $settings, bool $tfa = false, ?string $baseUrl = null): static
    {
        // Read constants with 2FA fallback, same as TemplateSmsProvider.
        // V4-specific defaults override when the corresponding constant is unset;
        // all other fields default to the parent's empty-string/empty behaviour.

        $url = '';
        if ($tfa) {
            $url = (string)$settings['2FA_SMS_URL']->val('');
        }
        if ($url === '') {
            $url = (string)$settings['SMS_HTTP_URL']->val('https://www.5centsms.com.au/api/v4/sms');
        }

        if ($url !== '' && $url[0] === '/' && $baseUrl !== null) {
            $origin = parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST);
            $port = parse_url($baseUrl, PHP_URL_PORT);
            if ($port !== null) $origin .= ':' . $port;
            $url = $origin . $url;
        }

        if ($url === '') {
            throw new \RuntimeException('Missing SMS configuration: ' . $settings['SMS_HTTP_URL']->getName());
        }

        $postTemplate = '';
        if ($tfa) {
            $postTemplate = (string)$settings['2FA_SMS_POST_TEMPLATE']->val('');
        }
        if ($postTemplate === '') {
            $postTemplate = (string)$settings['SMS_HTTP_POST_TEMPLATE']->val('sender=_USER_MOBILE_&to=_RECIPIENTS_COMMAS_&message=_MESSAGE_');
        }

        $headerTemplate = '';
        if ($tfa) {
            $headerTemplate = (string)$settings['2FA_SMS_HEADER_TEMPLATE']->val('');
        }
        if ($headerTemplate === '') {
            $headerTemplate = (string)$settings['SMS_HTTP_HEADER_TEMPLATE']->val('');
        }

        $responseOkRegex = '';
        if ($tfa) {
            $responseOkRegex = (string)$settings['2FA_SMS_RESPONSE_OK_REGEX']->val('');
        }
        if ($responseOkRegex === '') {
            $responseOkRegex = (string)$settings['SMS_HTTP_RESPONSE_OK_REGEX']->val('[{]\s*"destination":\s*"_RECIPIENT_",[^}]*"status":\s*"?(1000|1001|1002|1004|1006|1011|1527)"?,');
        }

        $responseErrorRegex = '';
        if ($tfa) {
            $responseErrorRegex = (string)$settings['2FA_SMS_RESPONSE_ERROR_REGEX']->val('');
        }
        if ($responseErrorRegex === '') {
            $responseErrorRegex = (string)$settings['SMS_HTTP_RESPONSE_ERROR_REGEX']->val('');
        }

        $responseIdRegex = '';
        if ($tfa) {
            $responseIdRegex = (string)$settings['2FA_SMS_RESPONSE_ID_REGEX']->val('');
        }
        if ($responseIdRegex === '') {
            $responseIdRegex = (string)$settings['SMS_HTTP_RESPONSE_ID_REGEX']->val('[{]\s*"destination":\s*"_RECIPIENT_",[^}]*"id":\s*"([^"]+)"[^}]*}');
        }

        $balanceUrl = '';
        if ($tfa) {
            $balanceUrl = (string)$settings['2FA_SMS_URL_BALANCE']->val('');
        }
        if ($balanceUrl === '') {
            $balanceUrl = (string)$settings['SMS_HTTP_URL_BALANCE']->val('https://www.5centsms.com.au/api/v4/account');
        }

        return new static(
            url: $url,
            postTemplate: $postTemplate,
            headerTemplate: $headerTemplate,
            responseOkRegex: $responseOkRegex,
            responseErrorRegex: $responseErrorRegex,
            responseIdRegex: $responseIdRegex,
            // Empty setting (e.g. blank row in `setting` table) means "use default".
            localPrefix: (string)$settings['SMS_LOCAL_PREFIX']->val('') ?: '0',
            internationalPrefix: (string)$settings['SMS_INTERNATIONAL_PREFIX']->val('') ?: '61',
            recipientArrayParameter: (string)$settings['SMS_RECIPIENT_ARRAY_PARAMETER']->val(''),
            balanceUrl: $balanceUrl,
            httpClient: static::createHttpClient(
                filter_var($settings['SMS_TESTMODE']->val(false), FILTER_VALIDATE_BOOLEAN),
                filter_var($settings['SMS_VERBOSE']->val(false), FILTER_VALIDATE_BOOLEAN),
            ),
            senderIds: (string)$settings['SMS_SENDER_OPTIONS']->val(''),
        );
    }

    public function getKey(): string
    {
        return '5csmsv4';
    }

    public static function usagePreference(): int
    {
        return -1;
    }

    public function getDescription(): string
    {
        return '5CentSMS (v4)';
    }

    public function hasCapability(SmsCapability $cap): bool
    {
        return $cap === SmsCapability::GET_BALANCE;
    }

    /** @return array<array{string, string, string}> */
    public static function getConstants(): array
    {
        return [
            ['SMS_HTTP_URL', 'optional', 'Gateway endpoint URL (default: v4 endpoint)'],
            ['SMS_HTTP_POST_TEMPLATE', 'optional', 'POST body template (default: v4 template)'],
            ['SMS_HTTP_HEADER_TEMPLATE', 'required', 'Additional HTTP headers (e.g. auth)'],
            ['SMS_HTTP_RESPONSE_OK_REGEX', 'optional', 'Regex for successful send responses (default: v4 pattern)'],
            ['SMS_HTTP_RESPONSE_ERROR_REGEX', 'optional', 'Regex for error responses'],
            ['SMS_HTTP_RESPONSE_ID_REGEX', 'optional', 'Regex to extract remote message ID from response (default: v4 pattern)'],
            ['SMS_HTTP_URL_BALANCE', 'optional', 'Balance endpoint URL (default: v4 endpoint)'],
            ['SMS_SENDER_OPTIONS', 'optional', 'Comma-separated sender options, may include _USER_MOBILE_'],
            ['SMS_TESTMODE', 'optional', 'Dry-run test mode: bool (returns "OK" for POST requests)'],
            ['SMS_VERBOSE', 'optional', 'Log HTTP to error log'],
        ];
    }
}
