<?php
/**
 * 福利大厅 v18：独立短信配置菜单
 * php scripts/patch_fanshub_v18.php
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

$exists = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/sms' LIMIT 1")->fetchColumn();
if ($exists) {
    echo "SKIP  fanshub/sms menu exists\n";
    exit(0);
}

$rootId = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if (!$rootId) {
    fwrite(STDERR, "WARN  fanshub menu not found, run install_fanshub.php first\n");
    exit(1);
}

$insert->execute(['file', $rootId, 'fanshub.sms', '短信配置', 'fa fa-envelope', '', '', '', 1, 'addtabs', $now, $now, 7, 'normal']);
$smsId = (int)$pdo->lastInsertId();
echo "OK    fanshub/sms menu (id={$smsId})\n";

$perms = [
    ['fanshub.sms/index', '查看'],
    ['fanshub.sms/save', '保存'],
    ['fanshub.sms/testdagousms', '大狗测试'],
    ['fanshub.sms/dagoubalance', '大狗余额'],
    ['fanshub.sms/testunisms', '国际测试'],
    ['fanshub.sms/unabalance', '国际余额'],
];
foreach ($perms as $perm) {
    $name = $perm[0];
    $title = $perm[1];
    if ($pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn()) {
        echo "SKIP  {$name}\n";
        continue;
    }
    $insert->execute(['file', $smsId, $name, $title, 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
    echo "OK    {$name}\n";
}

echo "DONE  open admin -> 福利大厅 -> 短信配置\n";
