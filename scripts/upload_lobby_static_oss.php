<?php
/**
 * 将 uni-999 大厅装修 static 图上传到 OSS（999/static/home/lobby/*）
 * php scripts/upload_lobby_static_oss.php
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

$dir = $root . '/uni-999/src/static/home/lobby';
if (!is_dir($dir)) {
    fwrite(STDERR, "missing $dir\n");
    exit(1);
}

$base = rtrim(OssService::publicBase(), '/');
$ok = 0;
$fail = 0;
foreach (glob($dir . '/*.{png,jpg,jpeg,webp,gif}', GLOB_BRACE) ?: [] as $local) {
    $name = basename($local);
    $key = '999/static/home/lobby/' . $name;
    $done = OssService::putLocalFile($local, $key);
    if ($done) {
        $ok++;
        echo "OK  {$base}/{$key}\n";
    } else {
        $fail++;
        echo "FAIL {$key}\n";
    }
}
echo "done ok={$ok} fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
