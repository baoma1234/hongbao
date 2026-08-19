<?php
$en = array_merge(
    include __DIR__ . '/en_a.php',
    include __DIR__ . '/en_b.php',
    include __DIR__ . '/en_c.php',
    include __DIR__ . '/en_d.php'
);
$zh = json_decode(file_get_contents(dirname(__DIR__) . '/_zh_keys.json'), true);
$miss = [];
foreach ($zh as $k => $v) {
    if (!isset($en[$k])) $miss[] = $k;
}
$extra = [
    'fission_home_entry_title','fission_home_entry_sub_active','fission_home_entry_sub_ended_drawn',
    'fission_home_entry_sub_ended_void','fission_home_entry_go','fission_home_entry_ended',
    'profile_payee_bound','profile_payee_address_label','profile_payee_address_ph','profile_payee_name_optional',
    'profile_payee_optional_ph','profile_payee_bind_btn','profile_payee_update_btn','profile_payee_submitting',
    'profile_payee_usdt_trc20_only','profile_payee_addr_with_chain','profile_payee_bound_prefix',
];
$extraMiss = [];
foreach ($extra as $k) {
    if (!isset($en[$k])) $extraMiss[] = $k;
}
echo 'en=' . count($en) . ' zh=' . count($zh) . ' miss=' . count($miss) . ' extraMiss=' . count($extraMiss) . PHP_EOL;
if ($miss) echo 'MISS: ' . implode(', ', $miss) . PHP_EOL;
if ($extraMiss) echo 'EXTRA MISS: ' . implode(', ', $extraMiss) . PHP_EOL;
$han = 0;
foreach ($en as $k => $v) {
    $c = str_replace('红宝', '', $v);
    if (preg_match('/\p{Han}/u', $c)) {
        $han++;
        if ($han <= 15) echo "han:$k => $v\n";
    }
}
echo "leftover_han=$han\n";
