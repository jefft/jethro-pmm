<?php
/** sms-per-recipient-override profile: default Cellcast behaviour (no overrides). */
use SmsMockServer\Provider\CellcastProfile;
CellcastProfile::register('sms-per-recipient-override', function (CellcastProfile $p) { });
