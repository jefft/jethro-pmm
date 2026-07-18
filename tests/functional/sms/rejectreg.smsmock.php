<?php

/** rejectreg profile: rejects all sender number registrations. */

use SmsMockServer\Provider\CellcastProfile;

CellcastProfile::register('rejectreg', function (CellcastProfile $p) {
    $p->rejectRegistrations('Registration rejected by carrier');
});
