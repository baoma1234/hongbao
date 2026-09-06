<?php
/**
 * 清后台菜单 Redis/文件缓存（__menu__）
 * php scripts/clear_admin_menu_cache.php
 */
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
require $root . '/thinkphp/base.php';
\think\App::initCommon();

try {
    \think\Cache::rm('__menu__');
    echo "OK Cache::rm(__menu__)\n";
} catch (Throwable $e) {
    echo "WARN Cache::rm: " . $e->getMessage() . "\n";
}

try {
    \think\Cache::clear();
    echo "OK Cache::clear()\n";
} catch (Throwable $e) {
    echo "WARN Cache::clear: " . $e->getMessage() . "\n";
}

$cacheDir = $root . '/runtime/cache';
if (is_dir($cacheDir)) {
    $n = 0;
    foreach (glob($cacheDir . '/*') as $f) {
        if (is_file($f)) {
            @unlink($f);
            $n++;
        }
    }
    echo "OK runtime/cache removed={$n}\n";
}

// 确认大厅菜单仍在
$env = @parse_ini_file($root . '/.env', true);
$d = $env['database'] ?? [];
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $d['hostname'] ?? '127.0.0.1',
        $d['hostport'] ?? 3306,
        $d['database'] ?? ''
    ),
    $d['username'] ?? 'root',
    $d['password'] ?? ''
);
$rows = $pdo->query(
    "SELECT id,pid,name,title FROM fa_auth_rule
     WHERE name IN ('fanshub_lobby','fanshub/lobbybanner','fanshub/lobbycategory','fanshub/lobbygame','fanshub/lobbyguide','fanshub/lobbyinvite')
     ORDER BY weigh DESC"
)->fetchAll(PDO::FETCH_ASSOC);
echo "menus:\n";
foreach ($rows as $r) {
    echo "  #{$r['id']} pid={$r['pid']} {$r['name']} {$r['title']}\n";
}
echo "DONE\n";
