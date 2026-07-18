<?php

/** cooloff profile: approves registrations instantly. */

use SmsMockServer\Provider\CellcastProfile;

CellcastProfile::register('cooloff', function (CellcastProfile $p) {
    $p->approveInstantly();
});
