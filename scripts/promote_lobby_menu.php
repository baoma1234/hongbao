<?php
/**
 * 把「大厅装修」提升为顶级菜单，并清菜单缓存
 * php scripts/promote_lobby_menu.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $d['hostname'] ?? '127.0.0.1',
        $d['hostport'] ?? 3306,
        $d['database'] ?? ''
    ),
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$rule = $prefix . 'auth_rule';
$now = time();

$row = $pdo->query("SELECT id,pid,name,title FROM {$rule} WHERE name='fanshub_lobby' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    fwrite(STDERR, "fanshub_lobby missing, run php scripts/install_lobby_home.php first\n");
    exit(1);
}

// 与会员运营/即时通讯同级的顶级菜单
$pdo->prepare(
    "UPDATE {$rule} SET pid=0, ismenu=1, status='normal', title='大厅装修', icon='fa fa-th-large', weigh=47, updatetime=? WHERE id=?"
)->execute([$now, (int)$row['id']]);
echo "OK fanshub_lobby #{$row['id']} pid=0 (was pid={$row['pid']})\n";

// 确保子菜单挂在大厅装修下且可见
$children = [
    'fanshub/lobbybanner' => ['轮播图管理', 'fa fa-picture-o', 40],
    'fanshub/lobbycategory' => ['大厅分类管理', 'fa fa-th', 30],
    'fanshub/lobbygame' => ['大厅分类游戏管理', 'fa fa-gamepad', 20],
    'fanshub/lobbyguide' => ['玩法说明', 'fa fa-book', 15],
    'fanshub/lobbyinvite' => ['邀请条管理', 'fa fa-gift', 10],
];
$parentId = (int)$row['id'];
foreach ($children as $name => $meta) {
    $st = $pdo->prepare("SELECT id FROM {$rule} WHERE name=? LIMIT 1");
    $st->execute([$name]);
    $cid = (int)$st->fetchColumn();
    if ($cid <= 0) {
        echo "MISS {$name}\n";
        continue;
    }
    $pdo->prepare(
        "UPDATE {$rule} SET pid=?, ismenu=1, status='normal', title=?, icon=?, weigh=?, updatetime=? WHERE id=?"
    )->execute([$parentId, $meta[0], $meta[1], $meta[2], $now, $cid]);
    echo "OK {$name} #{$cid} -> pid={$parentId}\n";
}

// 清 Redis 菜单缓存
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
require $root . '/thinkphp/base.php';
\think\App::initCommon();
try {
    \think\Cache::rm('__menu__');
    \think\Cache::clear();
    echo "OK menu cache cleared\n";
} catch (Throwable $e) {
    echo "WARN cache: " . $e->getMessage() . "\n";
}

echo "DONE — refresh admin or re-login\n";
