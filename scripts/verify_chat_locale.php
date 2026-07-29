<?php
$js = file_get_contents(dirname(__DIR__) . '/public/888/i18n/locales/en-PH.js');
if (!preg_match('/FANSHUB_LOCALES\[[^\]]+\]=(\{.*\});/s', $js, $m)) {
    echo "PARSE_FAIL\n";
    exit(1);
}
$data = json_decode($m[1], true);
$keys = [
    'chat_community_title','chat_conn_ok','chat_conn_off','chat_conn_connecting','chat_my_id',
    'chat_tab_chat','chat_tab_community','chat_tab_notice','chat_tab_commission',
    'chat_community_official','chat_my_groups','chat_friend_list',
    'chat_commission_total','chat_commission_withdraw_btn','chat_notice_latest'
];
echo 'total=' . count($data) . "\n";
foreach ($keys as $k) {
    $v = $data[$k] ?? 'MISS';
    $flag = preg_match('/[\x{4e00}-\x{9fff}]/u', $v) ? 'ZH' : 'OK';
    echo "$k|$flag|$v\n";
}
$zh = file_get_contents(dirname(__DIR__) . '/public/888/i18n/locales/zh-CN.js');
preg_match('/FANSHUB_LOCALES\[[^\]]+\]=(\{.*\});/s', $zh, $m2);
$zd = json_decode($m2[1], true);
echo 'zh_connecting=' . ($zd['chat_conn_connecting'] ?? 'MISS') . "\n";
echo 'zh_total=' . count($zd) . "\n";

$core = file_get_contents(dirname(__DIR__) . '/public/888/js/chat/01-core.js');
$net = file_get_contents(dirname(__DIR__) . '/public/888/js/chat/04-net.js');
echo 'chatT_fc=' . (strpos($core, 'Prefer fc/COPY') !== false ? '1' : '0') . "\n";
echo 'net_hardcoded_zh=' . (preg_match('/setConnStatus\(\'[^\']*[\x{4e00}-\x{9fff}]/u', $net) ? 'YES' : 'no') . "\n";
