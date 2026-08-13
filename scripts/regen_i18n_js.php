<?php
/**
 * Standalone regenerate of public i18n locale JS (no ThinkPHP boot).
 * php scripts/regen_i18n_js.php
 */
$root = dirname(__DIR__);
$zh = include $root . '/application/extra/fanshub_h5_copy.php';
if (!is_array($zh)) {
    fwrite(STDERR, "bad zh\n");
    exit(1);
}
$map = [
    'en-PH' => 'en-PH.php',
    'id-ID' => 'id-ID.php',
    'vi-VN' => 'vi-VN.php',
    'ms-MY' => 'ms-MY.php',
    'km-KH' => 'km-KH.php',
];
$locales = ['zh-CN' => $zh];
foreach ($map as $code => $file) {
    $path = $root . '/application/extra/i18n/' . $file;
    if (!is_file($path)) {
        continue;
    }
    ob_start();
    $data = include $path;
    ob_end_clean();
    if (!is_array($data)) {
        $data = [];
    }
    $locales[$code] = array_merge($zh, $data);
}
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$bundleJson = json_encode($locales, $flags);
if ($bundleJson === false) {
    fwrite(STDERR, "json encode fail\n");
    exit(1);
}
$ver = date('YmdHis');
$targets = ['888', 'fanshub', 'fanshubtest', '999'];
$ok = true;
foreach ($targets as $dir) {
    $i18nDir = $root . '/public/' . $dir . '/i18n';
    $locDir = $i18nDir . '/locales';
    if (!is_dir($locDir)) {
        mkdir($locDir, 0755, true);
    }
    file_put_contents($i18nDir . '/version.js', "window.FANSHUB_I18N_VER='{$ver}';\nwindow.FANSHUB_LOCALES=window.FANSHUB_LOCALES||{};\n");
    foreach ($locales as $code => $mapData) {
        $safe = preg_replace('/[^A-Za-z0-9\\-]/', '', (string)$code);
        $one = json_encode($mapData, $flags);
        $js = "window.FANSHUB_LOCALES=window.FANSHUB_LOCALES||{};\n"
            . 'window.FANSHUB_LOCALES[' . json_encode($safe) . ']=' . $one . ";\n";
        if (file_put_contents($locDir . '/' . $safe . '.js', $js) === false) {
            $ok = false;
        }
    }
    file_put_contents($i18nDir . '/locales.bundle.js', 'window.FANSHUB_LOCALES = ' . $bundleJson . ";\n");
    echo "wrote $i18nDir\n";
}
// App static
$appStatic = $root . '/uni-999/src/static/i18n';
$src = $root . '/public/999/i18n';
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $file) {
    $target = $appStatic . '/' . $it->getSubPathName();
    if ($file->isDir()) {
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
    } else {
        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        copy($file->getPathname(), $target);
    }
}
echo "synced uni-999/src/static/i18n\n";

// coverage check
foreach (['en-PH', 'vi-VN', 'id-ID', 'ms-MY', 'km-KH'] as $code) {
    $m = $locales[$code];
    $same = 0;
    foreach ($zh as $k => $v) {
        if (($m[$k] ?? null) === $v) {
            $same++;
        }
    }
    echo "$code same_as_zh=$same / " . count($zh) . " uid=" . mb_substr((string)($m['uid_label'] ?? ''), 0, 40) . "\n";
}
echo $ok ? "DONE\n" : "DONE_WITH_ERRORS\n";
