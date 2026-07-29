<?php
$js = file_get_contents(dirname(__DIR__) . '/public/888/i18n/locales/zh-CN.js');
if (!preg_match('/FANSHUB_LOCALES\["zh-CN"\]=(\{.*\});?\s*$/s', $js, $m)
    && !preg_match('/=\{(.*)\};?\s*$/s', $js, $m2)) {
    // strip prefix
    $json = preg_replace('/^window\.FANSHUB_LOCALES\["zh-CN"\]=/', '', trim($js));
    $json = rtrim($json, "; \r\n");
} else {
    $json = $m[1] ?? ('{' . ($m2[1] ?? '') . '}');
}
$data = json_decode($json, true);
if (!$data) {
    // try bundle
    $js = file_get_contents(dirname(__DIR__) . '/public/888/copy.defaults.js');
    $json = preg_replace('/^window\.FANSHUB_COPY_DEFAULTS=/', '', trim($js));
    $json = rtrim($json, "; \r\n");
    $data = json_decode($json, true);
}
if (!$data) {
    fwrite(STDERR, "parse fail\n");
    exit(1);
}
$keys = [
    'tab_bar_home','tab_bar_exchange','tab_bar_claim','tab_bar_messages','tab_bar_master','tab_bar_profile',
    'home_quick_exchange','home_quick_claim','home_quick_master','home_quick_messages','home_quick_profile',
    'home_quick_exchange_sub','home_quick_claim_sub','home_quick_master_sub','home_quick_messages_sub','home_quick_profile_sub',
];
foreach ($keys as $k) {
    echo $k . '=' . ($data[$k] ?? '(missing)') . "\n";
}
