<?php
/** sms-bulk profile: default Cellcast behaviour (no overrides). */
use SmsMockServer\Provider\CellcastProfile;
CellcastProfile::register('sms-bulk', function (CellcastProfile $p) { });
