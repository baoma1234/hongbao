<?php
/**
 * Upload Apple touch icon (v2 filename) to OSS.
 *
 * Usage:
 *   php scripts/upload_apple_touch_icon.php
 */

$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . '/');
define('RUNTIME_PATH', $root . '/runtime/');
define('DS', DIRECTORY_SEPARATOR);

require $root . '/thinkphp/base.php';
\think\App::initCommon();

use app\common\library\OssService;

$dir = $root . '/public/uploads/brand';
$name = 'apple-touch-icon-1024-v2.png';
$local = $dir . '/' . $name;

if (!OssService::enabled()) {
    fwrite(STDERR, "OSS disabled\n");
    exit(1);
}

if (!is_file($local)) {
    fwrite(STDERR, "missing $local\n");
    exit(1);
}

$base = rtrim(OssService::publicBase(), '/');
$key = 'uploads/brand/' . $name;
$ok = OssService::putLocalFile($local, $key);
$url = $base . '/' . $key;

echo ($ok ? 'OK' : 'FAIL') . " $url\n";
exit($ok ? 0 : 1);

