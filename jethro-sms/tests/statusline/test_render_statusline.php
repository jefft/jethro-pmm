<?php

/**
 * Unit tests for Sms\renderStatusline() — status-line HTML composition.
 *
 * Ports the user-visible phrasing/composition rules from the former
 * resources/js/jethro-sms.js renderStatusline() (now moved to the server —
 * see docs/docs/developer/reference/sms/SMS_DATASTAR.md).
 *
 * Reference: jethro-sms/src/sms_statusline.php renderStatusline(), buildCostLine().
 */

namespace Test\Sms\Statusline;

use function \Test\{test, assert_eq, assert_true, assert_false, assert_contains, assert_not_contains};
use \Sms\SmsStatuslineConfig;
use function \Sms\renderStatusline;

require_once __DIR__ . '/../../src/load.php';

/** @param array<int,array{personId?:?int,name?:string,message?:string,status?:int}> $deliveries */
function _row(int $pid, string $msg): array
{
    return ['personId' => $pid, 'name' => "P$pid", 'message' => $msg, 'status' => 0];
}

test('empty message -> empty html, not blocked', function () {
    $cfg = new SmsStatuslineConfig();
    $res = renderStatusline('', [], $cfg);
    assert_eq($res['html'], '');
    assert_false($res['blocked']);
});

test('whitespace-only message -> empty html', function () {
    $cfg = new SmsStatuslineConfig();
    $res = renderStatusline("   \n\t", [], $cfg);
    assert_eq($res['html'], '');
});

test('cost line phrasing: "1 segment -> 4 recipients = $0.20." (no leading char count)', function () {
    $cfg = new SmsStatuslineConfig(segmentCost: 0.05);
    $msg = str_repeat('a', 29);
    $deliveries = [_row(1, $msg), _row(2, $msg), _row(3, $msg), _row(4, $msg)];
    $res = renderStatusline($msg, $deliveries, $cfg);
    assert_contains($res['html'], '1 segment → 4 recipients = $0.20.');
    assert_false($res['blocked']);
});

test('plural "segments" when >1 segment', function () {
    $cfg = new SmsStatuslineConfig(segmentCost: 0.05);
    $msg = str_repeat('a', 200); // 2 GSM segments
    $deliveries = [_row(1, $msg)];
    $res = renderStatusline($msg, $deliveries, $cfg);
    assert_contains($res['html'], '2 segments →');
});

test('UCS-2 segment label when message has non-GSM chars (preview path)', function () {
    $cfg = new SmsStatuslineConfig(segmentCost: 0.05, unicodeMode: 'enabled');
    $msg = 'Hi😊';
    $deliveries = [_row(1, $msg)];
    $res = renderStatusline($msg, $deliveries, $cfg);
    assert_contains($res['html'], 'UCS-2 segment');
});

test('balance warning replaces cost line when over budget', function () {
    // 1 segment * 4 recipients = 4 segments needed; balance 2 -> blocked.
    $cfg = new SmsStatuslineConfig(segmentCost: 0.05, balance: 2);
    $msg = str_repeat('a', 29);
    $deliveries = [_row(1, $msg), _row(2, $msg), _row(3, $msg), _row(4, $msg)];
    $res = renderStatusline($msg, $deliveries, $cfg);
    assert_contains($res['html'], 'Insufficient balance: 4 segments needed, 2 available.');
    assert_not_contains($res['html'], 'recipients = $'); // cost line suppressed
    assert_true($res['blocked']);
});

test('max-length warning appears when effective length reaches the cap', function () {
    $cfg = new SmsStatuslineConfig(maxLength: 10, segmentCost: 0);
    $res = renderStatusline('0123456789', [], $cfg);
    assert_contains($res['html'], 'Max length (10) reached.');
});

test('test-mode notice appended when testMode on', function () {
    $cfg = new SmsStatuslineConfig(testMode: true, segmentCost: 0);
    $res = renderStatusline('Hi there', [], $cfg);
    assert_contains($res['html'], 'Test mode enabled — no SMSes will be sent.');
});

test('unicode cost-doubling warning when removing special chars would save a segment', function () {
    // Emoji + 155 GSM chars: UCS-2 needs 3 segments, GSM-only needs fewer.
    $cfg = new SmsStatuslineConfig(unicodeMode: 'enabled', segmentCost: 0);
    $msg = 'Hello😊' . str_repeat('a', 155);
    $res = renderStatusline($msg, [], $cfg);
    assert_contains($res['html'], 'doubles the cost.');
    // Emoji is rendered as itself (code-point display), not as a replacement char.
    assert_contains($res['html'], '😊');
});

test('unicode-disabled block short-circuits to ONLY the block message', function () {
    $cfg = new SmsStatuslineConfig(unicodeMode: 'disabled', testMode: true, segmentCost: 0.05);
    $res = renderStatusline('Hello😊', [], $cfg);
    assert_contains($res['html'], 'Unicode characters are not allowed:');
    assert_contains($res['html'], '😊');
    // Short-circuit: no cost line, no test-mode notice.
    assert_not_contains($res['html'], 'segment');
    assert_not_contains($res['html'], 'Test mode');
    assert_true($res['blocked']);
});

test('"chars remaining" fallback when no segment cost configured (single segment)', function () {
    $cfg = new SmsStatuslineConfig(segmentCost: 0);
    $res = renderStatusline('Hello', [], $cfg);
    assert_contains($res['html'], 'chars remaining in this segment');
});

test('"N chars = M segments" fallback when no cost and multi-segment', function () {
    $cfg = new SmsStatuslineConfig(segmentCost: 0);
    $res = renderStatusline(str_repeat('a', 200), [], $cfg);
    assert_contains($res['html'], 'chars = 2 segments');
});

test('config-help span shown for sysadmins on max-length warning', function () {
    $cfg = new SmsStatuslineConfig(maxLength: 5, segmentCost: 0, isSysadmin: true);
    $res = renderStatusline('123456', [], $cfg);
    assert_contains($res['html'], 'class="config-help"');
    assert_contains($res['html'], 'SMS_MAX_LENGTH');
});

test('no config-help span for non-sysadmins', function () {
    $cfg = new SmsStatuslineConfig(maxLength: 5, segmentCost: 0, isSysadmin: false);
    $res = renderStatusline('123456', [], $cfg);
    assert_not_contains($res['html'], 'config-help');
});
