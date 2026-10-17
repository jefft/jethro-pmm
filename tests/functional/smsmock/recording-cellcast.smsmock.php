<?php
/**
 * recording-cellcast profile: the mock side of smsmockserver/recordings/cellcast.
 * Mirrors the state of the account the live recording came from — one active
 * custom number, one ACMA-approved business name, no opt-outs — with made-up
 * values, so the normalised replies compare equal.
 */
use SmsMockServer\Provider\CellcastProfile;
CellcastProfile::register('recording-cellcast', function (CellcastProfile $p) {
    $p->setSenders([['number' => '61491570158', 'name' => 'Mock User']]);
    $p->setBusinessNames(['MockChurch']);
    $p->setBalance(248.7);
});
