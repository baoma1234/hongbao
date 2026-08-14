<?php
$js = file_get_contents(dirname(__DIR__) . '/public/888/i18n/locales/en-PH.js');
preg_match('/FANSHUB_LOCALES\["en-PH"\]\s*=\s*(\{.*\})\s*;?\s*$/s', $js, $m);
$d = json_decode($m[1], true);
foreach (['uid_label','uid_submit_btn','footer_line1','footer_line2','settle_title_low','marquee_text','marquee_fallback'] as $k) {
    $v = $d[$k] ?? 'MISS';
    $isEn = !preg_match('/[\x{4e00}-\x{9fff}]/u', $v) || preg_match('/Step 1|Submit account|Safety pledge|Apply for VIP|Core team|Welcome to the official/u', $v);
    echo ($isEn ? 'EN ' : 'ZH ') . "$k=" . mb_substr($v, 0, 70) . "\n";
}

// verify skip list no longer has manualSettle
$core = file_get_contents(dirname(__DIR__) . '/public/888/js/app-core.js');
echo 'skip_manual=' . (strpos($core, "manualSettleTitle") !== false ? 'still' : 'removed') . "\n";
echo 'hook_settle=' . (strpos($core, 'updateManualSettleButton') !== false ? 'yes' : 'no') . "\n";

$idx = file_get_contents(dirname(__DIR__) . '/public/888/index.php');
preg_match('/\$assetVer\s*=\s*\'([^\']+)\'/', $idx, $vm);
echo 'assetVer=' . ($vm[1] ?? '?') . "\n";

// bump if needed
if (($vm[1] ?? '') !== '202607251920') {
    $idx = preg_replace('/\$assetVer\s*=\s*\'[^\']+\'/', "\$assetVer = '202607251920'", $idx, 1);
    file_put_contents(dirname(__DIR__) . '/public/888/index.php', $idx);
    echo "bumped_to_202607251920\n";
}
