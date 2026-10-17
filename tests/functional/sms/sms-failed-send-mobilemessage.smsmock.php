<?php
/** sms-failed-send-mobilemessage profile: balance 0 and every send rejected. */
use SmsMockServer\Provider\MobileMessageProfile;
MobileMessageProfile::register('sms-failed-send-mobilemessage', function (MobileMessageProfile $p) {
    // demo's own mobile, which this test sends from (_USER_MOBILE_): already
    // verified on the account, as Jethro requires (DbLoggingSmsProvider::verifySenderNumber()).
    $p->preRegisterNumbers(['61491570157']);
    $p->setBalance(0);
    $p->rejectAllSends('Insufficient credits');
});
