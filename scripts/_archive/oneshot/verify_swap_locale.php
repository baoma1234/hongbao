<?php
$js = file_get_contents(dirname(__DIR__) . '/public/888/i18n/locales/en-PH.js');
if (!preg_match('/FANSHUB_LOCALES\[[^\]]+\]=(\{.*\});/s', $js, $m)) {
    echo "PARSE_FAIL\n";
    exit(1);
}
$data = json_decode($m[1], true);
echo 'total=' . count($data) . "\n";
foreach (['swap_title_pair', 'swap_avail_balance', 'swap_submit', 'swap_from_with_asset', 'profile_ex_max_hint', 'swap_pair_closed', 'swap_rate_line'] as $k) {
    $v = $data[$k] ?? 'MISS';
    $flag = preg_match('/[\x{4e00}-\x{9fff}]/u', $v) ? 'ZH' : 'OK';
    echo "$k|$flag|$v\n";
}
$zhJs = file_get_contents(dirname(__DIR__) . '/public/888/i18n/locales/zh-CN.js');
preg_match('/FANSHUB_LOCALES\[[^\]]+\]=(\{.*\});/s', $zhJs, $m2);
$zhData = json_decode($m2[1], true);
echo 'zh_total=' . count($zhData) . ' zh_pair=' . ($zhData['swap_title_pair'] ?? 'MISS') . "\n";
