<?php
/**
 * 红包公平性 SHA-256 字段
 * php scripts/patch_red_packet_fair.php
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
$table = $prefix . 'chat_red_packets';

function columnExists(PDO $pdo, $table, $column)
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetchColumn();
}

function addColumn(PDO $pdo, $table, $column, $ddl)
{
    if (columnExists($pdo, $table, $column)) {
        echo "SKIP {$table}.{$column}\n";
        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
    echo "OK   {$table}.{$column}\n";
}

addColumn($pdo, $table, 'fair_hash', "`fair_hash` char(64) NOT NULL DEFAULT '' COMMENT 'SHA256承诺(拼手气/扫雷)' AFTER `blessing`");
addColumn($pdo, $table, 'fair_seed', "`fair_seed` varchar(64) NOT NULL DEFAULT '' COMMENT '开奖种子(开奖后公开)' AFTER `fair_hash`");
addColumn($pdo, $table, 'fair_cents', "`fair_cents` text NULL COMMENT '预拆金额分列表CSV' AFTER `fair_seed`");
addColumn($pdo, $table, 'fair_payload', "`fair_payload` text NULL COMMENT '哈希明文(开奖后公开)' AFTER `fair_cents`");
addColumn($pdo, $table, 'fair_revealed_at', "`fair_revealed_at` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '公平性开奖时间' AFTER `fair_payload`");

try {
    $pdo->exec("ALTER TABLE `{$table}` ADD KEY `idx_fair_hash` (`fair_hash`)");
    echo "OK   idx_fair_hash\n";
} catch (PDOException $e) {
    echo "SKIP idx_fair_hash: " . $e->getMessage() . "\n";
}

echo "DONE\n";
