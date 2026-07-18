<?php
/** sms-send-single profile: default Cellcast behaviour (no overrides). */
use SmsMockServer\Provider\CellcastProfile;
CellcastProfile::register('sms-send-single', function (CellcastProfile $p) { });
