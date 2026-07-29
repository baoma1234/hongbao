<?php
$en = include dirname(__DIR__) . '/application/extra/i18n/en-PH.php';
$keys = ['uid_label','uid_hint_approved','uid_hint_rejected','leaderboard_title','footer_line1','settle_title_low','marquee_text','home_quick_messages'];
foreach ($keys as $k) {
    echo $k . '=' . (isset($en[$k]) ? $en[$k] : 'MISS') . "\n";
}
echo 'count=' . count($en) . "\n";
