<?php
$p = dirname(__DIR__) . '/public/888/index.html';
$h = file_get_contents($p);
preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
$s = '';
foreach ($m[1] as $b) {
    if (strlen($b) > strlen($s)) {
        $s = $b;
    }
}
$t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fh_check.js';
file_put_contents($t, $s);
echo "script_bytes=" . strlen($s) . "\n";
$output = [];
$code = 0;
exec('node --check ' . escapeshellarg($t) . ' 2>&1', $output, $code);
echo implode("\n", $output) . "\n";
echo "exit=$code\n";

// Also verify the reported hotspot
$lines = file($p);
echo "L2459=" . trim($lines[2458]) . "\n";
