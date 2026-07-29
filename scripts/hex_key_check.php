<?php
$c = include dirname(__DIR__) . '/application/extra/fanshub_h5_copy.php';
foreach (['master_lock_title','master_lock_desc','page_hero_exchange_title','page_hero_master_sub','page_hero_claim_sub'] as $k) {
    $v = $c[$k] ?? '';
    echo "== $k ==\n$v\nhex=" . bin2hex($v) . "\n\n";
}
