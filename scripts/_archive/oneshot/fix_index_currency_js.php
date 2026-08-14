<?php
$path = dirname(__DIR__) . '/public/888/index.html';
$src = file_get_contents($path);
if ($src === false) {
    fwrite(STDERR, "cannot read\n");
    exit(1);
}

$before = $src;

// Broken JS fallback: currencySymbol() || '锟?;
$count1 = 0;
$src = preg_replace(
    "/(currencySymbol\(\)\s*\)\s*\|\|\s*)'[^']*?;/",
    "$1'￥'",
    $src,
    -1,
    $count1
);

// Also: || '锟' without semicolon
$count2 = 0;
$src = preg_replace(
    "/(currencySymbol\(\)\s*\)\s*\|\|\s*)'锟'/",
    "$1'￥'",
    $src,
    -1,
    $count2
);

// HTML currency symbols that are just 锟
$count3 = 0;
$src = str_replace(
    [
        '>锟</span>',
        'id="chatRpBalance">锟?.00</strong>',
        'class="chat-rp-yuan">锟</span>',
        'id="profileWithdrawBalance">锟?.00</strong>',
        'id="jackpotNum">锟?2,000.00</div>',
        'id="welcomeLotteryPrice">锟?-</div>',
        'id="modalBalanceWrap">锟?.00</div>',
    ],
    [
        '>￥</span>',
        'id="chatRpBalance">￥0.00</strong>',
        'class="chat-rp-yuan">￥</span>',
        'id="profileWithdrawBalance">￥0.00</strong>',
        'id="jackpotNum">￥2,000.00</div>',
        'id="welcomeLotteryPrice">￥-</div>',
        'id="modalBalanceWrap">￥0.00</div>',
    ],
    $src,
    $count3
);

// Generic broken yuan in JS concatenations: 锟? -> ￥'
// Pattern often: 锟? +  meaning missing close quote after ￥
$count4 = 0;
$src = str_replace('锟?', '￥', $src, $count4);

$count5 = 0;
$src = str_replace('锟', '￥', $src, $count5);

if ($src === $before) {
    echo "NO CHANGE\n";
    exit(0);
}

file_put_contents($path, $src);
echo "OK fixed currencySymbol=$count1 yen_mark=$count2 html=$count3 yen_q=$count4 yen_bare=$count5\n";
echo "bytes before=" . strlen($before) . " after=" . strlen($src) . "\n";

// Quick JS sanity: find unclosed-looking currencySymbol fallbacks
if (preg_match_all("/currencySymbol\(\)\s*\)\s*\|\|\s*'([^']{0,10})'/u", $src, $m)) {
    echo "currency fallbacks: " . implode(', ', array_unique($m[1])) . "\n";
}
