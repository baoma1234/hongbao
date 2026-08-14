<?php
foreach (['index.html', 'index_recovered.html', 'index.html.bak'] as $name) {
    $path = dirname(__DIR__) . '/public/888/' . $name;
    if (!is_file($path)) {
        echo "$name MISSING\n";
        continue;
    }
    $h = file_get_contents($path);
    preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
    $s = '';
    foreach ($m[1] as $b) {
        if (strlen($b) > strlen($s)) $s = $b;
    }
    // Fix obvious currency break in bak/recovered for fair check
    $s2 = preg_replace("/(currencySymbol\(\)\s*\)\s*\|\|\s*)'[^']*?;/", "$1'￥';", $s);
    $s2 = preg_replace("/(currencySymbol\(\)\s*\)\s*\|\|\s*)'[^'\n]*$/", "$1'￥';", $s2);
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fh_' . md5($name) . '.js';
    file_put_contents($t, $s2);
    $out = [];
    $code = 0;
    exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
    echo "$name bytes=" . strlen($s) . " exit=$code " . ($out[0] ?? 'OK') . "\n";
}
