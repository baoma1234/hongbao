<?php
require __DIR__ . '/../im-server/vendor/autoload.php';
require __DIR__ . '/../im-server/App/Support/FairProof.php';

use Im\Support\FairProof;

$cents = [120, 340, 560, 80, 200];
$fair = FairProof::create('RPTEST001', 2, array_sum($cents), 5, 0, $cents);
$ok = FairProof::verifyPayload($fair['fair_payload'], $fair['fair_hash']);
echo 'hash_ok=' . ($ok ? '1' : '0') . PHP_EOL;
echo 'hash=' . $fair['fair_hash'] . PHP_EOL;
