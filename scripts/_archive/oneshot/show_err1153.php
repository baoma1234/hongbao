<?php
$h = file_get_contents(dirname(__DIR__) . '/public/888/index.html');
preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
$s = '';
foreach ($m[1] as $b) {
    if (strlen($b) > strlen($s)) $s = $b;
}
$t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fh_check.js';
file_put_contents($t, $s);
$lines = file($t);
$n = 1153;
for ($i = $n - 15; $i <= $n + 5; $i++) {
    if (!isset($lines[$i - 1])) continue;
    echo $i . '| ' . $lines[$i - 1];
}

// map to html: search distinctive nearby text
$needle = trim($lines[$n - 5] ?? '');
$html = file(dirname(__DIR__) . '/public/888/index.html');
foreach ($html as $i => $line) {
    if ($needle && str_contains($line, substr($needle, 0, 40))) {
        echo "HTML ~L" . ($i + 1) . "\n";
        for ($j = max(0, $i - 10); $j <= $i + 8; $j++) {
            echo ($j + 1) . '| ' . $html[$j];
        }
        break;
    }
}
