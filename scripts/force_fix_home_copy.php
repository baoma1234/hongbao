<?php
$root = dirname(__DIR__);
$home = file_get_contents($root . '/public/888/partials/tab-home.php');
$copy = include $root . '/application/extra/fanshub_h5_copy.php';

// Find remaining mojibake lines
if (preg_match_all('/^.*(?:鍏|褰撳|鑲′|鏈|鐢熸|浜掑|馃搳|馃挵|馃挰).*$/mu', $home, $m)) {
    echo "remaining_moji_lines=" . count($m[0]) . "\n";
    foreach ($m[0] as $line) {
        echo trim($line) . "\n---\n";
    }
}

// Force-fix footer / jackpot / share by direct key rewrite if still bad
$keysForce = ['footer_line1','footer_line2','footer_line3','jackpot_label','jackpot_pool_label','jackpot_hint','share_promo_btn','share_promo_action_btn','page_hero_social_title','page_hero_social_sub','stepper_1','stepper_2','stepper_3','stepper_4','stepper_5','user_account_status','user_status_shield','user_rank_default'];
foreach ($keysForce as $key) {
    if (!isset($copy[$key])) continue;
    $val = $copy[$key];
    $home = preg_replace(
        '/(<[^>]*\bdata-copy="' . preg_quote($key, '/') . '"[^>]*>)(.*?)(<\/[^>]+>)/us',
        '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $val) . '$3',
        $home,
        1
    );
}

// Fix broken asset unit span if truncated
$home = preg_replace(
    '/(<span class="asset-unit" data-copy="asset_rights_unit">)(.*?)(<\/span>)/u',
    '$1' . $copy['asset_rights_unit'] . '$3',
    $home,
    1
);

file_put_contents($root . '/public/888/partials/tab-home.php', $home);
echo "home_force_done moji_left=" . preg_match_all('/鍏|褰撳|鑲′|鏈|鐢熸/u', $home) . "\n";

// Verify footer bytes contain 【 = e3 80 90
if (preg_match('/data-copy="footer_line1"[^>]*>([^<]*)</u', $home, $m)) {
    echo "has_bracket=" . (strpos($m[1], '【') !== false ? 'yes' : 'no') . "\n";
    file_put_contents($root . '/scripts/_footer1_final.txt', $m[1]);
}
