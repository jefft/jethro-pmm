<?php

/** send-fail profile: sets balance to 0 and rejects all sends. */

use SmsMockServer\Provider\CellcastProfile;

CellcastProfile::register('sms-failed-send', function (CellcastProfile $p) {
    $p->setBalance(0);
    $p->rejectAllSends('Insufficient credits');
});
