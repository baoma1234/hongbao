<?php
$t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fh_check.js';
$h = file_get_contents(dirname(__DIR__) . '/public/888/index.html');
preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
$s = '';
foreach ($m[1] as $b) {
    if (strlen($b) > strlen($s)) $s = $b;
}
file_put_contents($t, $s);
$lines = file($t);
$n = 189;
for ($i = $n - 8; $i <= $n + 8; $i++) {
    if (!isset($lines[$i - 1])) continue;
    echo $i . '| ' . $lines[$i - 1];
}
