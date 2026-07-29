<?php
$root = dirname(__DIR__);
$j = json_decode(file_get_contents($root . '/scripts/_i18n_gaps.json'), true);
$lines = [];
foreach ($j as $f => $gaps) {
    $lines[] = '## ' . $f . ' (' . count($gaps) . ')';
    foreach ($gaps as $g) {
        $lines[] = '  [' . $g['kind'] . '] ' . $g['text'];
    }
}
file_put_contents($root . '/scripts/_i18n_gaps_utf8.txt', implode("\n", $lines));

$copy = include $root . '/application/extra/fanshub_h5_copy.php';
$chatKeys = [];
foreach ($copy as $k => $v) {
    if (strpos($k, 'chat_') === 0 || strpos($k, 'profile_') === 0 || strpos($k, 'ex_') === 0 || strpos($k, 'exchange_') === 0 || strpos($k, 'wallet_') === 0 || strpos($k, 'ledger_') === 0) {
        $chatKeys[$k] = $v;
    }
}
file_put_contents($root . '/scripts/_related_keys.json', json_encode($chatKeys, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo 'gaps_files=' . count($j) . "\n";
echo 'related_keys=' . count($chatKeys) . "\n";
echo 'total_copy=' . count($copy) . "\n";
