<?php

/** needsotp profile: requires OTP for number registrations. */

use SmsMockServer\Provider\CellcastProfile;

CellcastProfile::register('needsotp', function (CellcastProfile $p) {
    $p->requireOTPForNumbers('654321');
});
