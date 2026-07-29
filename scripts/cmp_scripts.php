<?php
function largestScript($path) {
    $h = file_get_contents($path);
    preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
    $best = '';
    foreach ($m[1] as $b) {
        if (strlen($b) > strlen($best)) $best = $b;
    }
    return $best;
}

foreach (['live' => 'index.html', 'rec' => 'index_recovered.html', 'bak' => 'index.html.bak'] as $label => $file) {
    $path = dirname(__DIR__) . '/public/888/' . $file;
    if (!is_file($path)) {
        echo "$label missing\n";
        continue;
    }
    $best = largestScript($path);
    $lines = preg_split('/\r?\n/', $best);
    echo "$label lines=" . count($lines) . " bytes=" . strlen($best) . "\n";
    $t = sys_get_temp_dir() . "/fh_$label.js";
    file_put_contents($t, $best);
    $out = [];
    $code = 0;
    exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
    echo "  check exit=$code " . implode(' | ', array_slice($out, 0, 3)) . "\n";
    echo "  last 12 lines:\n";
    foreach (array_slice($lines, -12, null, true) as $i => $l) {
        echo '    ' . ($i + 1) . '|' . substr($l, 0, 120) . "\n";
    }
    echo "\n";
}
