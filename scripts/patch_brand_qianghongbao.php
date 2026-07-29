<?php
/**
 * Rename brand to 抢红宝 across H5 i18n / defaults.
 */
$root = dirname(__DIR__) . '/public/888';
$files = [
    $root . '/copy.defaults.js',
    $root . '/i18n/locales.bundle.js',
    $root . '/i18n/locales/zh-CN.js',
    $root . '/i18n/locales/en-PH.js',
    $root . '/i18n/locales/id-ID.js',
    $root . '/i18n/locales/vi-VN.js',
    $root . '/i18n/locales/ms-MY.js',
    $root . '/i18n/locales/km-KH.js',
];

$replacements = [
    // brand
    '"brand_name":"555.bio"' => '"brand_name":"抢红宝"',
    // page titles — keep locale flavor but swap brand
    '555.bio官方直营' => '抢红宝官方直营',
    '555.bio Official Direct' => '抢红宝 Official',
    '555.bio Rasmi' => '抢红宝 Rasmi',
    '555.bio Chính thức' => '抢红宝 Chính thức',
    '555.bio Resmi' => '抢红宝 Resmi',
    '555.bio ផ្លូវការ' => '抢红宝 ផ្លូវការ',
    // common UI strings
    '555.bio 全网生态实时大屏' => '抢红宝 全网生态实时大屏',
    '555.bio Official Live Rewards Pool' => '抢红宝 Official Live Rewards Pool',
    '555.bio Official Live' => '抢红宝 Official Live',
    '555.bio 官方' => '抢红宝官方',
    '555.bio官方' => '抢红宝官方',
    '[555.bio官方极速开户]' => '[抢红宝官方极速开户]',
    '555.bio 主站' => '抢红宝主站',
    '555.bio主站' => '抢红宝主站',
    '555.bio Open-Marketing Platform' => '抢红宝 Open-Marketing Platform',
    '555.bio 路 VIP' => '抢红宝 VIP',
    '555.bio VIP' => '抢红宝 VIP',
];

// Also generic remaining 555.bio in copy files (brand surface only)
$generic = [
    '555.bio' => '抢红宝',
];

foreach ($files as $file) {
    if (!is_file($file)) {
        echo "skip missing {$file}\n";
        continue;
    }
    $raw = file_get_contents($file);
    $before = $raw;
    foreach ($replacements as $a => $b) {
        $raw = str_replace($a, $b, $raw);
    }
    // Remaining brand mentions in these copy files
    $raw = str_replace('555.bio', '抢红宝', $raw);
    if ($raw === $before) {
        echo "unchanged " . basename($file) . "\n";
        continue;
    }
    file_put_contents($file, $raw);
    echo "patched " . basename($file) . "\n";
}

echo "done\n";
