<?php
/**
 * 福利大厅 v3：邀请IP字段 + 邀请记录菜单
 * php scripts/patch_fanshub_v3.php
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

$cols = $pdo->query("SHOW COLUMNS FROM fa_fans_invite LIKE 'invitee_ip'")->fetch();
if (!$cols) {
    $pdo->exec("ALTER TABLE `fa_fans_invite`
        ADD COLUMN `invitee_ip` varchar(45) NOT NULL DEFAULT '' AFTER `invitee_mobile`,
        ADD COLUMN `inviter_ip` varchar(45) NOT NULL DEFAULT '' AFTER `invitee_ip`,
        ADD KEY `idx_invitee_ip` (`invitee_ip`)");
    echo "OK    fa_fans_invite ip columns\n";
} else {
    echo "SKIP  fa_fans_invite ip columns exist\n";
}

$exists = $pdo->query("SELECT id FROM fa_auth_rule WHERE name='fanshub/invite' LIMIT 1")->fetchColumn();
if ($exists) {
    echo "SKIP  fanshub/invite menu exists\n";
    exit(0);
}

$pid = $pdo->query("SELECT id FROM fa_auth_rule WHERE name='fanshub' LIMIT 1")->fetchColumn();
if (!$pid) {
    echo "WARN  fanshub menu not found\n";
    exit(1);
}

$now = time();
$insert = $pdo->prepare('INSERT INTO fa_auth_rule (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$insert->execute(['file', $pid, 'fanshub/invite', '邀请记录', 'fa fa-share-alt', '', '', '', 1, 'addtabs', $now, $now, 5, 'normal']);
$inviteId = (int)$pdo->lastInsertId();
$insert->execute(['file', $inviteId, 'fanshub/invite/index', '查看', 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
echo "OK    fanshub/invite menu installed\n";
