<?php
/**
 * 修复活动配置子菜单路由：fanshub.config/xxx → fanshub/config/xxx
 * 避免 path 被解析成 action=fanshub.config
 * php scripts/fix_activity_config_routes.php
 */
$root = dirname(__DIR__);
$env = is_file($root . '/.env') ? parse_ini_file($root . '/.env', true) : [];
$d = $env['database'] ?? [];
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $d['hostname'] ?? '127.0.0.1', $d['hostport'] ?: '3306', $d['database'] ?? ''),
    $d['username'] ?? 'root',
    $d['password'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$rule = ($d['prefix'] ?? 'fa_') . 'auth_rule';
$now = time();

$map = [
    'fanshub.config/basic' => 'fanshub/config/basic',
    'fanshub.config/exchange' => 'fanshub/config/exchange',
    'fanshub.config/invite' => 'fanshub/config/invite',
    'fanshub.config/copy' => 'fanshub/config/copy',
    'fanshub.config/market' => 'fanshub/config/market',
    'fanshub.config/security' => 'fanshub/config/security',
    'fanshub.config/i18n' => 'fanshub/config/i18n',
    'fanshub.config/index' => 'fanshub/config/index',
    'fanshub.config/save' => 'fanshub/config/save',
    'fanshub.config/resetcopy' => 'fanshub/config/resetcopy',
    'fanshub.config/checklist' => 'fanshub/config/checklist',
    'fanshub.config/testuidverify' => 'fanshub/config/testuidverify',
    'fanshub.config/resetjackpot' => 'fanshub/config/resetjackpot',
    'fanshub.config/savei18n' => 'fanshub/config/savei18n',
];

$stmt = $pdo->prepare("UPDATE `{$rule}` SET `name`=?, `updatetime`=? WHERE `name`=?");
foreach ($map as $old => $new) {
    // 若目标已存在则删旧留新
    $existsNew = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name=" . $pdo->quote($new) . " LIMIT 1")->fetchColumn();
    $existsOld = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name=" . $pdo->quote($old) . " LIMIT 1")->fetchColumn();
    if ($existsOld && $existsNew) {
        $pdo->prepare("DELETE FROM `{$rule}` WHERE name=?")->execute([$old]);
        echo "DEL duplicate old {$old} (keep {$new})\n";
        continue;
    }
    if (!$existsOld) {
        echo "SKIP missing {$old}\n";
        continue;
    }
    $stmt->execute([$new, $now, $old]);
    echo "OK  {$old} -> {$new}\n";
}

// 父级保持 fanshub.config，显式 url 指向总览（斜杠路由）
$parentId = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub.config' LIMIT 1")->fetchColumn();
if ($parentId > 0) {
    $pdo->prepare("UPDATE `{$rule}` SET `url`='', `updatetime`=? WHERE id=?")->execute([$now, $parentId]);
}

$cacheDir = $root . '/runtime/cache';
if (is_dir($cacheDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            @unlink($f->getPathname());
        }
    }
    echo "CLEARED runtime/cache\n";
}

echo "DONE\n";
