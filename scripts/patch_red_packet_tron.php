<?php
/**
 * 红包波场公平性字段
 * php scripts/patch_red_packet_tron.php
 */
$root = dirname(__DIR__);
$envFile = $root . DIRECTORY_SEPARATOR . '.env';
$host = '127.0.0.1';
$port = 3306;
$dbName = 'caijin_com_7111';
$user = 'root';
$pass = '';
$prefix = 'fa_';
if (is_file($envFile)) {
    $ini = parse_ini_file($envFile, true);
    if (!empty($ini['database'])) {
        $d = $ini['database'];
        $host = $d['hostname'] ?? $host;
        $port = (int)($d['hostport'] ?? $port);
        $dbName = $d['database'] ?? $dbName;
        $user = $d['username'] ?? $user;
        $pass = $d['password'] ?? $pass;
        $prefix = $d['prefix'] ?? $prefix;
    }
}
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$table = $prefix . 'chat_red_packets';

function hasColumn(PDO $pdo, $table, $col)
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
}

function addColumn(PDO $pdo, $table, $col, $ddl)
{
    if (hasColumn($pdo, $table, $col)) {
        echo "SKIP {$col}\n";
        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
    echo "OK   {$col}\n";
}

addColumn($pdo, $table, 'tron_block_num', "`tron_block_num` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '波场目标区块高度' AFTER `blessing`");
addColumn($pdo, $table, 'tron_block_id', "`tron_block_id` varchar(64) NOT NULL DEFAULT '' COMMENT '波场区块哈希block_id' AFTER `tron_block_num`");
addColumn($pdo, $table, 'tron_lucky', "`tron_lucky` char(1) NOT NULL DEFAULT '' COMMENT '区块哈希末位幸运尾数' AFTER `tron_block_id`");
addColumn($pdo, $table, 'tron_status', "`tron_status` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '0无1待开2成功3失败' AFTER `tron_lucky`");

try {
    $pdo->exec("ALTER TABLE `{$table}` ADD KEY `idx_tron_status` (`tron_status`)");
    echo "OK   idx_tron_status\n";
} catch (Throwable $e) {
    echo "SKIP idx_tron_status: " . $e->getMessage() . "\n";
}

echo "DONE\n";
