<?php
/**
 * 真人视讯分类图 + 四款封面传 OSS，并落地分类/游戏数据。
 * php scripts/upload_lobby_live.php
 */
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . '/');
define('RUNTIME_PATH', $root . '/runtime/');
define('DS', DIRECTORY_SEPARATOR);

require $root . '/thinkphp/base.php';
\think\App::initCommon();

use app\common\library\FansHubLobby;
use app\common\library\OssService;

$dir = $root . '/uni-999/src/static/home/lobby';
$files = [
    'cat-live.png',
    'og-baccarat.png',
    'og-dragon.png',
    'og-roulette.png',
    'og-niuniu.png',
];

if (!OssService::enabled()) {
    fwrite(STDERR, "OSS disabled\n");
    exit(1);
}

$base = rtrim(OssService::publicBase(), '/');
$okAll = true;
foreach ($files as $name) {
    $local = $dir . '/' . $name;
    if (!is_file($local)) {
        fwrite(STDERR, "missing $local\n");
        $okAll = false;
        continue;
    }
    $key = '999/static/home/lobby/' . $name;
    $ok = OssService::putLocalFile($local, $key);
    $url = $base . '/' . $key;
    echo ($ok ? 'OK' : 'FAIL') . " $url\n";
    if (!$ok) {
        $okAll = false;
    }
}

FansHubLobby::ensureOgLobby();
echo "OK ensureOgLobby\n";

exit($okAll ? 0 : 1);
