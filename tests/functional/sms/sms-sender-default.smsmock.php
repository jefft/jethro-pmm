<?php
/** sms-sender-default profile: default Cellcast behaviour (no overrides). */
use SmsMockServer\Provider\CellcastProfile;
CellcastProfile::register('sms-sender-default', function (CellcastProfile $p) { });
