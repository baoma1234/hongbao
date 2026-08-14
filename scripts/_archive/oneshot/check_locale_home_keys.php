<?php
$keys = ['uid_label','uid_submit_btn','uid_placeholder','uid_hint_idle','settle_title_low','settle_title_high','settle_sub_low','settle_sub_high','footer_line1','footer_line2','footer_line3','marquee_text','marquee_fallback','page_hero_claim_title','leaderboard_title'];
$dir = dirname(__DIR__) . '/application/extra/i18n';
$files = ['en-PH.php','id-ID.php','vi-VN.php','ms-MY.php','km-KH.php'];
foreach ($files as $f) {
    $data = include $dir . '/' . $f;
    $miss = [];
    $zhish = [];
    foreach ($keys as $k) {
        if (!isset($data[$k]) || $data[$k] === '') {
            $miss[] = $k;
            continue;
        }
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $data[$k])) {
            $zhish[] = $k;
        }
    }
    echo $f . ' count=' . count($data) . ' miss=' . implode(',', $miss) . ' still_zh=' . implode(',', $zhish) . "\n";
}
