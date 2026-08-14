<?php
$root = dirname(__DIR__);
$c = include $root . '/application/extra/fanshub_h5_copy.php';
$keys = [
    'footer_line1','footer_line2','footer_line3',
    'uid_label','uid_placeholder','uid_submit_btn','uid_hint_idle','uid_hint_pending','uid_hint_ok','uid_hint_fail',
    'open_account_btn','open_account_badge_fallback','settle_title_low','settle_sub_low',
    'swap_title','swap_avail','swap_submit','swap_to_label','swap_rate_label',
    'page_hero_master_title','page_hero_master_sub','page_hero_exchange_title',
    'chat_community_title','chat_tab_chat','chat_tab_community','chat_tab_notice','chat_tab_commission',
    'profile_vip_badge','profile_section_asset','jackpot_label','jackpot_hint',
];
foreach ($keys as $k) {
    if (!isset($c[$k])) { echo "MISSING $k\n"; continue; }
    $v = $c[$k];
    $bad = (preg_match('/�|鐢|閸|鍙|馃|棣|闂/u', $v) || strpos($v, "\xEF\xBF\xBD") !== false);
    echo ($bad ? 'BAD ' : 'OK  ') . "$k = " . $v . "\n";
}

echo "\n--- home uid-section snippet ---\n";
$home = file_get_contents($root . '/public/888/partials/tab-home.php');
if (preg_match('/uid-section.*?<\/section>/s', $home, $m) || preg_match('/class="[^"]*uid[^"]*".{0,2000}/us', $home, $m2)) {
    // extract around uid
}
if (preg_match('/id="uidSection".*?id="manualSettleCard".{0,800}/s', $home, $m)) {
    echo $m[0];
} else {
    // find match-card near uid
    $pos = strpos($home, 'uid-');
    echo substr($home, max(0, $pos - 100), 2500);
}

echo "\n\n--- master partial head ---\n";
$master = file_get_contents($root . '/public/888/partials/tab-master.php');
echo substr($master, 0, 2000);

echo "\n\n--- exchange share-swap ---\n";
$ex = file_get_contents($root . '/public/888/partials/tab-exchange.php');
echo $ex;
