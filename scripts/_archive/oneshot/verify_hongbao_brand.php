<?php
$root = dirname(__DIR__);
$needles = ['抢红包', '抢红宝'];
$dirs = [
    $root . '/application/extra',
    $root . '/application/admin',
    $root . '/application/common',
    $root . '/public/888',
    $root . '/im-server/App',
];
$hits = 0;
foreach ($dirs as $dir) {
    if (!is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $ext = strtolower($f->getExtension());
        if (!in_array($ext, ['php', 'js', 'html', 'css'], true)) continue;
        $path = $f->getPathname();
        if (strpos($path, 'Lua') !== false) continue; // technical comments OK
        $c = file_get_contents($path);
        foreach ($needles as $n) {
            if (strpos($c, $n) !== false) {
                echo "HIT $n => " . str_replace($root . DIRECTORY_SEPARATOR, '', $path) . "\n";
                $hits++;
            }
        }
    }
}
echo "hits=$hits\n";
$zh = include $root . '/application/extra/fanshub_h5_copy.php';
echo 'brand_name=' . ($zh['brand_name'] ?? '') . "\n";
echo 'wallet_grab=' . ($zh['wallet_ledger_type_red_packet_grab'] ?? '') . "\n";
echo 'tab_messages=' . ($zh['tab_bar_messages'] ?? '') . "\n";
