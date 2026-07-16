<?php

/**
 * SSE endpoint that long-polls the 2fa_pending table for verification.
 *
 * GET ?call=2fa_wait
 *
 * Reads $_SESSION['2fa']['verify_token'] to know which row to poll.
 * Releases the session lock before entering the poll loop so the
 * ?call=2fa_verify request (from the phone) can acquire the session
 * if needed.  Re-acquires periodically to check for typed-code
 * verification (which happens in the same session).
 *
 * On verification, pushes a Datastar signal that the 2FA form's JS
 * uses to auto-submit.  If the 2FA window expires, pushes an expiry
 * signal so the UI can refresh.
 *
 * @see \Jethro\sseStart()
 * @see \Jethro\ssePatchSignals()
 */
class Call_2FA_Wait extends Call
{
    /** Maximum seconds to poll before giving up. */
    private const MAX_WAIT = 600; // 10 minutes — matches 2FA code expiry

    /** Seconds between DB polls. */
    private const POLL_INTERVAL = 2;

    public function run(): void
    {
        // Ensure the session is started and read the verify token.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $verifyToken = $_SESSION['2fa']['verify_token'] ?? null;
        $expiry = $_SESSION['2fa']['expiry'] ?? 0;

        if ($verifyToken === null) {
            http_response_code(400);
            echo "No pending 2FA verification.\n";
            return;
        }

        // Release the session lock so the typed-code POST and the
        // verify-link GET can both acquire the session.
        session_write_close();

        require_once __DIR__ . '/../include/sse.php';
        \Jethro\sseStart();

        $deadline = min(time() + self::MAX_WAIT, $expiry);
        $db = $GLOBALS['db'];

        while (time() < $deadline) {
            // Poll the 2fa_pending table.
            $SQL = 'SELECT verified FROM 2fa_pending
                    WHERE verify_token = ' . $db->quote($verifyToken) . '
                      AND expiry > NOW()';
            $row = $db->queryOne($SQL);

            if ($row && !empty($row['verified'])) {
                \Jethro\ssePatchSignals(['2faVerified' => true]);
                return;
            }

            // Also check session (typed-code path sets verified in session).
            session_start();
            $sessionVerified = !empty($_SESSION['2fa']['verified']);
            session_write_close();

            if ($sessionVerified) {
                \Jethro\ssePatchSignals(['2faVerified' => true]);
                return;
            }

            sleep(self::POLL_INTERVAL);
        }

        // Timed out — signal expiry so the UI can refresh.
        \Jethro\ssePatchSignals(['2faExpired' => true]);
    }
}
