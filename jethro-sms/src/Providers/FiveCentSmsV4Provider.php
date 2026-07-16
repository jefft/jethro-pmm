<?php

declare(strict_types=1);

namespace Sms\Providers;
use Sms\SmsCapability;


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
 * PHP constants read by fromConstants():
 *   When $tfa is false:
 *     SMS_HTTP_URL          → falls back to v4 default
 *     SMS_HTTP_POST_TEMPLATE → falls back to v4 default
 *     ... (same constants as TemplateSmsProvider)
 *   When $tfa is true:
 *     2FA_SMS_* constants tried first, then SMS_HTTP_* constants,
 *     then v4 defaults.
 *
 */
class FiveCentSmsV4Provider extends TemplateSmsProvider
{
    public static function fromConstants(bool $tfa = false): static
    {
        // Read constants with 2FA fallback, same as TemplateSmsProvider.
        // V4-specific defaults override when the corresponding constant is unset;
        // all other fields default to the parent's empty-string/empty behaviour.

        $url = '';
        if ($tfa) {
            $url = (string)ifdef('2FA_SMS_URL', '');
        }
        if ($url === '') {
            $url = (string)ifdef('SMS_HTTP_URL', 'https://www.5centsms.com.au/api/v4/sms');
        }

        if ($url === '') {
            throw new \RuntimeException('Missing SMS configuration: SMS_HTTP_URL');
        }

        $postTemplate = '';
        if ($tfa) {
            $postTemplate = (string)ifdef('2FA_SMS_POST_TEMPLATE', '');
        }
        if ($postTemplate === '') {
            $postTemplate = (string)ifdef('SMS_HTTP_POST_TEMPLATE', 'sender=_USER_MOBILE_&to=_RECIPIENTS_COMMAS_&message=_MESSAGE_');
        }

        $headerTemplate = '';
        if ($tfa) {
            $headerTemplate = (string)ifdef('2FA_SMS_HEADER_TEMPLATE', '');
        }
        if ($headerTemplate === '') {
            $headerTemplate = (string)ifdef('SMS_HTTP_HEADER_TEMPLATE', '');
        }

        $responseOkRegex = '';
        if ($tfa) {
            $responseOkRegex = (string)ifdef('2FA_SMS_RESPONSE_OK_REGEX', '');
        }
        if ($responseOkRegex === '') {
            $responseOkRegex = (string)ifdef('SMS_HTTP_RESPONSE_OK_REGEX', '[{]\s*"destination":\s*"_RECIPIENT_",[^}]*"status":\s*"?(1000|1001|1002|1004|1006|1011|1527)"?,');
        }

        $responseErrorRegex = '';
        if ($tfa) {
            $responseErrorRegex = (string)ifdef('2FA_SMS_RESPONSE_ERROR_REGEX', '');
        }
        if ($responseErrorRegex === '') {
            $responseErrorRegex = (string)ifdef('SMS_HTTP_RESPONSE_ERROR_REGEX', '');
        }

        $responseIdRegex = '';
        if ($tfa) {
            $responseIdRegex = (string)ifdef('2FA_SMS_RESPONSE_ID_REGEX', '');
        }
        if ($responseIdRegex === '') {
            $responseIdRegex = (string)ifdef('SMS_HTTP_RESPONSE_ID_REGEX', '[{]\s*"destination":\s*"_RECIPIENT_",[^}]*"id":\s*"([^"]+)"[^}]*}');
        }

        $balanceUrl = '';
        if ($tfa) {
            $balanceUrl = (string)ifdef('2FA_SMS_URL_BALANCE', '');
        }
        if ($balanceUrl === '') {
            $balanceUrl = (string)ifdef('SMS_HTTP_URL_BALANCE', 'https://www.5centsms.com.au/api/v4/account');
        }

        return new static(
            url: $url,
            postTemplate: $postTemplate,
            headerTemplate: $headerTemplate,
            responseOkRegex: $responseOkRegex,
            responseErrorRegex: $responseErrorRegex,
            responseIdRegex: $responseIdRegex,
            localPrefix: (string)ifdef('SMS_LOCAL_PREFIX', '0'),
            internationalPrefix: (string)ifdef('SMS_INTERNATIONAL_PREFIX', '61'),
            recipientArrayParameter: (string)ifdef('SMS_RECIPIENT_ARRAY_PARAMETER', ''),
            balanceUrl: $balanceUrl,
            httpClient: static::createHttpClient(
                filter_var(ifdef('SMS_TESTMODE', false), FILTER_VALIDATE_BOOLEAN),
                filter_var(ifdef('SMS_VERBOSE', false), FILTER_VALIDATE_BOOLEAN),
            ),
            senderIds: (string)ifdef('SMS_SENDER_OPTIONS', ''),
            balance: (string)ifdef('SMS_BALANCE', ''),
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

    public function isOperational(): \Result
    {
        $cached = $this->cache?->get('sms_operational');
        if ($cached !== null) {
            return \Result::success((bool) $cached);
        }

        $result = $this->getBalance();

        if ($result->isSuccess()) {
            $this->cache?->set('sms_operational', true, 300);
            return \Result::success(true);
        }

        $this->cache?->set('sms_operational', false, 60);
        return \Result::success(false);
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
