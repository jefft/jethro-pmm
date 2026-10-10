<?php

/**
 * Unit tests for Sms\renderPreviewPanel() — the preview table, one row per message.
 *
 * Ports the HTML structure and override-highlight behaviour from the former
 * resources/js/jethro-sms.js renderPreviewPanel() (now on the server — see
 * docs/docs/developer/reference/sms/SMS_DATASTAR.md).
 *
 * Reference: jethro-sms/src/sms_statusline.php renderPreviewPanel().
 */

namespace Test\Sms\Statusline;

use function \Test\{test, assert_eq, assert_contains, assert_not_contains};
use function \Sms\renderPreviewPanel;

require_once __DIR__ . '/../../src/load.php';

test('empty deliveries -> empty string', function () {
    assert_eq(renderPreviewPanel([], []), '');
});

test('one row per delivery', function () {
    $deliveries = [
        ['key' => '1', 'name' => 'Alice', 'message' => 'Hi Alice', 'status' => 0],
        ['key' => '2', 'name' => 'Bob', 'message' => 'Hi Bob', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_eq(substr_count($html, 'class="sms-preview-msg"'), 2);
    assert_contains($html, 'Alice');
    assert_contains($html, 'Hi Bob');
});

test('names and messages are HTML-escaped', function () {
    $deliveries = [
        ['key' => '1', 'name' => '<b>Eve</b>', 'message' => 'a & b <script>', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_contains($html, '&lt;b&gt;Eve&lt;/b&gt;');
    assert_contains($html, 'a &amp; b &lt;script&gt;');
    assert_not_contains($html, '<script>');
});

test('overridden row is highlighted (#fff3cd) and shows the override text', function () {
    $deliveries = [
        ['key' => '7', 'name' => 'Carol', 'message' => 'original', 'status' => 0],
    ];
    // PHP parses message_overrides[7] into int key 7; the row key is the string '7'.
    $html = renderPreviewPanel($deliveries, [7 => 'EDITED TEXT']);
    assert_contains($html, '#fff3cd');
    assert_contains($html, 'EDITED TEXT');
    assert_not_contains($html, 'original');
});

test('non-overridden row has no highlight', function () {
    $deliveries = [
        ['key' => '7', 'name' => 'Carol', 'message' => 'original', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_not_contains($html, '#fff3cd');
    assert_contains($html, 'original');
});

test('a shared-mobile row (key 1_2) posts its override as message_overrides[1_2], highlights by that key, and lists one name per line', function () {
    $deliveries = [
        ['key' => '1_2', 'name' => "Alice\nBob", 'message' => 'Hi Alice & Bob', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, ['1_2' => 'EDITED']);
    assert_contains($html, "'message_overrides[1_2]'", 'the send path expands this key to both people');
    assert_contains($html, '#fff3cd');
    assert_contains($html, 'EDITED');
    assert_contains($html, 'Alice</span><br><span style="white-space:nowrap">Bob');
});

test('row keys are reduced to word characters before landing in Datastar expressions', function () {
    $deliveries = [
        ['key' => "1');alert(1);('", 'name' => 'X', 'message' => 'm', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_not_contains($html, 'alert(1)');
    assert_contains($html, "message_overrides[1alert1]");
});

test('empty message renders as (empty) placeholder', function () {
    $deliveries = [
        ['key' => '1', 'name' => 'Alice', 'message' => '', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_contains($html, '(empty)');
});

test('missing name falls back to #key', function () {
    $deliveries = [
        ['key' => '42', 'name' => '', 'message' => 'hello', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_contains($html, '#42');
});
