<?php

/**
 * Handles the 2FA verification link clicked from an SMS.
 *
 * GET ?call=2fa_verify&t=<verify_token>
 *
 * Marks the corresponding 2fa_pending row as verified so the desktop SSE
 * endpoint can pick it up.  Works cross-device: the phone that clicks the
 * SMS link has no PHP session relationship to the desktop browser waiting
 * on the 2FA form.
 *
 * Returns a simple "Verified" page.
 */
class Call_2FA_Verify extends Call
{
    public function run(): void
    {
        $token = (string) ($_GET['t'] ?? '');

        if ($token === '') {
            http_response_code(400);
            echo '<p>Missing verification token.</p>';
            return;
        }

        $db = $GLOBALS['db'];
        $SQL = 'UPDATE 2fa_pending
                SET verified = 1
                WHERE verify_token = ' . $db->quote($token) . '
                  AND expiry > NOW()
                  AND verified = 0';
        $affected = $db->exec($SQL);

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Verified — Jethro</title>
            <style>
                body { font-family: system-ui, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f5f5f5; }
                .box { text-align: center; padding: 2rem; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
                .check { font-size: 3rem; color: #468847; }
                h1 { color: #333; font-size: 1.25rem; margin: 0.5rem 0; }
                p { color: #666; font-size: 0.875rem; }
            </style>
        </head>
        <body>
            <div class="box">
                <div class="check">&#10003;</div>
                <?php if ($affected): ?>
                    <h1>Verified!</h1>
                    <p>You can close this tab and return to Jethro.</p>
                <?php else: ?>
                    <h1>Link expired or already used</h1>
                    <p>Please type the 6-digit code from the SMS instead.</p>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
    }
}
