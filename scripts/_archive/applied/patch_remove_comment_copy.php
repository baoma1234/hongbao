<?php
$root = dirname(__DIR__);
$map = [
    '排行榜 · 实时留言' => '排行榜',
    'Ranks · live comments' => 'Leaderboard',
];
$files = array_merge(
    glob($root . '/public/888/i18n/locales/*.js') ?: [],
    [
        $root . '/public/888/copy.defaults.js',
        $root . '/public/888/i18n/locales.bundle.js',
        $root . '/application/extra/i18n/en-PH.php',
        $root . '/application/extra/i18n/id-ID.php',
        $root . '/application/extra/i18n/vi-VN.php',
        $root . '/application/extra/i18n/ms-MY.php',
        $root . '/application/extra/i18n/km-KH.php',
    ]
);
foreach ($files as $f) {
    if (!is_file($f)) {
        continue;
    }
    $s = file_get_contents($f);
    $n = strtr($s, $map);
    if ($n !== $s) {
        file_put_contents($f, $n);
        echo "ok $f\n";
    }
}
echo "done\n";
