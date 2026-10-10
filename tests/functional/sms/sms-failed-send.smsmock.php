<?php

/** send-fail profile: sets balance to 0 and rejects all sends. */

use SmsMockServer\Provider\CellcastProfile;

CellcastProfile::register('sms-failed-send', function (CellcastProfile $p) {
    // demo's own mobile, which these tests send from (_USER_MOBILE_): already
    // registered on the account, as Jethro now requires (DbLoggingSmsProvider::verifySenderNumber()).
    $p->preRegisterNumbers(['61491570157']);
    $p->setBalance(0);
    $p->rejectAllSends('Insufficient credits');
});
