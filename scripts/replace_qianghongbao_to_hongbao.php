<?php
/**
 * 将「抢红包」「抢红宝」统一替换为「红宝」，并刷新相关前端包
 */
$root = dirname(__DIR__);
$replacements = [
    '抢红包' => '红宝',
    '抢红宝' => '红宝',
];

$dirs = [
    $root . '/application/extra',
    $root . '/application/admin/view/fanshub',
    $root . '/application/common/library',
    $root . '/public/888',
    $root . '/public/im',
    $root . '/im-server/App',
];

$exts = ['php', 'js', 'html', 'css', 'md', 'sql', 'json', 'txt'];
$skipDirs = ['node_modules', 'vendor', '.git'];
$changed = [];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/application/extra'));
$paths = [];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $path = $file->getPathname();
        $ok = false;
        foreach ($skipDirs as $sd) {
            if (strpos($path, DIRECTORY_SEPARATOR . $sd . DIRECTORY_SEPARATOR) !== false) {
                $ok = true;
                break;
            }
        }
        if ($ok) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $exts, true)) continue;
        // 跳过大体积打包文件，后面单独用源文件再生成
        if (preg_match('/locales\.bundle\.js$|copy\.defaults\.js$/i', $path)) {
            // 仍处理，保证一致性
        }
        $paths[] = $path;
    }
}

foreach ($paths as $path) {
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') continue;
    if (strpos($raw, '抢红包') === false && strpos($raw, '抢红宝') === false) continue;
    $next = str_replace(array_keys($replacements), array_values($replacements), $raw);
    if ($next === $raw) continue;
    file_put_contents($path, $next);
    $changed[] = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
}

echo "files_changed=" . count($changed) . PHP_EOL;
foreach ($changed as $c) {
    echo "OK  {$c}\n";
}

// site.php brand
$site = $root . '/application/extra/site.php';
if (is_file($site)) {
    $raw = file_get_contents($site);
    $next = str_replace(['抢红包', '抢红宝'], '红宝', $raw);
    if ($next !== $raw) {
        file_put_contents($site, $next);
        echo "OK  application/extra/site.php\n";
    }
}

echo "DONE\n";
