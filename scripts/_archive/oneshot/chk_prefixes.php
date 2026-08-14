<?php
$path = dirname(__DIR__) . '/public/888/index.html';
$h = file_get_contents($path);
preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
$best = '';
foreach ($m[1] as $b) {
    if (strlen($b) > strlen($best)) $best = $b;
}
$lines = preg_split('/\r?\n/', $best);
$t = sys_get_temp_dir() . '/fh_chk.js';

foreach ([1487, 1488, 1500, 1600, 2000, 2500, 2690, 2693, 2695, 2800, 2956] as $i) {
    file_put_contents($t, implode("\n", array_slice($lines, 0, $i)) . "\n");
    $out = [];
    $code = 0;
    exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
    $msg = preg_replace('/\s+/', ' ', implode(' ', $out));
    echo "prefix $i => $code " . substr($msg, 0, 140) . "\n";
}

echo "\n--- around 2693 ---\n";
for ($j = 2685; $j <= 2705; $j++) {
    echo $j . '|' . ($lines[$j - 1] ?? '') . "\n";
}

// map to HTML
$snippet = trim($lines[2692] ?? '');
$html = file($path);
foreach ($html as $i => $l) {
    if ($snippet !== '' && str_contains($l, substr($snippet, 0, 30))) {
        echo "HTML around " . ($i + 1) . "\n";
        for ($k = max(0, $i - 8); $k <= min(count($html) - 1, $i + 5); $k++) {
            echo ($k + 1) . '|' . rtrim($html[$k]) . "\n";
        }
        break;
    }
}
