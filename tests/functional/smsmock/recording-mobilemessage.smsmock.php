<?php
/**
 * recording-mobilemessage profile: the mock side of
 * smsmockserver/recordings/mobilemessage. Mirrors the state of the account
 * the live recordings came from — the shared default number plus one own
 * number, no Sender IDs, no unsubscribes — so list replies compare equal.
 */
use SmsMockServer\Provider\MobileMessageProfile;
MobileMessageProfile::register('recording-mobilemessage', function (MobileMessageProfile $p) {
    $p->preRegisterNumbers(['61491570157']);
});
