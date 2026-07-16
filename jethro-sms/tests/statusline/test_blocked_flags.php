<?php

/**
 * Unit tests for the blocked/blockReason flags returned by
 * Sms\renderStatusline(). These drive the Datastar $smsSendBlocked /
 * $smsBlockReason signals that disable the Send button (formerly the
 * unicode-blocked / balance-blocked jQuery .data() flags in jethro-sms.js).
 *
 * See docs/docs/developer/reference/sms/SMS_DATASTAR.md.
 *
 * Reference: jethro-sms/src/sms_statusline.php renderStatusline().
 */

namespace Test\Sms\Statusline;

use function \Test\{test, assert_true, assert_false, assert_contains, assert_eq};
use \Sms\SmsStatuslineConfig;
use function \Sms\renderStatusline;

require_once __DIR__ . '/../../src/load.php';

function _prow(int $pid, string $msg): array
{
    return ['personId' => $pid, 'name' => "P$pid", 'message' => $msg, 'status' => 0];
}

test('unicode-disabled message -> blocked=true with a reason', function () {
    $cfg = new SmsStatuslineConfig(unicodeMode: 'disabled');
    $res = renderStatusline('Hello😊', [], $cfg);
    assert_true($res['blocked']);
    assert_contains($res['blockReason'], 'unicode policy');
});

test('when_free >70 chars with non-GSM -> blocked=true', function () {
    $cfg = new SmsStatuslineConfig(unicodeMode: 'when_free');
    $res = renderStatusline('Hello😊' . str_repeat('a', 65), [], $cfg);
    assert_true($res['blocked']);
    assert_contains($res['blockReason'], 'unicode policy');
});

test('when_free <=70 chars with non-GSM -> not blocked', function () {
    $cfg = new SmsStatuslineConfig(unicodeMode: 'when_free');
    $res = renderStatusline('Hi😊', [], $cfg);
    assert_false($res['blocked']);
    assert_eq($res['blockReason'], '');
});

test('insufficient balance -> blocked=true with a balance reason', function () {
    $cfg = new SmsStatuslineConfig(segmentCost: 0.05, balance: 1);
    $msg = str_repeat('a', 29);
    $deliveries = [_prow(1, $msg), _prow(2, $msg), _prow(3, $msg), _prow(4, $msg)];
    $res = renderStatusline($msg, $deliveries, $cfg);
    assert_true($res['blocked']);
    assert_contains($res['blockReason'], 'Insufficient balance');
});

test('sufficient balance -> not blocked', function () {
    $cfg = new SmsStatuslineConfig(segmentCost: 0.05, balance: 100);
    $msg = str_repeat('a', 29);
    $deliveries = [_prow(1, $msg), _prow(2, $msg)];
    $res = renderStatusline($msg, $deliveries, $cfg);
    assert_false($res['blocked']);
    assert_eq($res['blockReason'], '');
});

test('balance check does NOT fire on raw-text estimate (no preview deliveries)', function () {
    // No deliveries -> recipientCount assumed 1, balance check skipped entirely.
    $cfg = new SmsStatuslineConfig(segmentCost: 0.05, balance: 0);
    $res = renderStatusline(str_repeat('a', 29), [], $cfg);
    assert_false($res['blocked']);
});

test('enabled mode with non-GSM short message -> not blocked', function () {
    $cfg = new SmsStatuslineConfig(unicodeMode: 'enabled', segmentCost: 0);
    $res = renderStatusline('Hi😊', [], $cfg);
    assert_false($res['blocked']);
});
