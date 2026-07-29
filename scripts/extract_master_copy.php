<?php
$js = file_get_contents(dirname(__DIR__) . '/public/888/copy.defaults.js');
$json = preg_replace('/^window\.FANSHUB_COPY_DEFAULTS=/', '', trim($js));
$json = rtrim($json, "; \r\n");
$data = json_decode($json, true);
if (!$data) {
    fwrite(STDERR, "json err: " . json_last_error_msg() . "\n");
    exit(1);
}
$keys = [
    'page_hero_master_title','page_hero_master_sub',
    'master_lock_title','master_lock_desc','master_lock_btn',
    'phase2_honor_title','phase2_checkin_streak','phase2_checkin_ledger',
    'phase2_checkin_toggle','phase2_radar_title','phase2_checkin_violent_btn',
    'loading_generic',
];
foreach ($keys as $k) {
    echo $k . '=' . ($data[$k] ?? '(missing)') . "\n";
}
