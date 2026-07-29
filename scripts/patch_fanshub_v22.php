<?php
/**
 * 福利大厅 v22：修正活动配置菜单路由（fanshub/config → fanshub.config）
 * php scripts/patch_fanshub_v22.php
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
    'fanshub/config'              => 'fanshub.config',
    'fanshub/config/index'        => 'fanshub.config/index',
    'fanshub/config/save'         => 'fanshub.config/save',
    'fanshub/config/resetcopy'    => 'fanshub.config/resetcopy',
    'fanshub/config/checklist'    => 'fanshub.config/checklist',
    'fanshub/config/testuidverify'=> 'fanshub.config/testuidverify',
    'fanshub/config/resetjackpot' => 'fanshub.config/resetjackpot',
    'fanshub/config/i18n'         => 'fanshub.config/i18n',
    'fanshub/config/savei18n'     => 'fanshub.config/savei18n',
];

$stmt = $pdo->prepare("UPDATE {$rule} SET name=? WHERE name=?");
foreach ($map as $old => $new) {
    $stmt->execute([$new, $old]);
    echo ($stmt->rowCount() ? 'OK' : 'SKIP') . "    {$old} -> {$new}\n";
}

echo "DONE  re-login admin if menu link still old\n";
