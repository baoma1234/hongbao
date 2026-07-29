<?php
$root = dirname(__DIR__);
$copy = include $root . '/application/extra/fanshub_h5_copy.php';
$home = file_get_contents($root . '/public/888/partials/tab-home.php');

$badge = str_replace('{open_account_rights}', '2', $copy['open_account_badge_fallback']);

// Fix broken closers like 股?/span> -> use full rebuild of button
$newBtn = '<button type="button" class="cta-open-account" id="openAccountBtn" onclick="goToMainStation()">'
    . '<span class="cta-open-account-label" id="openAccountBtnLabel" data-copy="open_account_badge_fallback">' . $badge . '</span>'
    . '<span class="cta-open-account-badge" id="openAccountBadge" data-copy="open_account_badge_fallback">' . $badge . '</span>'
    . '</button>';

if (preg_match('/<button type="button" class="cta-open-account" id="openAccountBtn"[^>]*>[\s\S]*?<\/button>/u', $home)) {
    $home = preg_replace(
        '/<button type="button" class="cta-open-account" id="openAccountBtn"[^>]*>[\s\S]*?<\/button>/u',
        $newBtn,
        $home,
        1
    );
    echo "btn_ok\n";
} else {
    // broken closer may prevent match — replace from button start to next home-claim-section
    $home = preg_replace(
        '/<button type="button" class="cta-open-account" id="openAccountBtn"[\s\S]*?(?=<div class="home-claim-section")/u',
        $newBtn . "\n            </div>\n\n            ",
        $home,
        1
    );
    echo "btn_fallback_replace\n";
}

// Fix any remaining broken patterns: CJK?/tag>
$home = preg_replace_callback(
    '/([\x{4e00}-\x{9fff}])\?(<\/(?:span|div|p|button|label|strong)>)/u',
    function ($m) {
        // drop the stray ? before closer
        return $m[1] . $m[2];
    },
    $home
);

// Re-apply all data-copy fallbacks once more for claim/home keys that may still be wrong
foreach (['page_hero_claim_title','page_hero_claim_sub','uid_label','uid_submit_btn','uid_hint_idle','settle_title_low','settle_sub_low','footer_line1','footer_line2','footer_line3'] as $key) {
    if (!isset($copy[$key])) continue;
    $val = $copy[$key];
    $home = preg_replace(
        '/(<[^>]*\bdata-copy="' . preg_quote($key, '/') . '"[^>]*>)(.*?)(<\/[^>]+>)/us',
        '$1' . addcslashes($val, '\\$') . '$3',
        $home,
        1
    );
}

file_put_contents($root . '/public/888/partials/tab-home.php', $home);

// Verify
$pos = strpos($home, 'openAccountBtn');
echo substr($home, $pos, 420) . "\n";
echo "broken_closer_left=" . preg_match_all('/[\x{4e00}-\x{9fff}]\?<\/(?:span|div|p)>/u', $home) . "\n";
echo "moji_left=" . preg_match_all('/鍏|褰撳|鑲′|鏈|鐢熸/u', $home) . "\n";
