<?php

/** schedule profile: no overrides, just enables scheduling support. */

use SmsMockServer\Provider\CellcastProfile;

CellcastProfile::register('schedule', function (CellcastProfile $p) {
    // No overrides — just activate the profile for scheduling tests
});
