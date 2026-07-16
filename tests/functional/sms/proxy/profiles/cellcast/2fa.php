<?php

/** 2fa profile: requires OTP for number registrations. */

use Mocksmsproxy\Provider\CellcastProfile;

CellcastProfile::register('2fa', function (CellcastProfile $p) {
    $p->requireOTPForNumbers('123456');
});
