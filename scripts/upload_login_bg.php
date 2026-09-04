<?php
/**
 * Upload login hero background to OSS accelerate.
 * php scripts/upload_login_bg.php
 */
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . '/');
define('RUNTIME_PATH', $root . '/runtime/');
define('DS', DIRECTORY_SEPARATOR);

require $root . '/thinkphp/base.php';
\think\App::initCommon();

use app\common\library\OssService;

$local = $root . '/uni-999/src/static/login/bg-hero.png';
$key = '999/static/login/bg-hero.png';

if (!is_file($local)) {
    fwrite(STDERR, "missing $local\n");
    exit(1);
}

if (!OssService::enabled()) {
    fwrite(STDERR, "OSS disabled\n");
    exit(1);
}

$base = rtrim(OssService::publicBase(), '/');
$ok = OssService::putLocalFile($local, $key);
$url = $base . '/' . $key;
echo ($ok ? 'OK' : 'FAIL') . " $url\n";
exit($ok ? 0 : 1);
