#!/usr/bin/env php
<?php
/**
 * CentOS / 线上：左侧菜单不出新项时用
 * php scripts/fix_admin_sidebar_menu.php
 *
 * 典型现象：后台「权限管理→菜单规则」能看到，左侧栏没有。
 * 原因：Redis tp:__menu__ 旧缓存 + auth_type=2 的 Session 权限列表 + 偶发 OPcache。
 */
$root = dirname(__DIR__);
chdir($root);

echo "=== 1) 检查关键文件 ===\n";
$authFile = $root . '/application/admin/library/Auth.php';
$authSrc = is_file($authFile) ? file_get_contents($authFile) : '';
$hasSuper = (strpos($authSrc, '$isSuper') !== false);
echo $hasSuper
    ? "OK Auth.php 已含超管 * 侧栏修复（需 git pull 到 69975333+）\n"
    : "MISS Auth.php 缺少 \$isSuper 修复 → 先 git pull origin main\n";

echo "=== 2) 检查菜单规则 ===\n";
$env = @parse_ini_file($root . '/.env', true);
if (!$env) {
    fwrite(STDERR, ".env 读失败\n");
    exit(1);
}
$d = $env['database'];
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $d['hostname'] ?? '127.0.0.1', $d['hostport'] ?? 3306, $d['database'] ?? ''),
    $d['username'] ?? 'root',
    $d['password'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$need = [
    'fanshub/redpacketrain' => '红宝雨机器人',
    'fanshub/redpacketrain/index' => '红宝雨-查看',
    'fanshub/videocrawl' => '视频采集',
    'fanshub/videocrawl/index' => '视频采集-查看',
];
foreach ($need as $name => $label) {
    $row = $pdo->query('SELECT id,pid,ismenu,status FROM fa_auth_rule WHERE name=' . $pdo->quote($name) . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo "MISS {$label} ({$name}) → 跑 php scripts/deploy_rp_rain.php / install_videocrawl.php\n";
        continue;
    }
    echo sprintf(
        "OK   %-28s id=%s ismenu=%s status=%s\n",
        $name,
        $row['id'],
        $row['ismenu'],
        $row['status']
    );
}

echo "=== 3) 删 Redis 菜单键（扫 0~5 库）===\n";
$redisCfg = $env['redis'] ?? [];
$deleted = 0;
try {
    if (!class_exists('Redis')) {
        echo "WARN PHP redis 扩展未装，跳过 Redis\n";
    } else {
        $rr = new Redis();
        $rr->connect((string)($redisCfg['host'] ?? '127.0.0.1'), (int)($redisCfg['port'] ?? 6379), 2);
        $pass = (string)($redisCfg['password'] ?? '');
        if ($pass !== '') {
            $rr->auth($pass);
        }
        for ($db = 0; $db <= 5; $db++) {
            $rr->select($db);
            foreach (['tp:__menu__', '__menu__', 'im:__menu__'] as $k) {
                if ($rr->exists($k)) {
                    $rr->del($k);
                    $deleted++;
                    echo "DEL db={$db} {$k}\n";
                }
            }
            foreach ($rr->keys('*menu*') ?: [] as $k) {
                $rr->del($k);
                $deleted++;
                echo "DEL db={$db} {$k}\n";
            }
        }
        echo $deleted ? "OK Redis 已删 {$deleted} 个键\n" : "OK Redis 无菜单缓存键（可能已清）\n";
    }
} catch (Throwable $e) {
    echo 'WARN Redis: ' . $e->getMessage() . "\n";
}

echo "=== 4) ThinkPHP Cache::rm(__menu__) ===\n";
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
require $root . '/thinkphp/base.php';
\think\App::initCommon();
try {
    \think\Cache::rm('__menu__');
    echo "OK Cache::rm(__menu__)\n";
} catch (Throwable $e) {
    echo 'WARN ' . $e->getMessage() . "\n";
}

echo "=== 5) 删 Session（强制重新登录）===\n";
$sessionDirs = [
    $root . '/runtime/session',
    $root . '/runtime/temp',
];
$sn = 0;
foreach ($sessionDirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            @unlink($f->getPathname());
            $sn++;
        }
    }
}
echo "OK 删除 session/temp 文件约 {$sn} 个\n";

echo "=== 6) 下一步（必须做）===\n";
echo "A) 重启 PHP-FPM（清 OPcache，否则 Auth.php 修复不生效）:\n";
echo "   systemctl restart php-fpm\n";
echo "   # 或宝塔: systemctl restart php-81-php-fpm / php-80-php-fpm\n";
echo "B) 浏览器退出后台，清站点 Cookie 后重新登录\n";
echo "C) 左侧应出现：玩法大全→红宝雨机器人；即时通讯→视频采集\n";
echo "DONE\n";
