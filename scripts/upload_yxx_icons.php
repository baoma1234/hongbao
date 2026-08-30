<?php
/**
 * Upload YXX face + bowl PNGs to OSS.
 * php scripts/upload_yxx_icons.php
 */
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . '/');
define('RUNTIME_PATH', $root . '/runtime/');
define('DS', DIRECTORY_SEPARATOR);

require $root . '/thinkphp/base.php';
\think\App::initCommon();

use app\common\library\OssService;

$dir = $root . '/uni-999/src/static/yxx';
$files = [
    ['local' => $dir . '/bowl.png', 'key' => '999/static/yxx/bowl.png'],
    ['local' => $dir . '/gourd.png', 'key' => '999/static/yxx/gourd.png'],
    ['local' => $dir . '/crab.png', 'key' => '999/static/yxx/crab.png'],
    ['local' => $dir . '/shrimp.png', 'key' => '999/static/yxx/shrimp.png'],
    ['local' => $dir . '/fish.png', 'key' => '999/static/yxx/fish.png'],
    ['local' => $dir . '/rooster.png', 'key' => '999/static/yxx/rooster.png'],
    ['local' => $dir . '/tiger.png', 'key' => '999/static/yxx/tiger.png'],
];
for ($i = 1; $i <= 18; $i++) {
    $files[] = [
        'local' => $dir . '/dice/' . $i . '.png',
        'key'   => '999/static/yxx/dice/' . $i . '.png',
    ];
}

if (!OssService::enabled()) {
    fwrite(STDERR, "OSS disabled\n");
    exit(1);
}

$base = rtrim(OssService::publicBase(), '/');
$okAll = true;
foreach ($files as $item) {
    $local = (string)$item['local'];
    if (!is_file($local)) {
        fwrite(STDERR, "missing $local\n");
        $okAll = false;
        continue;
    }
    $key = (string)$item['key'];
    $ok = OssService::putLocalFile($local, $key);
    $url = $base . '/' . $key;
    echo ($ok ? 'OK' : 'FAIL') . " $url\n";
    if (!$ok) {
        $okAll = false;
    }
}

exit($okAll ? 0 : 1);
