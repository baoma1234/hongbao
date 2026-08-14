<?php
$root = dirname(__DIR__);
// How many keys in locale vs copy
$c = include $root . '/application/extra/fanshub_h5_copy.php';
$js = file_get_contents($root . '/public/888/i18n/locales/zh-CN.js');
if (!preg_match('/=\{(.+)\};?\s*$/s', $js, $m)) {
    $json = preg_replace('/^window\.FANSHUB_LOCALES\[[^\]]+\]=/', '', trim($js));
    $json = rtrim($json, "; \r\n");
} else {
    $json = '{' . $m[1] . '}';
}
// Better parse
$json = preg_replace('/^window\.FANSHUB_LOCALES\["zh-CN"\]\s*=\s*/', '', trim($js));
$json = rtrim($json, "; \r\n");
$loc = json_decode($json, true);
echo 'copy=' . count($c) . ' locale_zh=' . (is_array($loc) ? count($loc) : 0) . ' json_err=' . json_last_error_msg() . "\n";
if (is_array($loc)) {
    echo 'locale footer1=' . ($loc['footer_line1'] ?? 'MISS') . "\n";
    echo 'locale swap_title=' . ($loc['swap_title'] ?? 'MISS') . "\n";
    echo 'locale chat_scan=' . ($loc['chat_scan'] ?? 'MISS') . "\n";
}

// fanshub.php size
$cfg = include $root . '/application/extra/fanshub.php';
$hc = $cfg['h5_copy'] ?? [];
echo 'cfg_h5_copy=' . count($hc) . "\n";
echo 'cfg has swap_title=' . (isset($hc['swap_title']) ? '1' : '0') . "\n";
echo 'cfg has chat_scan=' . (isset($hc['chat_scan']) ? '1' : '0') . "\n";
