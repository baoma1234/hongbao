<?php
// Finalize: verify copy encoding + gap summary
$root = dirname(__DIR__);
$c = include $root . '/application/extra/fanshub_h5_copy.php';
$checks = ['swap_title', 'chat_scan', 'profile_recharge_title', 'wallet_ledger_empty', 'chat_rp_submit'];
foreach ($checks as $k) {
    $v = isset($c[$k]) ? $c[$k] : 'MISSING';
    $ok = preg_match('/[\x{4e00}-\x{9fff}]/u', $v) ? 'CN_OK' : 'NO_CN';
    echo "$k=$ok:$v\n";
}
require $root . '/scripts/scan_h5_i18n_true_gaps.php';
