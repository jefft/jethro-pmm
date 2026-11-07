<?php
/**
 * SMS shortener client — the jethro/jethro-sms-shortener Composer package
 * (Apache-2.0), installed into vendor/ by `composer install`.
 *
 * Provides getSmsShortener() and smsShortenerConfig(): a client for the
 * jethro.au Worker that rewords an SMS to fit in fewer segments with Workers
 * AI. Jethro uses it for the composer's "Shorter alternative" suggestion
 * (calls/call_sms_shorten.class.php).
 *
 * Loaded through the Composer autoloader, which includes the package's
 * SmsShortener.php as an autoload "files" entry; requiring that file directly
 * as well would declare its functions twice.
 *
 * See https://github.com/jethro-chms/jethro-sms-shortener and
 * docs/docs/developer/reference/sms-shortener.md
 */
require_once __DIR__ . '/../vendor/autoload.php';
