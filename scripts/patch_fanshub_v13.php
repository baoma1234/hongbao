<?php
/**
 * 福利大厅 v13：多语言收尾 + main_uid 唯一约束
 * php scripts/patch_fanshub_v13.php
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

$table = 'fa_fans_account';
$col = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'main_uid_unique'")->fetch();
if ($col) {
    echo "SKIP  {$table}.main_uid_unique\n";
} else {
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `main_uid_unique` varchar(64) GENERATED ALWAYS AS (IF(`main_uid` = '', NULL, `main_uid`)) STORED");
    echo "OK    {$table}.main_uid_unique column\n";
}

$idx = $pdo->query("SHOW INDEX FROM {$table} WHERE Key_name='uk_main_uid_nonempty'")->fetch();
if ($idx) {
    echo "SKIP  {$table}.uk_main_uid_nonempty\n";
} else {
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `uk_main_uid_nonempty` (`main_uid_unique`)");
        echo "OK    {$table}.uk_main_uid_nonempty\n";
    } catch (PDOException $e) {
        echo "WARN  uk_main_uid_nonempty failed: " . $e->getMessage() . "\n";
    }
}

require __DIR__ . '/generate_i18n_locales.php';
require __DIR__ . '/seed_h5_copy_defaults.php';
echo "OK    i18n + h5_copy synced\n";
