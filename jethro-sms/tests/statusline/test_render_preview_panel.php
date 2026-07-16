<?php

/**
 * Unit tests for Sms\renderPreviewPanel() — per-recipient preview table.
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
        ['personId' => 1, 'name' => 'Alice', 'message' => 'Hi Alice', 'status' => 0],
        ['personId' => 2, 'name' => 'Bob', 'message' => 'Hi Bob', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, []);
    // One <tr> per body row + one in the <thead>.
    assert_eq(substr_count($html, 'data-personid='), 2);
    assert_contains($html, 'Alice');
    assert_contains($html, 'Hi Bob');
});

test('names and messages are HTML-escaped', function () {
    $deliveries = [
        ['personId' => 1, 'name' => '<b>Eve</b>', 'message' => 'a & b <script>', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_contains($html, '&lt;b&gt;Eve&lt;/b&gt;');
    assert_contains($html, 'a &amp; b &lt;script&gt;');
    assert_not_contains($html, '<script>');
});

test('overridden row is highlighted (#fff3cd) and shows the override text', function () {
    $deliveries = [
        ['personId' => 7, 'name' => 'Carol', 'message' => 'original', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, [7 => 'EDITED TEXT']);
    assert_contains($html, '#fff3cd');
    assert_contains($html, 'EDITED TEXT');
    assert_not_contains($html, 'original');
});

test('non-overridden row has no highlight', function () {
    $deliveries = [
        ['personId' => 7, 'name' => 'Carol', 'message' => 'original', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_not_contains($html, '#fff3cd');
    assert_contains($html, 'original');
});

test('override keyed by string personId is recognised', function () {
    $deliveries = [
        ['personId' => 7, 'name' => 'Carol', 'message' => 'original', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, ['7' => 'EDITED']);
    assert_contains($html, '#fff3cd');
    assert_contains($html, 'EDITED');
});

test('empty message renders as (empty) placeholder', function () {
    $deliveries = [
        ['personId' => 1, 'name' => 'Alice', 'message' => '', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_contains($html, '(empty)');
});

test('missing name falls back to #personId', function () {
    $deliveries = [
        ['personId' => 42, 'name' => '', 'message' => 'hello', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_contains($html, '#42');
});

test('row carries data-personid for client override binding', function () {
    $deliveries = [
        ['personId' => 99, 'name' => 'X', 'message' => 'm', 'status' => 0],
    ];
    $html = renderPreviewPanel($deliveries, []);
    assert_contains($html, 'data-personid="99"');
});
