<?php
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password']
);
$pid = $pdo->query("SELECT id FROM fa_auth_rule WHERE name='fanshub' LIMIT 1")->fetchColumn();
if (!$pid) {
    echo "fanshub menu not found\n";
    exit(1);
}
$exists = $pdo->query("SELECT id FROM fa_auth_rule WHERE name='fanshub/index' LIMIT 1")->fetchColumn();
if ($exists) {
    echo "fanshub/index already exists\n";
    exit(0);
}
$now = time();
$stmt = $pdo->prepare('INSERT INTO fa_auth_rule (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$stmt->execute(['file', $pid, 'fanshub/index', '查看', 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
echo "added fanshub/index\n";
