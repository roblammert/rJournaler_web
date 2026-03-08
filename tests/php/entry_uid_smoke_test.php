<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';

use App\Entry\EntryUid;

$uid = EntryUid::generate('rjournaler', 'W010000');
if (!EntryUid::isValid($uid)) {
    fwrite(STDERR, "Generated UID is invalid: {$uid}\n");
    exit(1);
}

if (!EntryUid::isValidAppVersionCode('T031502')) {
    fwrite(STDERR, "Expected app version code to be valid.\n");
    exit(1);
}

if (EntryUid::isValidAppVersionCode('BADVALUE')) {
    fwrite(STDERR, "Invalid app version code unexpectedly validated.\n");
    exit(1);
}

fwrite(STDOUT, "entry_uid_smoke_test passed\n");
