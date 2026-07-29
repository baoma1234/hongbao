<?php
$path = dirname(__DIR__) . '/public/888/index.html';
$h = file_get_contents($path);
preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
$best = '';
$idx = -1;
foreach ($m[1] as $i => $b) {
    if (strlen($b) > strlen($best)) { $best = $b; $idx = $i; }
}
echo "scripts=" . count($m[1]) . " largest_idx=$idx bytes=" . strlen($best) . "\n";
$lines = preg_split('/\r?\n/', $best);
$n = count($lines);
$t = sys_get_temp_dir() . '/fh_live2.js';
file_put_contents($t, $best);
$out = [];
$code = 0;
exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
echo "full check=$code\n" . implode("\n", $out) . "\n";

// find last OK from 1488
$lo = 1488;
$hi = $n;
$lastOk = 1488;
while ($lo <= $hi) {
    $mid = intdiv($lo + $hi, 2);
    file_put_contents($t, implode("\n", array_slice($lines, 0, $mid)) . "\n");
    $out = [];
    $code = 0;
    exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
    if ($code === 0) { $lastOk = $mid; $lo = $mid + 1; }
    else { $hi = $mid - 1; }
}
echo "lastOk=$lastOk / $n\n";
for ($j = $lastOk; $j < min($n, $lastOk + 35); $j++) {
    echo ($j + 1) . '|' . $lines[$j] . "\n";
}

// find first concrete error by appending closers periodically? 
// Instead walk line by line from lastOk looking for truncations without continuation
echo "\nSuspicious after lastOk:\n";
for ($j = $lastOk; $j < $n; $j++) {
    $l = rtrim($lines[$j]);
    $next = ltrim($lines[$j + 1] ?? '');
    if (preg_match('/\|\|\s*[a-zA-Z_$][\w.]*\s*$/', $l) && !preg_match('/[;,)\]}]\s*$/', $l) && !preg_match('/^\?/', $next)) {
        echo ($j+1) . '|' . $l . "\n";
    }
    if (preg_match('/\|\|\s*$/', $l) && !preg_match('/^[\'"`\w(\[]/', $next)) {
        echo ($j+1) . '|' . $l . "\n";
    }
    if (preg_match('/=\s*[a-zA-Z_$][\w.]*\s*$/', $l) && !preg_match('/^\?/', $next) && !preg_match('/[;,)\]}]\s*$/', $l) && preg_match('/^(const|let|var|if|for|return|function|async|await|document|window|account|CONFIG|show|update|render|apply|session|local)/', $next)) {
        echo ($j+1) . '|' . $l . "\n";
    }
}
