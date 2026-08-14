<?php
/**
 * 福利大厅 v19：修正短信配置菜单路由（fanshub/sms → fanshub.sms）
 * php scripts/patch_fanshub_v19.php
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
$map = [
    'fanshub/sms'            => 'fanshub.sms',
    'fanshub/sms/index'      => 'fanshub.sms/index',
    'fanshub/sms/save'       => 'fanshub.sms/save',
    'fanshub/sms/testdagousms' => 'fanshub.sms/testdagousms',
    'fanshub/sms/dagoubalance' => 'fanshub.sms/dagoubalance',
    'fanshub/sms/testunisms' => 'fanshub.sms/testunisms',
    'fanshub/sms/unabalance' => 'fanshub.sms/unabalance',
];

$stmt = $pdo->prepare("UPDATE {$rule} SET name=? WHERE name=?");
foreach ($map as $old => $new) {
    $stmt->execute([$new, $old]);
    echo ($stmt->rowCount() ? 'OK' : 'SKIP') . "    {$old} -> {$new}\n";
}

echo "DONE  re-login admin if menu link still old\n";
