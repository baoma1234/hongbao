<?php
$root = dirname(__DIR__);
$core = file_get_contents($root . '/public/888/js/app-core.js');

// Find suspicious truncations: Chinese char then ? then quote/tag
if (preg_match_all('/.{0,60}[\x{4e00}-\x{9fff}]\?.{0,20}/u', $core, $m)) {
    echo "trunc_patterns=" . count($m[0]) . "\n";
    foreach ($m[0] as $s) echo "  $s\n";
}

// incomplete utf8
if (preg_match_all('/[\xE0-\xEF](?![\x80-\xBF]{2})/', $core, $m, PREG_OFFSET_CAPTURE)) {
    echo "incomplete=" . count($m[0]) . "\n";
    foreach (array_slice($m[0], 0, 10) as $hit) {
        $pos = $hit[1];
        $line = substr_count(substr($core, 0, $pos), "\n") + 1;
        echo "  line $line hex=" . bin2hex(substr($core, max(0,$pos-5), 20)) . "\n";
    }
}

// syntax check
$tmp = sys_get_temp_dir() . '/appcore_check.js';
// node syntax check if available
exec('node --check ' . escapeshellarg($root . '/public/888/js/app-core.js') . ' 2>&1', $out, $code);
echo "node_check_exit=$code\n";
echo implode("\n", $out) . "\n";

foreach (glob($root . '/public/888/js/chat/*.js') as $f) {
    exec('node --check ' . escapeshellarg($f) . ' 2>&1', $o, $c);
    if ($c !== 0) {
        echo "FAIL " . basename($f) . "\n" . implode("\n", $o) . "\n";
    }
}
