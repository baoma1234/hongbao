<?php
/**
 * 用户账户：晋升团长权限
 * php scripts/patch_fanshub_promotemaster.php
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
$accPid = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/account' LIMIT 1")->fetchColumn();
if (!$accPid) {
    fwrite(STDERR, "WARN  fanshub/account menu missing\n");
    exit(1);
}
$name = 'fanshub/account/promotemaster';
$title = '晋升团长';
if ($pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn()) {
    echo "SKIP  {$name}\n";
    exit(0);
}
$insert->execute(['file', $accPid, $name, $title, 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
echo "OK    {$name}\n";
echo "请在角色组勾选「晋升团长」，并清除后台权限缓存后刷新\n";
