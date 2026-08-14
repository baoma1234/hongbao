<?php
$en = include dirname(__DIR__) . '/application/extra/i18n/en-PH.php';
$c = include dirname(__DIR__) . '/application/extra/fanshub_h5_copy.php';
$keys = ['uid_label','uid_placeholder','uid_submit_btn','uid_hint_idle','settle_title_low','settle_sub_low','settle_title_high','footer_line1','footer_line2','footer_line3','marquee_text','marquee_fallback','marquee_fallback_prefix','page_hero_claim_title','page_hero_claim_sub','uid_submit_pending','uid_submit_approved'];
echo "=== EN ===\n";
foreach ($keys as $k) {
    echo $k . '=' . (isset($en[$k]) ? $en[$k] : 'MISS') . "\n";
}
echo "=== ZH ===\n";
foreach ($keys as $k) {
    echo $k . '=' . (isset($c[$k]) ? $c[$k] : 'MISS') . "\n";
}

// dump applyPageCopy skip + updateDynamicCopy settle/footer
$core = file(dirname(__DIR__) . '/public/888/js/app-core.js');
echo "\n=== applyPageCopy skip line ===\n";
foreach ($core as $i => $line) {
    if (strpos($line, 'manualSettleTitle') !== false || strpos($line, 'function updateDynamicCopy') !== false || strpos($line, 'footer_line') !== false || strpos($line, 'uid_label') !== false) {
        echo ($i + 1) . ':' . rtrim($line) . "\n";
    }
}
$boot = file(dirname(__DIR__) . '/public/888/js/app-boot.js');
echo "\n=== app-boot marquee/settle ===\n";
foreach ($boot as $i => $line) {
    if (preg_match('/marquee|manualSettle|uid_submit|footer_line|updateUid|settle_title/', $line)) {
        echo ($i + 1) . ':' . rtrim($line) . "\n";
    }
}
