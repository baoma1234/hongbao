<?php
/**
 * 福利大厅 v11：留言导出 + 流水邀请类型迁移
 * php scripts/patch_fanshub_v11.php
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

$prefix = 'fa_';
$rule = $prefix . 'auth_rule';
$now = time();

$exists = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/comment/export' LIMIT 1")->fetchColumn();
if ($exists) {
    echo "SKIP  fanshub/comment/export\n";
} else {
    $pid = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/comment' LIMIT 1")->fetchColumn();
    if ($pid) {
        $stmt = $pdo->prepare('INSERT INTO fa_auth_rule (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute(['file', $pid, 'fanshub/comment/export', '导出', 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
        echo "OK    fanshub/comment/export\n";
    } else {
        echo "WARN  fanshub/comment parent missing\n";
    }
}

$updated = $pdo->exec("UPDATE {$prefix}fans_ledger SET type='invite' WHERE type='share' AND remark='邀请新用户奖励'");
echo "OK    ledger invite migrated ({$updated} rows)\n";

require __DIR__ . '/seed_h5_copy_defaults.php';
