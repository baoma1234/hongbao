<?php
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . '/');
define('RUNTIME_PATH', $root . '/runtime/');
define('DS', DIRECTORY_SEPARATOR);

require $root . '/thinkphp/base.php';
\think\App::initCommon();

use app\common\library\OssService;

$local = $root . '/uni-999/src/static/home/lobby/commission.png';
$key = '999/static/home/lobby/commission.png';

if (!is_file($local)) {
    fwrite(STDERR, "missing $local\n");
    exit(1);
}
if (!OssService::enabled()) {
    fwrite(STDERR, "OSS disabled\n");
    exit(1);
}
$ok = OssService::putLocalFile($local, $key);
$url = rtrim(OssService::publicBase(), '/') . '/' . $key;
echo ($ok ? 'OK' : 'FAIL') . " $url\n";
exit($ok ? 0 : 1);
