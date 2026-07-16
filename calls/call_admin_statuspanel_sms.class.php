<?php

/**
 * Status panel for the SMS Gateway section of the system configuration page.
 * Fetched via AJAX from ?call=admin_statuspanel_sms.
 */

require_once __DIR__ . '/call_admin_statuspanel.class.php';

class Call_Admin_Statuspanel_Sms extends Call_Admin_Statuspanel
{
    public function linkedFeature(): ?string
    {
        return 'SMS';
    }

    protected function isConfigured(): \Result
    {
        require_once JETHRO_ROOT . '/include/jethro_sms.php';
        $result = \Jethro\Sms\getSmsProvider();
        if ($result->isFailure()) {
            return $result;
        }
        return \Result::success(true);
    }

    protected function getHelpText(): string
    {
        return 'An external SMS provider may be configured to allow staff to send SMS messages.';
    }

    protected function getStatus(): array
    {
        $details = [];
        $testModeSuffix = filter_var(ifdef('SMS_TESTMODE', false), FILTER_VALIDATE_BOOLEAN)
            ? ' — test mode (no real SMSes sent)'
            : '';

        $providerResult = \Jethro\Sms\getSmsProvider();
        if ($providerResult->isFailure()) {
            return [
                'success' => false,
                'message' => 'SMS gateway is not available.',
                'details' => ['Error' => $providerResult->getError()],
            ];
        }

        /** @var \Sms\SmsProvider $provider */
        $provider = $providerResult->getValue();
        $details['Provider'] = $provider->getDescription();

        $operationalResult = $provider->isOperational();
        if ($operationalResult->isFailure() || $operationalResult->getValue() !== true) {
            $reason = $operationalResult->isFailure()
                ? $operationalResult->getError()
                : 'Provider did not respond';
            return [
                'success' => false,
                'message' => 'SMS gateway is not reachable — ' . $reason,
                'details' => ['Provider' => $provider->getDescription()],
            ];
        }

        $message = 'Operational' . $testModeSuffix;

        if ($provider->hasCapability(\Sms\SmsCapability::GET_BALANCE)) {
            $balance = \Jethro\Sms\getSmsBalance();
            if ($balance !== null) {
                $message = 'Operational' . $testModeSuffix . ', ' . $balance . ' SMSes remaining';
                $details['Credits remaining'] = (string) $balance;
            } else {
                $details['Credits remaining'] = 'Unknown';
            }
        } else {
            $details['Credits remaining'] = '(not supported by provider)';
        }

        if ($provider->hasCapability(\Sms\SmsCapability::GET_SENDER_IDS)) {
            $senderIdsResult = $provider->getSenderIds(getAll: true);
            if ($senderIdsResult->isSuccess()) {
                $senderIds = $senderIdsResult->getValue();
                if ($senderIds !== []) {
                    $approved = [];
                    $notApproved = [];
                    $unknown = [];
                    foreach ($senderIds as $s) {
                        $value = $s instanceof \Sms\SenderID ? $s->value : (string) $s;
                        if ($s instanceof \Sms\SenderID && $s->acmaApproved === true) {
                            $approved[] = $value;
                        } elseif ($s instanceof \Sms\SenderID && $s->acmaApproved === false) {
                            $notApproved[] = $value;
                        } else {
                            $unknown[] = $value;
                        }
                    }
                    if ($approved !== []) {
                        $details['Sender IDs'] = implode(', ', $approved);
                    }
                    if ($notApproved !== []) {
                        $details['Sender IDs (not approved)'] = implode(', ', $notApproved);
                    }
                    if ($unknown !== []) {
                        $details['Sender IDs (ACMA status unknown)'] = implode(', ', $unknown);
                    }
                    if ($approved === [] && $notApproved === [] && $unknown === []) {
                        $details['Sender IDs'] = 'None returned';
                    }
                } else {
                    $details['Sender IDs'] = 'None returned';
                }
            } else {
                $details['Sender IDs'] = 'Error: ' . $senderIdsResult->getError();
            }
        } else {
            $details['Sender IDs'] = '(not supported by provider)';
        }

        $senderNumbersResult = $provider->getSenderNumbers();
        if ($senderNumbersResult->isSuccess()) {
            $numbers = $senderNumbersResult->getValue();
            if ($numbers !== []) {
                $details['Sender Numbers (registered)'] = implode(', ', array_map(
                    static fn (\Sms\PhoneNumber $n) => $n->value,
                    $numbers,
                ));
            } else {
                $details['Sender Numbers (registered)'] = 'None returned';
            }
        } else {
            $details['Sender Numbers (registered)'] = 'Error: ' . $senderNumbersResult->getError();
        }

        return [
            'success' => true,
            'message' => $message,
            'details' => $details,
        ];
    }

    /** @return array<string, string>  method => label */
    protected function getOperations(): array
    {
        $providerResult = \Jethro\Sms\getSmsProvider();
        if ($providerResult->isFailure()) {
            return [];
        }

        $provider = $providerResult->getValue();
        $ops = [];

        if ($provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_ID)) {
            $ops['registerSenderId'] = 'Register Sender ID';
        }


        // Sync history: direct link (not a provider method — bridge-level operation)
        $ops['synchronizeHistory'] = 'Synchronize History';

        return $ops;
    }
}
