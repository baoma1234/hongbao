<?php
$path = dirname(__DIR__) . '/public/888/index.html';
$src = file_get_contents($path);

$n = 0;

// currencySymbol() || '￥'  → ensure semicolon
$src = preg_replace(
    "/(currencySymbol\(\)\s*\)\s*\|\|\s*'￥')(\s*\n)/",
    "$1;$2",
    $src,
    -1,
    $c1
);
$n += $c1;

// Broken: '...￥ + expr  →  '...￥' + expr
$src = preg_replace("/￥ \+/u", "￥' + ", $src, -1, $c2);
$n += $c2;

// Broken mojibake end-of-string before +:  'xxxx? +  where ? is replacement leftovers
// Common: 锛? +  / 鍏? + / 浜? + already sometimes ok if quote present
// Pattern: Chinese mojibake char then ? then space+ without closing quote
$src = preg_replace("/([\x{4e00}-\x{9fff}])\? \+/u", "$1' + ", $src, -1, $c3);
$n += $c3;

// Fix leftover broken closes like: 浜?)');  → already may be ok
// Fix: ' / 浠?( ... → keep as string if quote missing mid concat
// Pattern seen: ' / 浠?( 馃敟 杈冩槰鏃ュぇ鐩樻媺鍗?+' + pct
$src = str_replace(
    "' / 浠?( 馃敟 杈冩槰鏃ュぇ鐩樻媺鍗?+'",
    "' / 份 ( 🔥 较昨日大盘拉升 +'",
    $src,
    $c4
);
$n += $c4;

file_put_contents($path, $src);
echo "patched counts: semi=$c1 yen_plus=$c2 qmark_plus=$c3 jackpot=$c4 total_ops=$n\n";

// Extract inline script and try to spot remaining bad patterns
if (preg_match_all('/\|\| \(\'[^\']*￥ \+/u', $src, $m)) {
    echo "STILL BAD yen+: " . count($m[0]) . "\n";
}
if (preg_match_all("/currencySymbol\(\)[^\n]+/u", $src, $m)) {
    foreach (array_unique($m[0]) as $line) {
        echo "CURR: $line\n";
    }
}

// Find lines with odd quote imbalance in script around known hotspots
$lines = explode("\n", $src);
foreach ([2458, 2495, 2592, 2611, 2679, 3100, 3103, 3106, 4028, 4032, 4123, 4126] as $i) {
    if (!isset($lines[$i])) continue;
    $line = $lines[$i];
    $sq = substr_count($line, "'");
    echo "L" . ($i + 1) . " quotes=$sq :: " . substr($line, 0, 120) . "\n";
}
