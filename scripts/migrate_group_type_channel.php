<?php
/**
 * 群组类型：group=普通群组，channel=频道（社群「频道群组」Tab）
 * php scripts/migrate_group_type_channel.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$table = $prefix . 'chat_groups';

$col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'group_type'")->fetch(PDO::FETCH_ASSOC);
if (!$col) {
    $pdo->exec(
        "ALTER TABLE `{$table}` ADD COLUMN `group_type` varchar(16) NOT NULL DEFAULT 'group'"
        . " COMMENT 'group=群组 channel=频道' AFTER `privacy_mode`"
    );
    echo "OK added group_type\n";
} else {
    echo "OK group_type exists\n";
}

$idx = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name='idx_group_type'")->fetch(PDO::FETCH_ASSOC);
if (!$idx) {
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `idx_group_type` (`group_type`)");
        echo "OK idx_group_type\n";
    } catch (Throwable $e) {
        echo "SKIP idx: " . $e->getMessage() . "\n";
    }
} else {
    echo "SKIP idx_group_type\n";
}

$st = $pdo->prepare("UPDATE `{$table}` SET group_type='channel' WHERE id IN (70,71,72)");
$st->execute();
echo "OK marked channel ids 70,71,72 rows=" . $st->rowCount() . "\n";
echo "DONE\n";
