<?php
$path = dirname(__DIR__) . '/public/888/index.html';
$h = file_get_contents($path);
preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
$best = '';
foreach ($m[1] as $b) {
    if (strlen($b) > strlen($best)) $best = $b;
}
$lines = preg_split('/\r?\n/', $best);
$n = count($lines);
$lo = 1;
$hi = $n;
$lastBad = $n;
while ($lo < $hi) {
    $mid = intdiv($lo + $hi, 2);
    $chunk = implode("\n", array_slice($lines, 0, $mid)) . "\n";
    // close open structures roughly? No - just check if error is before mid or "unexpected end"
    $t = sys_get_temp_dir() . '/fh_bin.js';
    file_put_contents($t, $chunk);
    $out = [];
    $code = 0;
    exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
    $msg = implode("\n", $out);
    $errLine = 0;
    if (preg_match('/:(\d+)\r?\n/', $msg, $mm)) $errLine = (int)$mm[1];
    elseif (preg_match('/:(\d+):/', $msg, $mm)) $errLine = (int)$mm[1];
    $isEof = str_contains($msg, 'Unexpected end of input');
    echo "mid=$mid code=$code errLine=$errLine eof=" . ($isEof ? '1' : '0') . " msg=" . substr(preg_replace('/\s+/', ' ', $msg), 0, 80) . "\n";
    if ($code === 0) {
        // prefix is valid (surprising for incomplete) - need more
        $lo = $mid + 1;
    } elseif ($isEof || $errLine >= $mid - 2) {
        // problem is unclosed by mid, or error at end - need earlier cut to find first bad introduction
        // Actually for EOF, the first line that introduces unclosed structure is what we want.
        // Binary search: find smallest mid where check fails with EOF or any error that wasn't in smaller prefix
        $hi = $mid;
        $lastBad = $mid;
    } else {
        // concrete syntax error earlier than mid
        $hi = $errLine > 0 ? $errLine : $mid;
        $lastBad = $hi;
    }
}

// refine: find first line where prefix becomes invalid
$firstBad = 1;
for ($i = max(1, $lastBad - 80); $i <= min($n, $lastBad + 5); $i++) {
    $chunk = implode("\n", array_slice($lines, 0, $i)) . "\n";
    $t = sys_get_temp_dir() . '/fh_bin.js';
    file_put_contents($t, $chunk);
    $out = [];
    $code = 0;
    exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
    $msg = implode("\n", $out);
    $isEof = str_contains($msg, 'Unexpected end of input');
    $other = $code !== 0 && !$isEof;
    if ($other) {
        echo "FIRST_CONCRETE_ERR at prefix $i\n";
        echo implode("\n", array_slice($out, 0, 6)) . "\n";
        $start = max(0, $i - 5);
        for ($j = $start; $j < min($n, $i + 3); $j++) {
            echo ($j + 1) . '|' . $lines[$j] . "\n";
        }
        exit(0);
    }
    if ($code !== 0 && $isEof) {
        // still eof - keep going to find when concrete error appears, or report around lastBad
    }
}

echo "scan around lastBad=$lastBad\n";
for ($j = max(0, $lastBad - 8); $j < min($n, $lastBad + 3); $j++) {
    echo ($j + 1) . '|' . $lines[$j] . "\n";
}

// Also: check prefixes every 100 lines for first non-eof error
echo "\n--- milestone scan ---\n";
$prevOkEof = true;
for ($i = 50; $i <= $n; $i += 50) {
    $chunk = implode("\n", array_slice($lines, 0, $i)) . "\n";
    file_put_contents($t, $chunk);
    $out = [];
    $code = 0;
    exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
    $msg = implode("\n", $out);
    $isEof = str_contains($msg, 'Unexpected end of input');
    $status = $code === 0 ? 'OK' : ($isEof ? 'EOF' : 'ERR');
    if ($status === 'ERR') {
        echo "ERR at $i: " . substr(preg_replace('/\s+/', ' ', $msg), 0, 120) . "\n";
        $errLine = 0;
        if (preg_match('/:(\d+)/', $msg, $mm)) $errLine = (int)$mm[1];
        for ($j = max(0, $errLine - 4); $j < min($n, $errLine + 2); $j++) {
            echo ($j + 1) . '|' . $lines[$j] . "\n";
        }
        break;
    }
    echo "$i $status\n";
}
