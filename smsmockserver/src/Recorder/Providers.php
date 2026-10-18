<?php

namespace SmsMockServer\Recorder;

/**
 * Every recordable provider: its cases class, the mock profile that mirrors
 * the live account (shipped in smsmockserver/profiles/), and the credentials
 * the mock accepts (it does not validate them).
 */
final class Providers
{
    /**
     * @return array<string, array{cases: class-string<ProviderCases>, profile: string, mock_env: array<string, string>}>
     */
    public static function all(): array
    {
        return [
            'cellcast' => [
                'cases' => CellcastCases::class,
                'profile' => 'recording-cellcast',
                'mock_env' => [
                    'CELLCAST_APIKEY' => 'mock',
                    'CELLCAST_TEST_NUMBER' => '0491570158',
                    'CELLCAST_TEST_SENDER' => '61491570158',
                ],
            ],
            '5centsmsv5' => [
                'cases' => FiveCentSmsCases::class,
                'profile' => 'recording-5centsmsv5',
                'mock_env' => [
                    'FIVECENTSMS_APIKEY_ID' => 'mock',
                    'FIVECENTSMS_APIKEY' => 'mock',
                    'FIVECENTSMS_TEST_NUMBER' => '0491570158',
                    'FIVECENTSMS_TEST_SENDER' => '61491570158',
                ],
            ],
            'mobilemessage' => [
                'cases' => MobileMessageCases::class,
                'profile' => 'recording-mobilemessage',
                'mock_env' => [
                    'MOBILEMESSAGE_USERNAME' => 'mock',
                    'MOBILEMESSAGE_PASSWORD' => 'mock',
                    'MOBILEMESSAGE_TEST_NUMBER' => '0491570158',
                ],
            ],
        ];
    }
}
