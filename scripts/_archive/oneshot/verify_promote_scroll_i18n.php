<?php
$root = dirname(__DIR__);
$c = file_get_contents($root . '/public/888/js/chat/06-notice.js');
echo 'timer3s=' . (strpos($c, ', 3000)') !== false ? '1' : '0') . "\n";
echo 'step=' . (strpos($c, 'startPromoteEarnScroll') !== false ? '1' : '0') . "\n";
echo 'css_anim=' . (strpos(file_get_contents($root . '/public/888/css/chat-notice-feed.css'), 'chatPromoteEarnScroll') !== false ? 'still' : 'removed') . "\n";

$zh = include $root . '/application/extra/fanshub_h5_copy.php';
$en = include $root . '/application/extra/i18n/en-PH.php';
$cfg = include $root . '/application/extra/fanshub.php';
$keys = [
    'promote_earn_title', 'promote_earn_live', 'promote_earn_col_uid', 'promote_earn_col_type',
    'promote_earn_col_detail', 'promote_earn_col_amount', 'promote_earn_type_share', 'promote_earn_type_group',
    'promote_earn_detail_share_n', 'promote_earn_detail_groups_n', 'promote_earn_detail_multi',
    'promote_earn_detail_exposure', 'promote_earn_refreshed',
];
$n = 0;
foreach ($keys as $k) {
    if (!isset($cfg['h5_copy'][$k]) || $cfg['h5_copy'][$k] === '') {
        $cfg['h5_copy'][$k] = $zh[$k];
        $n++;
    }
}
file_put_contents($root . '/application/extra/fanshub.php', "<?php\n\nreturn " . var_export($cfg, true) . ";\n");
echo "fanshub_h5_copy_added=$n\n";
echo 'en_title=' . ($en['promote_earn_title'] ?? 'MISS') . "\n";
