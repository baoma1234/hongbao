<?php
$root = dirname(__DIR__);
$path = $root . '/public/888/js/chat/06-notice.js';
$js = file_get_contents($path);

// Flexible replacements for commission titles
$js = preg_replace(
    "/title\.textContent\s*=\s*'推广结算'\s*;/",
    "title.textContent = chatT('chat_commission_nav_promo');",
    $js
);
$js = preg_replace(
    "/title\.textContent\s*=\s*'红包返佣'\s*;/",
    "title.textContent = chatT('chat_commission_nav_rebate');",
    $js
);
$js = preg_replace(
    "/title\.textContent\s*=\s*'提现记录'\s*;/",
    "title.textContent = chatT('chat_commission_nav_withdraw');",
    $js
);
$js = preg_replace(
    "/title\.textContent\s*=\s*'最近结算'\s*;/",
    "title.textContent = chatT('chat_commission_recent');",
    $js
);
$js = preg_replace(
    "/renderCommissionRows\(\s*data\.promo_recent\s*,\s*'暂无推广结算记录'\s*\)/",
    "renderCommissionRows(data.promo_recent, chatT('chat_commission_empty_promo'))",
    $js
);
$js = preg_replace(
    "/renderCommissionRows\(\s*data\.rebate_recent\s*,\s*'暂无红包返佣记录'\s*\)/",
    "renderCommissionRows(data.rebate_recent, chatT('chat_commission_empty_rebate'))",
    $js
);
$js = preg_replace(
    "/renderCommissionRows\(\s*data\.withdraw_recent\s*,\s*'暂无提现记录'\s*\)/",
    "renderCommissionRows(data.withdraw_recent, chatT('chat_commission_empty_withdraw'))",
    $js
);
$js = preg_replace(
    "/renderCommissionRows\(\s*data\.recent\s*,\s*'暂无结算记录，邀请好友即可获得'\s*\)/",
    "renderCommissionRows(data.recent, chatT('chat_commission_empty_recent'))",
    $js
);

file_put_contents($path, $js);
echo "notice_titles_done\n";

// Verify footer restored
$home = file_get_contents($root . '/public/888/partials/tab-home.php');
preg_match('/data-copy="footer_line1"[^>]*>([^<]*)</u', $home, $m);
file_put_contents($root . '/scripts/_footer1_after.txt', $m[1] ?? 'MISS');
preg_match('/data-copy="uid_label"[^>]*>([^<]*)</u', $home, $m2);
file_put_contents($root . '/scripts/_uid_after.txt', $m2[1] ?? 'MISS');
echo "footer1=" . ($m[1] ?? 'MISS') . "\n";
echo "moji_left=" . preg_match_all('/鍏|褰撳|鑲′|鏈|鐢熸/u', $home) . "\n";

// Check app-core for broken || ;
$core = file_get_contents($root . '/public/888/js/app-core.js');
if (preg_match_all('/\|\|\s*;/', $core, $mm)) {
    echo "broken_or_semi=" . count($mm[0]) . "\n";
}
exec('node --check ' . escapeshellarg($root . '/public/888/js/app-core.js') . ' 2>&1', $o, $c);
echo "appcore_syntax=$c " . implode(' ', $o) . "\n";
exec('node --check ' . escapeshellarg($path) . ' 2>&1', $o2, $c2);
echo "notice_syntax=$c2 " . implode(' ', $o2) . "\n";

// share-swap-title
$ex = file_get_contents($root . '/public/888/partials/tab-exchange.php');
preg_match('/id="shareSwapTitle"[^>]*>([^<]*)</u', $ex, $mx);
echo "swap_title_html=" . ($mx[1] ?? 'MISS') . "\n";
