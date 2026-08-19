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
$names = ['bowl.png', 'gourd.png', 'crab.png', 'shrimp.png', 'fish.png', 'rooster.png', 'tiger.png'];

if (!OssService::enabled()) {
    fwrite(STDERR, "OSS disabled\n");
    exit(1);
}

$base = rtrim(OssService::publicBase(), '/');
$okAll = true;
foreach ($names as $name) {
    $local = $dir . '/' . $name;
    if (!is_file($local)) {
        fwrite(STDERR, "missing $local\n");
        $okAll = false;
        continue;
    }
    $key = '999/static/yxx/' . $name;
    $ok = OssService::putLocalFile($local, $key);
    $url = $base . '/' . $key;
    echo ($ok ? 'OK' : 'FAIL') . " $url\n";
    if (!$ok) {
        $okAll = false;
    }
}

exit($okAll ? 0 : 1);
