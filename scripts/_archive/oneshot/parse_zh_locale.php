<?php
$js = file_get_contents(dirname(__DIR__) . '/public/888/i18n/locales/zh-CN.js');
$js = trim($js);
if (!preg_match('/FANSHUB_LOCALES\["zh-CN"\]\s*=\s*(\{.*\})\s*;?\s*$/s', $js, $m)) {
    echo "regex_fail\n";
    echo substr($js, 0, 100) . "\n";
    exit(1);
}
$data = json_decode($m[1], true);
echo 'keys=' . (is_array($data) ? count($data) : 0) . ' err=' . json_last_error_msg() . "\n";
if ($data) {
    foreach (['footer_line1', 'swap_title', 'chat_scan', 'uid_label', 'page_hero_master_title'] as $k) {
        echo $k . '=' . (isset($data[$k]) ? $data[$k] : 'MISS') . "\n";
    }
}

// How does app merge server h5_copy?
$core = file_get_contents(dirname(__DIR__) . '/public/888/js/app-core.js');
preg_match_all('/h5_copy|FANSHUB_COPY_DEFAULTS|function syncCopy|Object\.assign/', $core, $mm);
echo "\n--- related lines ---\n";
$lines = explode("\n", $core);
foreach ($lines as $i => $line) {
    if (preg_match('/h5_copy|FANSHUB_COPY_DEFAULTS|syncCopyFrom|mergeH5|applyPageCopy|COPY\s*=/', $line)) {
        echo ($i + 1) . ': ' . trim($line) . "\n";
    }
}
