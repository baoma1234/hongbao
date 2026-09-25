<?php
/**
 * 大厅四分类图标上传 OSS 加速。
 * php scripts/upload_lobby_cats.php
 */
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . '/');
define('RUNTIME_PATH', $root . '/runtime/');
define('DS', DIRECTORY_SEPARATOR);

require $root . '/thinkphp/base.php';
\think\App::initCommon();

use app\common\library\OssService;
use think\Db;

$dir = $root . '/uni-999/src/static/home/lobby';
$files = [
    '1.png',
    '2.png',
    '3.png',
    '4.png',
    'cat-1.png',
    'cat-2.png',
    'cat-3.png',
    'cat-4.png',
    'cat-live.png',
    'commission.png',
    'fission-hongbao.png',
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

// 后台分类 icon 指到本地种子路径（前端走 OSS 同名）
$now = time();
$iconMap = [
    'games'      => 'home/lobby/cat-1.png',
    'hot'        => 'home/lobby/cat-1.png',
    'live'       => 'home/lobby/cat-live.png',
    'commission' => 'home/lobby/cat-4.png',
    'fission'    => 'home/lobby/fission-hongbao.png',
    'notice'     => 'home/lobby/fission-hongbao.png',
];
foreach ($iconMap as $key => $icon) {
    try {
        $n = Db::name('fans_lobby_categories')->where('cat_key', $key)->update([
            'icon'        => $icon,
            'icon_static' => '',
            'updatetime'  => $now,
        ]);
        echo "DB cat_key={$key} updated={$n} icon={$icon}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "DB {$key}: " . $e->getMessage() . "\n");
    }
}

exit($okAll ? 0 : 1);
