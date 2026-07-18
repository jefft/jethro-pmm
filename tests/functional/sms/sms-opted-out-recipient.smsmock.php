<?php

/** williamson-optout profile: sets up opt-out entries for Williamson family. */

use SmsMockServer\Provider\CellcastProfile;

CellcastProfile::register('sms-opted-out-recipient', function (CellcastProfile $p) {
    $p->setOptOuts([
        [
            'number' => '61491570157',
            'first_name' => 'Jamison',
            'last_name' => 'Williamson',
            'full_name' => 'Jamison Williamson',
        ],
    ]);
});
