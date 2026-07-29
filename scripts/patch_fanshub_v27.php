<?php
/**
 * 福利大厅 v27：二期后台可观测性 — 签到记录菜单
 * php scripts/patch_fanshub_v27.php
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

$rule = 'fa_auth_rule';
$now = time();
$insert = $pdo->prepare('INSERT INTO fa_auth_rule (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

$exists = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/checkin' LIMIT 1")->fetchColumn();
if ($exists) {
    echo "SKIP  fanshub/checkin menu exists\n";
    exit(0);
}

$rootId = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if (!$rootId) {
    fwrite(STDERR, "WARN  fanshub menu not found, run install_fanshub.php first\n");
    exit(1);
}

$insert->execute(['file', $rootId, 'fanshub/checkin', '签到记录', 'fa fa-calendar-check-o', '', '', '星火二期签到', 1, 'addtabs', $now, $now, 3, 'normal']);
$menuId = (int)$pdo->lastInsertId();
echo "OK    fanshub/checkin menu (id={$menuId})\n";

$perms = [
    ['fanshub/checkin/index', '查看'],
    ['fanshub/checkin/export', '导出'],
];
foreach ($perms as $perm) {
    $name = $perm[0];
    $title = $perm[1];
    if ($pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn()) {
        echo "SKIP  {$name}\n";
        continue;
    }
    $insert->execute(['file', $menuId, $name, $title, 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
    echo "OK    {$name}\n";
}

echo "DONE  re-login admin if menu not visible; open 福利大厅 -> 签到记录\n";
