<?php
/**
 * Upload game lobby grid 01.png–06.png to OSS accelerate.
 * php scripts/upload_lobby_games.php
 */
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . '/');
define('RUNTIME_PATH', $root . '/runtime/');
define('DS', DIRECTORY_SEPARATOR);

require $root . '/thinkphp/base.php';
\think\App::initCommon();

use app\common\library\OssService;

$dir = $root . '/uni-999/src/static/home/lobby';
$files = [];
for ($i = 1; $i <= 6; $i++) {
    $n = sprintf('%02d.png', $i);
    $files[] = [
        'local' => $dir . '/' . $n,
        'key'   => '999/static/home/lobby/' . $n,
    ];
}
foreach (['750x400.png', '750x150.png'] as $bn) {
    $files[] = [
        'local' => $dir . '/' . $bn,
        'key'   => '999/static/home/lobby/' . $bn,
    ];
}
foreach (['detail-01.png', 'detail-02.png', 'detail-03.png', 'detail-04.png', 'detail-06.png'] as $dn) {
    $files[] = [
        'local' => $dir . '/' . $dn,
        'key'   => '999/static/home/lobby/' . $dn,
    ];
}
foreach (['1.png', '2.png', '3.png', '4.png', '66.png', '77.png', '88.png'] as $cn) {
    $files[] = [
        'local' => $dir . '/' . $cn,
        'key'   => '999/static/home/lobby/' . $cn,
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
