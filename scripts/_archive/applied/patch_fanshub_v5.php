<?php
/**
 * 福利大厅 v5：设备指纹 + 导出/排行榜菜单权限
 * php scripts/patch_fanshub_v5.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']
);

$col = $pdo->query("SHOW COLUMNS FROM fa_fans_login_log LIKE 'device_fingerprint'")->fetch();
if (!$col) {
    $pdo->exec("ALTER TABLE `fa_fans_login_log` ADD COLUMN `device_fingerprint` varchar(64) NOT NULL DEFAULT '' COMMENT '设备指纹' AFTER `user_agent`, ADD KEY `idx_device_fp` (`device_fingerprint`)");
    echo "OK    fa_fans_login_log.device_fingerprint\n";
} else {
    echo "SKIP  fa_fans_login_log.device_fingerprint\n";
}

$rule = 'fa_auth_rule';
$now = time();
$insert = $pdo->prepare('INSERT INTO fa_auth_rule (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

$perms = [
    ['fanshub/ledger', 'fanshub/ledger/export', '导出'],
    ['fanshub/invite', 'fanshub/invite/export', '导出'],
    ['fanshub/invite', 'fanshub/invite/leaderboard', '排行榜', 0],
    ['fanshub/task', 'fanshub/task/export', '导出'],
    ['fanshub/account', 'fanshub/account/export', '导出'],
];

foreach ($perms as $item) {
    list($parentName, $permName, $title, $isMenu) = array_pad($item, 4, 0);
    $exists = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($permName) . " LIMIT 1")->fetchColumn();
    if ($exists) {
        echo "SKIP  {$permName}\n";
        continue;
    }
    $pid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($parentName) . " LIMIT 1")->fetchColumn();
    if (!$pid) {
        echo "WARN  parent {$parentName} missing for {$permName}\n";
        continue;
    }
    if ($isMenu) {
        $insert->execute(['file', $pid, $permName, $title, 'fa fa-trophy', '', '', '', 1, 'addtabs', $now, $now, 3, 'normal']);
        $menuId = (int)$pdo->lastInsertId();
        $insert->execute(['file', $menuId, $permName . '/index', '查看', 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
        echo "OK    menu {$permName}\n";
    } else {
        $insert->execute(['file', $pid, $permName, $title, 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
        echo "OK    {$permName}\n";
    }
}

echo "Done.\n";
