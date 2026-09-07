<?php
/**
 * 上传登录页静态图到 OSS（999/static/login/*）
 * php scripts/upload_login_static_oss.php
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

$dir = $root . '/uni-999/src/static/login';
if (!is_dir($dir)) {
    fwrite(STDERR, "missing $dir\n");
    exit(1);
}

$want = ['bj.jpg', 'logo-l.png', 'bg-hero.jpg', 'bg-hero.png'];
$base = rtrim(OssService::publicBase(), '/');
$ok = 0;
$fail = 0;
foreach ($want as $name) {
    $local = $dir . '/' . $name;
    if (!is_file($local)) {
        echo "SKIP missing {$name}\n";
        continue;
    }
    $key = '999/static/login/' . $name;
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
