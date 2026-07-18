<?php

/** pendingid profile: leaves sender ID registrations in pending state indefinitely. */

use SmsMockServer\Provider\FiveCentSmsProfile;
use SmsMockServer\PendingRegistration;

FiveCentSmsProfile::register('pendingid', function (FiveCentSmsProfile $p) {
    $p->profile->onRegisterHook = function (PendingRegistration $pr) {
        $pr->pendingIndefinite();
    };
});
