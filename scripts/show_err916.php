<?php
$t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fh_check.js';
$lines = file($t);
$n = 916;
for ($i = $n - 8; $i <= $n + 8; $i++) {
    if (!isset($lines[$i - 1])) continue;
    echo $i . '| ' . $lines[$i - 1];
}

// Find this snippet in index.html
$html = file(dirname(__DIR__) . '/public/888/index.html');
$needle = trim($lines[$n - 1]);
foreach ($html as $i => $line) {
    if (str_contains($line, 'async function doSendSms') || (str_contains($line, 'try {') && isset($html[$i-1]) && str_contains($html[$i-1], 'doSendSms'))) {
        echo "HTML around doSendSms L" . ($i + 1) . ":\n";
        for ($j = $i - 2; $j <= $i + 10; $j++) {
            if (isset($html[$j])) echo ($j + 1) . '| ' . $html[$j];
        }
        break;
    }
}
