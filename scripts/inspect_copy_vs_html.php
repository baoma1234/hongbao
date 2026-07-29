<?php
$root = dirname(__DIR__);
$c = include $root . '/application/extra/fanshub_h5_copy.php';
foreach (['footer_line1','footer_line2','footer_line3','uid_label','uid_placeholder','swap_title','jackpot_label','page_hero_exchange_title','open_account_btn'] as $k) {
    $v = $c[$k] ?? '';
    echo "== $k ==\n";
    echo "len=" . strlen($v) . " mb=" . mb_strlen($v, 'UTF-8') . "\n";
    echo "utf8_ok=" . (mb_check_encoding($v, 'UTF-8') ? '1' : '0') . "\n";
    // write to dedicated utf8 file for reading
}
$dump = [];
foreach ($c as $k => $v) {
    if (in_array($k, ['footer_line1','footer_line2','footer_line3','uid_label','uid_placeholder','uid_submit_btn','uid_hint_idle','open_account_btn','open_account_badge_fallback','settle_title_low','settle_sub_low','swap_title','page_hero_exchange_title','page_hero_master_title','page_hero_master_sub','jackpot_label','jackpot_hint','chat_community_title','profile_section_asset','master_lock_title','master_lock_desc'], true)) {
        $dump[$k] = $v;
    }
}
file_put_contents($root . '/scripts/_sample_copy_keys.json', json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// fanshub.php overrides
$cfgPath = $root . '/application/extra/fanshub.php';
if (is_file($cfgPath)) {
    $cfg = include $cfgPath;
    $hc = isset($cfg['h5_copy']) && is_array($cfg['h5_copy']) ? $cfg['h5_copy'] : [];
    echo "fanshub.php h5_copy keys=" . count($hc) . "\n";
    $ov = [];
    foreach (['footer_line1','uid_label','swap_title','page_hero_master_title'] as $k) {
        if (isset($hc[$k])) $ov[$k] = $hc[$k];
    }
    file_put_contents($root . '/scripts/_sample_override_keys.json', json_encode($ov, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "overrides_sample=" . count($ov) . "\n";
} else {
    echo "no fanshub.php\n";
}

// HTML corruption check with hex
$home = file_get_contents($root . '/public/888/partials/tab-home.php');
if (preg_match('/data-copy="footer_line1"[^>]*>([^<]*)</u', $home, $m)) {
    echo "html_footer1_hex=" . bin2hex($m[1]) . "\n";
    file_put_contents($root . '/scripts/_html_footer1.txt', $m[1]);
}
if (preg_match('/data-copy="uid_label"[^>]*>([^<]*)</u', $home, $m)) {
    file_put_contents($root . '/scripts/_html_uid_label.txt', $m[1]);
}
// broken closing tags?
echo "broken_tag_count=" . preg_match_all('/[\x{4e00}-\x{9fff}]\?<\/(?:div|span|button|p|label)>/u', $home, $mm) . "\n";
echo "mojibake_span_count=" . preg_match_all('/鍏|褰撳|鑲′|閸/u', $home) . "\n";
