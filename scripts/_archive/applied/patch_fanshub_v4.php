<?php
/**
 * 福利大厅 v4：登录日志/任务/幂等表 + 流水通道字段
 * php scripts/patch_fanshub_v4.php
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
foreach (['fa_fans_login_log', 'fa_fans_task', 'fa_fans_idempotent'] as $table) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS `' . $table . '`[\s\S]*?;/', $sql, $m)) {
        $pdo->exec($m[0]);
        echo "OK    {$table}\n";
    }
}

$col = $pdo->query("SHOW COLUMNS FROM fa_fans_ledger LIKE 'channel'")->fetch();
if (!$col) {
    $pdo->exec("ALTER TABLE `fa_fans_ledger` ADD COLUMN `channel` varchar(64) NOT NULL DEFAULT '' COMMENT '闪兑通道' AFTER `remark`");
    echo "OK    fa_fans_ledger.channel\n";
} else {
    echo "SKIP  fa_fans_ledger.channel\n";
}

$exists = $pdo->query("SELECT id FROM fa_auth_rule WHERE name='fanshub/task' LIMIT 1")->fetchColumn();
if ($exists) {
    echo "SKIP  fanshub/task menu\n";
    exit(0);
}

$pid = $pdo->query("SELECT id FROM fa_auth_rule WHERE name='fanshub' LIMIT 1")->fetchColumn();
if (!$pid) {
    echo "WARN  fanshub menu missing\n";
    exit(1);
}

$now = time();
$insert = $pdo->prepare('INSERT INTO fa_auth_rule (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$insert->execute(['file', $pid, 'fanshub/task', '任务记录', 'fa fa-tasks', '', '', '', 1, 'addtabs', $now, $now, 4, 'normal']);
$taskId = (int)$pdo->lastInsertId();
$insert->execute(['file', $taskId, 'fanshub/task/index', '查看', 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
$accPid = $pdo->query("SELECT id FROM fa_auth_rule WHERE name='fanshub/account' LIMIT 1")->fetchColumn();
if ($accPid && !$pdo->query("SELECT id FROM fa_auth_rule WHERE name='fanshub/account/detail' LIMIT 1")->fetchColumn()) {
    $insert->execute(['file', $accPid, 'fanshub/account/detail', '详情', 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
    echo "OK    fanshub/account/detail permission\n";
}
echo "OK    fanshub/task menu\n";
