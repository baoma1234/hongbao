<?php
/**
 * 给超级管理员组挂上「重启聊天服务」节点（可重复执行）
 *   php scripts/add_im_restart_auth_rule.php
 */
$root = dirname(__DIR__);
require $root . '/thinkphp/base.php';

// 用原生 PDO，避免依赖完整 App 启动
$dbFile = $root . '/application/database.php';
if (!is_file($dbFile)) {
    fwrite(STDERR, "database.php missing\n");
    exit(1);
}
$cfg = include $dbFile;
$host = $cfg['hostname'] ?? '127.0.0.1';
$port = $cfg['hostport'] ?? '3306';
$name = $cfg['database'] ?? '';
$user = $cfg['username'] ?? '';
$pass = $cfg['password'] ?? '';
$prefix = $cfg['prefix'] ?? 'fa_';
$dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$parentName = 'fanshub/redpacketauto';
$ruleName = 'fanshub/redpacketauto/restartim';
$st = $pdo->prepare("SELECT id FROM {$prefix}auth_rule WHERE name=? LIMIT 1");
$st->execute([$parentName]);
$parent = $st->fetch(PDO::FETCH_ASSOC);
if (!$parent) {
    echo "parent rule {$parentName} not found, skip insert (superadmin still works)\n";
    exit(0);
}
$pid = (int)$parent['id'];
$st->execute([$ruleName]);
$exists = $st->fetch(PDO::FETCH_ASSOC);
$now = time();
if ($exists) {
    echo "rule already exists id=" . $exists['id'] . "\n";
    exit(0);
}
$ins = $pdo->prepare(
    "INSERT INTO {$prefix}auth_rule
    (type,pid,name,title,icon,condition,remark,ismenu,menutype,extend,py,pinyin,createtime,updatetime,weigh,status)
    VALUES ('file',?,?,'重启聊天服务','fa fa-power-off','','一键执行 im-server restart-all',0,NULL,'','','',?, ?,0,'normal')"
);
$ins->execute([$pid, $ruleName, $now, $now]);
echo "inserted rule {$ruleName} id=" . $pdo->lastInsertId() . "\n";
