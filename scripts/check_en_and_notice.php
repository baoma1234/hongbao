<?php
$root = dirname(__DIR__);
$js = file_get_contents($root . '/public/888/i18n/locales/en-PH.js');
preg_match('/FANSHUB_LOCALES\["en-PH"\]\s*=\s*(\{.*\})\s*;?\s*$/s', $js, $m);
$d = json_decode($m[1], true);
echo 'en_keys=' . count($d) . "\n";
foreach (['footer_line1', 'swap_title', 'chat_scan', 'uid_label', 'chat_tab_commission', 'page_hero_master_title', 'brand_name', 'login_submit_btn'] as $k) {
    $v = $d[$k] ?? 'MISS';
    $isCn = preg_match('/[\x{4e00}-\x{9fff}]/u', $v);
    echo ($isCn ? 'CN ' : 'EN ') . "$k=$v\n";
}

// compare with i18n php source
$enFile = $root . '/application/extra/i18n/en-PH.php';
if (is_file($enFile)) {
    $en = include $enFile;
    echo "\nen-PH.php keys=" . count($en) . "\n";
    foreach (['footer_line1', 'swap_title', 'uid_label', 'brand_name'] as $k) {
        echo "src $k=" . (isset($en[$k]) ? $en[$k] : 'MISS') . "\n";
    }
}

// notice.js line bytes
$lines = file($root . '/public/888/js/chat/06-notice.js');
foreach ([369, 370, 379, 412, 416] as $n) {
    $line = $lines[$n - 1] ?? '';
    echo "\nL$n hex=" . bin2hex(substr(trim($line), 0, 80)) . "\n";
    echo "L$n txt=" . trim($line) . "\n";
}
