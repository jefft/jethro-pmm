<?php
/**
 * recording-5centsmsv5 profile: the mock side of
 * smsmockserver/recordings/5centsmsv5. Mirrors the state of the account the
 * live recording came from — the distinct sender-ID shapes it lists
 * (pending, unverified, approved, and an ACMA-approved alpha ID), and no
 * unsubscribes — with made-up numbers, so the normalised replies compare
 * equal without recording anyone's real numbers.
 */
use SmsMockServer\Provider\FiveCentSmsProfile;
FiveCentSmsProfile::register('recording-5centsmsv5', function (FiveCentSmsProfile $p) {
    $p->setSenders([
        ['senderid' => '61400000001', 'status' => 'pending'],
        ['senderid' => '61400000002', 'status' => 'unverified'],
        ['senderid' => '61400000003', 'status' => 'approved'],
        ['senderid' => '61400000004', 'status' => 'unverified'],
        [
            'senderid' => 'MockChurch',
            'status' => 'acma_approved',
            'acma_details_id' => '000000000000000000000000',
            'acma_additional_details' => 'Mock Church = mock',
        ],
        ['senderid' => '61400000005', 'status' => 'approved'],
    ]);
});
