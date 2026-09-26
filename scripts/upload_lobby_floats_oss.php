<?php
/**
 * 上传左侧浮标三图到 OSS
 * php scripts/upload_lobby_floats_oss.php
 */
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . '/');
define('RUNTIME_PATH', $root . '/runtime/');
define('DS', DIRECTORY_SEPARATOR);

require $root . '/thinkphp/base.php';
\think\App::initCommon();

use app\common\library\OssService;

if (!OssService::enabled()) {
    fwrite(STDERR, "OSS disabled\n");
    exit(1);
}

$files = ['float-caijin.png', 'float-video.png', 'float-haiwai.png'];
$base = rtrim(OssService::publicBase(), '/');
$fail = 0;
foreach ($files as $name) {
    $local = $root . '/uni-999/src/static/home/lobby/' . $name;
    $key = '999/static/home/lobby/' . $name;
    if (!is_file($local)) {
        echo "MISS {$local}\n";
        $fail++;
        continue;
    }
    $ok = OssService::putLocalFile($local, $key);
    echo ($ok ? 'OK  ' : 'FAIL ') . "{$base}/{$key}\n";
    if (!$ok) {
        $fail++;
    }
}
exit($fail > 0 ? 1 : 0);
