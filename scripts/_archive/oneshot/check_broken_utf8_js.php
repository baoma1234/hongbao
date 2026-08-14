<?php
$root = dirname(__DIR__);
$lines = file($root . '/public/888/js/chat/06-notice.js');
foreach ([412, 427, 379, 401] as $n) {
    $line = $lines[$n - 1] ?? '';
    echo "L$n full_hex=" . bin2hex(rtrim($line, "\r\n")) . "\n";
    echo "L$n valid_utf8=" . (mb_check_encoding($line, 'UTF-8') ? '1' : '0') . "\n";
}

// Find all lines with incomplete UTF-8 or broken quotes in chat js
foreach (glob($root . '/public/888/js/chat/*.js') as $f) {
    $raw = file_get_contents($f);
    $rel = basename($f);
    // incomplete 3-byte utf8 sequences
    if (preg_match_all('/[\xE0-\xEF](?![\x80-\xBF]{2})/', $raw, $m, PREG_OFFSET_CAPTURE)) {
        echo "$rel incomplete_utf8=" . count($m[0]) . "\n";
        foreach (array_slice($m[0], 0, 5) as $hit) {
            $pos = $hit[1];
            echo "  at $pos context=" . bin2hex(substr($raw, max(0, $pos - 10), 30)) . "\n";
        }
    }
}

// home mojibake segments - rewrite plan: replace text of every data-copy node from copy map
$home = file_get_contents($root . '/public/888/partials/tab-home.php');
echo "home_moji=" . preg_match_all('/鍏|褰撳|鑲′|鏈|鐢熸|浜掑|绂忓/u', $home) . "\n";
