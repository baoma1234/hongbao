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
$t = sys_get_temp_dir() . '/fh_lin.js';

function chk($lines, $i, $t) {
    file_put_contents($t, implode("\n", array_slice($lines, 0, $i)) . "\n");
    $out = [];
    $code = 0;
    exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

$lastOk = 1488;
$okPoints = [1488];
for ($i = 1489; $i <= $n; $i++) {
    // only check likely balance points: blank line after `}` 
    $prev = rtrim($lines[$i - 1] ?? '');
    if (!preg_match('/^\s*\}\s*$/', $prev) && $i !== $n) continue;
    [$code, $msg] = chk($lines, $i, $t);
    if ($code === 0) {
        $lastOk = $i;
        $okPoints[] = $i;
        echo "OK $i\n";
    } else {
        $eof = str_contains($msg, 'Unexpected end of input');
        if (!$eof) {
            echo "ERR $i: " . substr(preg_replace('/\s+/', ' ', $msg), 0, 160) . "\n";
            for ($j = max(0, $i - 8); $j < min($n, $i + 2); $j++) {
                echo ($j + 1) . '|' . $lines[$j] . "\n";
            }
            // continue to find more? break on first concrete
            break;
        }
    }
}
echo "final lastOk=$lastOk\n";
echo "okPoints count=" . count($okPoints) . " last=" . end($okPoints) . "\n";
echo "after lastOk:\n";
for ($j = $lastOk; $j < min($n, $lastOk + 40); $j++) {
    echo ($j + 1) . '|' . $lines[$j] . "\n";
}
