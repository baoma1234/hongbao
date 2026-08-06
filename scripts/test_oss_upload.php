<?php
/**
 * 本机测试阿里云 OSS 双写：php scripts/test_oss_upload.php
 */
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . '/');
define('RUNTIME_PATH', $root . '/runtime/');
define('DS', DIRECTORY_SEPARATOR);

require $root . '/thinkphp/base.php';

// ThinkPHP 简易引导
\think\App::initCommon();

use app\common\library\OssService;

echo "enabled=" . (OssService::enabled() ? 'yes' : 'no') . PHP_EOL;
echo "publicBase=" . OssService::publicBase() . PHP_EOL;

$tmp = RUNTIME_PATH . 'oss_test_' . date('YmdHis') . '.txt';
if (!is_dir(dirname($tmp))) {
    @mkdir(dirname($tmp), 0755, true);
}
file_put_contents($tmp, 'oss dual-write test ' . date('c') . "\n");
$key = 'uploads/oss_test/' . basename($tmp);
$ok = OssService::putLocalFile($tmp, $key);
echo "put=" . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
if ($ok) {
    echo "url=" . OssService::publicUrl('/' . $key) . PHP_EOL;
}
@unlink($tmp);
