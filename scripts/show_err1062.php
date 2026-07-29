<?php
$t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fh_check.js';
// regenerate
$h = file_get_contents(dirname(__DIR__) . '/public/888/index.html');
preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
$s = '';
foreach ($m[1] as $b) {
    if (strlen($b) > strlen($s)) $s = $b;
}
file_put_contents($t, $s);
$lines = file($t);
$n = 1062;
for ($i = $n - 10; $i <= $n + 5; $i++) {
    if (!isset($lines[$i - 1])) continue;
    echo $i . '| ' . $lines[$i - 1];
}

// locate in html
foreach (file(dirname(__DIR__) . '/public/888/index.html') as $i => $line) {
    if (str_contains($line, 'detectBrowserLocale') || (str_contains($line, 'var map = {') && isset($prev) && str_contains($prev, 'langs'))) {
        // show
    }
}
$html = file(dirname(__DIR__) . '/public/888/index.html');
foreach ($html as $i => $line) {
    if (preg_match('/function detectBrowserLocale|navigator\.languages/', $line)) {
        echo "HTML L" . ($i + 1) . "\n";
        for ($j = $i; $j < $i + 25 && isset($html[$j]); $j++) {
            echo ($j + 1) . '| ' . $html[$j];
        }
        break;
    }
}
