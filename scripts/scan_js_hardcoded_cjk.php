<?php
$root = dirname(__DIR__);
$jsFiles = array_merge(
    glob($root . '/public/888/js/*.js') ?: [],
    glob($root . '/public/888/js/chat/*.js') ?: []
);
$out = [];
foreach ($jsFiles as $f) {
    $c = file_get_contents($f);
    // hardcoded CJK string literals not already inside chatT/fc/wt(
    preg_match_all('/(["\'])([^"\']*[\x{4e00}-\x{9fff}][^"\']*)\1/u', $c, $m, PREG_OFFSET_CAPTURE);
    $hits = [];
    foreach ($m[2] as $i => $hit) {
        $text = $hit[0];
        $pos = $hit[1];
        $before = substr($c, max(0, $pos - 80), 80);
        if (preg_match('/(?:chatT|chatTx|fc|wt)\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*$/s', $before)) continue;
        if (preg_match('/(?:chatT|chatTx|fc|wt)\s*\(\s*$/s', $before)) continue;
        // skip comments
        $lineStart = strrpos(substr($c, 0, $pos), "\n");
        $line = substr($c, $lineStart === false ? 0 : $lineStart, 120);
        if (preg_match('/^\s*\/\//', $line) || preg_match('/^\s*\*/', $line)) continue;
        $len = mb_strlen($text);
        if ($len < 1 || $len > 60) continue;
        $hits[$text] = true;
    }
    if ($hits) {
        $rel = str_replace('\\', '/', substr($f, strlen($root) + 1));
        $out[$rel] = array_keys($hits);
    }
}
file_put_contents($root . '/scripts/_js_hardcoded_cjk.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
foreach ($out as $rel => $items) {
    echo "## $rel (" . count($items) . ")\n";
    foreach (array_slice($items, 0, 50) as $t) echo "  $t\n";
    echo "\n";
}
