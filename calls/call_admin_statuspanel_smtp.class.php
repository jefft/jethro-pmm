<?php

/**
 * Status panel for the SMTP Email section of the system configuration page.
 * Fetched via AJAX from ?call=admin_statuspanel_smtp.
 */

require_once __DIR__ . '/call_admin_statuspanel.class.php';

class Call_Admin_Statuspanel_Smtp extends Call_Admin_Statuspanel
{
    protected function isConfigured(): \Result
    {
        $configured = ifdef('SMTP_SERVER', '') !== '';
        return $configured ? \Result::success(true) : \Result::failure('SMTP_SERVER is not set');
    }

    protected function getHelpText(): string
    {
        return 'SMTP settings are used to send member registration emails, error alerts to system administrators, and other system notifications.';
    }

    protected function getStatus(): array
    {
        require_once JETHRO_ROOT . '/include/emailer.class.php';
        $result = Emailer::testConnection();

        $details = [];
        if ($result['greeting'] !== '') {
            $details['Greeting'] = $result['greeting'];
        }
        if ($result['ehlo'] !== '') {
            $details['EHLO'] = $result['ehlo'];
        }

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'SMTP server is reachable and responding.' : $result['error'],
            'details' => $details,
        ];
    }
}
