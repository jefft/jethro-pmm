<?php

/**
 * ============================================================================
 * Minimal Server-Sent Events helper for Datastar
 * ============================================================================
 *
 * Namespace: Jethro
 *
 * Emits the SSE event frames the Datastar v1.x client consumes. Jethro has no
 * Composer autoloader, so we deliberately do NOT pull in the Datastar SDK —
 * the wire format is tiny and stable. See
 * docs/docs/developer/reference/sms/SMS_DATASTAR.md for how this is used by
 * calls/call_sms_statusline.class.php.
 *
 * Datastar SSE wire format (v1.x):
 *
 *   event: datastar-patch-elements
 *   data: elements <div id="foo">Hello</div>
 *   <blank line>
 *
 *   event: datastar-patch-signals
 *   data: signals {"foo": 1, "bar": 2}
 *   <blank line>
 *
 * Default element patch mode is `morph`, matched by top-level element id —
 * which is exactly what the statusline/preview replacement wants. Multi-line
 * HTML is split across repeated `data: elements` lines. Each event is
 * terminated by a blank line (two newlines).
 *
 * This is a ONE-SHOT response: the Call emits its frames and returns; the
 * connection then closes. Datastar accepts a connection that closes
 * immediately after the frames.
 */

namespace Jethro;

/**
 * Begin an SSE response: flush any output buffers, set the streaming headers,
 * and disable proxy/php-fpm buffering so frames reach the client promptly.
 *
 * Safe to call once per request, before any ssePatch* call.
 */
function sseStart(): void
{
    // Drain any output buffering so our frames are not held back.
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    if (!headers_sent()) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        // Disable nginx/php-fpm response buffering (see nginx/CLAUDE_NGINX.md).
        header('X-Accel-Buffering: no');
    }
}

/**
 * Emit a `datastar-patch-elements` frame. The element is morphed into the DOM
 * by id (default Datastar mode). Multi-line HTML is split across repeated
 * `data: elements` lines per the SSE spec.
 */
function ssePatchElements(string $html): void
{
    echo "event: datastar-patch-elements\n";
    foreach (explode("\n", $html) as $line) {
        echo 'data: elements ' . $line . "\n";
    }
    echo "\n";
    flush();
}

/**
 * Emit a `datastar-patch-signals` frame, patching the named signals into the
 * client's signal store. Values are JSON-encoded.
 *
 * @param array<string, mixed> $signals
 */
function ssePatchSignals(array $signals): void
{
    echo "event: datastar-patch-signals\n";
    echo 'data: signals ' . json_encode($signals, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    echo "\n";
    flush();
}
