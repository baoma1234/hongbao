<?php
/**
 * 福利大厅 v21：独立会员等级配置菜单
 * php scripts/patch_fanshub_v21.php
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

$exists = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub.memberlevel' LIMIT 1")->fetchColumn();
if ($exists) {
    echo "SKIP  fanshub.memberlevel menu exists\n";
    exit(0);
}

$rootId = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if (!$rootId) {
    fwrite(STDERR, "WARN  fanshub menu not found, run install_fanshub.php first\n");
    exit(1);
}

$insert->execute(['file', $rootId, 'fanshub.memberlevel', '会员等级配置', 'fa fa-star', '', '', '', 1, 'addtabs', $now, $now, 6, 'normal']);
$menuId = (int)$pdo->lastInsertId();
echo "OK    fanshub.memberlevel menu (id={$menuId})\n";

$perms = [
    ['fanshub.memberlevel/index', '查看'],
    ['fanshub.memberlevel/save', '保存'],
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

echo "DONE  open admin -> 福利大厅 -> 会员等级配置 (re-login if menu not visible)\n";
