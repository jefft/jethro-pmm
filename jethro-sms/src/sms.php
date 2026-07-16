<?php

/**
 * Backward-compatibility loader — requires the individual type files
 * that were extracted from this monolith (2026-07-03 restructure).
 *
 * New code should require src/load.php instead.
 */

require_once __DIR__ . '/SmsCapability.php';
require_once __DIR__ . '/FormField.php';
require_once __DIR__ . '/RegistrationStep.php';
require_once __DIR__ . '/SmsProvider.php';
require_once __DIR__ . '/DecoratingSmsProvider.php';
require_once __DIR__ . '/TokenExpandingSmsProvider.php';
require_once __DIR__ . '/OverridingSmsProvider.php';
require_once __DIR__ . '/SmsStatus.php';
require_once __DIR__ . '/SendSummary.php';
require_once __DIR__ . '/SmsCache.php';
require_once __DIR__ . '/SmsSender.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/FakeHttpClient.php';
require_once __DIR__ . '/SmsDelivery.php';
require_once __DIR__ . '/Providers/TemplateSmsProvider.php';
require_once __DIR__ . '/Providers/FiveCentSmsV4Provider.php';
require_once __DIR__ . '/Providers/FiveCentSmsV5Provider.php';
require_once __DIR__ . '/Providers/CellcastFakeHttpClient.php';
require_once __DIR__ . '/Providers/FiveCentSmsV5FakeHttpClient.php';
require_once __DIR__ . '/Providers/SmsBroadcastFakeHttpClient.php';
require_once __DIR__ . '/Providers/TemplateFakeHttpClient.php';
require_once __DIR__ . '/Providers/FiveCentSmsDelivery.php';
require_once __DIR__ . '/Providers/SmsBroadcastSmsDelivery.php';
require_once __DIR__ . '/functions.php';
