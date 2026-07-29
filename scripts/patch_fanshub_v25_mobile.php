<?php
/**
 * 福利大厅 v25：扩展 fa_user.mobile 以支持 E.164 国际手机号（如 +8613812345678）
 * php scripts/patch_fanshub_v25_mobile.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$prefix = isset($d['prefix']) ? $d['prefix'] : 'fa_';
$table = $prefix . 'user';

$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']
);

$col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'mobile'")->fetch(PDO::FETCH_ASSOC);
if (!$col) {
    fwrite(STDERR, "column {$table}.mobile not found\n");
    exit(1);
}

if (preg_match('/varchar\((\d+)\)/i', (string)$col['Type'], $m) && (int)$m[1] >= 20) {
    echo "SKIP  {$table}.mobile already varchar({$m[1]})\n";
    exit(0);
}

$pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `mobile` varchar(20) NOT NULL DEFAULT '' COMMENT '手机号(E.164)'");
echo "OK    {$table}.mobile widened to varchar(20)\n";
