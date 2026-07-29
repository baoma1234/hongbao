<?php
$js = file_get_contents(dirname(__DIR__) . '/public/888/i18n/locales/en-PH.js');
preg_match('/FANSHUB_LOCALES\[[^\]]+\]=(\{.*\});/s', $js, $m);
$data = json_decode($m[1], true);
foreach (['phase2_honor_title','phase2_checkin_streak','phase2_radar_title','phase2_radar_urge','master_lock_title','phase2_honor_name_1'] as $k) {
    $v = $data[$k] ?? 'MISS';
    $flag = preg_match('/[\x{4e00}-\x{9fff}]/u', $v) ? 'ZH' : 'OK';
    echo "$k|$flag|$v\n";
}
// sanity check radar function syntax-ish
$core = file_get_contents(dirname(__DIR__) . '/public/888/js/app-core.js');
echo 'urge_fn=' . (strpos($core, 'async function urgeVirtualTeammate') !== false ? '1' : '0') . "\n";
echo 'urge_win=' . (strpos($core, 'window.urgeVirtualTeammate') !== false ? '1' : '0') . "\n";
