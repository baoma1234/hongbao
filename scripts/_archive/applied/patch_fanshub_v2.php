<?php
/**
 * 福利大厅 v2 补丁：留言表 + 留言管理菜单
 * php scripts/patch_fanshub_v2.php
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

$sql = file_get_contents($root . '/sql/fanshub.sql');
if (preg_match('/CREATE TABLE IF NOT EXISTS `fa_fans_comment`[\s\S]*?;/', $sql, $m)) {
    $pdo->exec($m[0]);
    echo "OK    fa_fans_comment table\n";
}

$exists = $pdo->query("SELECT id FROM fa_auth_rule WHERE name='fanshub/comment' LIMIT 1")->fetchColumn();
if ($exists) {
    echo "SKIP  fanshub/comment menu exists\n";
    exit(0);
}

$pid = $pdo->query("SELECT id FROM fa_auth_rule WHERE name='fanshub' LIMIT 1")->fetchColumn();
if (!$pid) {
    echo "WARN  fanshub parent menu not found\n";
    exit(1);
}

$now = time();
$insert = $pdo->prepare('INSERT INTO fa_auth_rule (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

$insert->execute(['file', $pid, 'fanshub/comment', '留言管理', 'fa fa-comments', '', '', '', 1, 'addtabs', $now, $now, 6, 'normal']);
$commentId = (int)$pdo->lastInsertId();

foreach ([
    ['fanshub/comment/index', '查看'],
    ['fanshub/comment/edit', '编辑'],
    ['fanshub/comment/del', '删除'],
    ['fanshub/comment/approve', '通过'],
    ['fanshub/comment/reject', '拒绝'],
] as $item) {
    $insert->execute(['file', $commentId, $item[0], $item[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
}

echo "OK    fanshub/comment menu installed\n";
