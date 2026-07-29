<?php
$root = dirname(__DIR__);
$home = file_get_contents($root . '/public/888/partials/tab-home.php');
// extract around open account / share promo / footer / uid
foreach (['open_account', 'uid-section', 'footer_line', 'share-promo', 'jackpot-meta', 'asset-label'] as $needle) {
    $pos = stripos($home, $needle);
    if ($pos === false) { echo "no $needle\n"; continue; }
    echo "\n==== $needle @ $pos ====\n";
    echo substr($home, max(0, $pos - 80), 500) . "\n";
}

// app-core moji lines
$core = file_get_contents($root . '/public/888/js/app-core.js');
if (preg_match_all('/.{0,40}(?:鍏|褰撳|鑲′|鏈|鐢熸|浜掑).{0,40}/u', $core, $m)) {
    echo "\n==== app-core moji snippets ====\n";
    foreach (array_slice($m[0], 0, 20) as $s) echo $s . "\n---\n";
}
