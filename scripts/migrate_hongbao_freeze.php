<?php
/**
 * 红包潜在赔付冻结：fans_account.hongbao_frozen + records.frozen_amount/freeze_status
 */
$root = dirname(__DIR__);
$env = @parse_ini_file($root . '/.env', true) ?: [];
$db = $env['database'] ?? [];
$host = $db['hostname'] ?? '127.0.0.1';
$name = $db['database'] ?? '';
$user = $db['username'] ?? '';
$pass = $db['password'] ?? '';
$prefix = $db['prefix'] ?? 'fa_';

if ($name === '') {
    fwrite(STDERR, "missing database in .env\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$host};dbname={$name};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function hasColumn(PDO $pdo, $table, $col)
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
}

function addColumn(PDO $pdo, $table, $col, $ddl)
{
    if (hasColumn($pdo, $table, $col)) {
        echo "[skip] {$table}.{$col}\n";
        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
    echo "[ok] {$table}.{$col}\n";
}

$account = $prefix . 'fans_account';
$records = $prefix . 'chat_red_packet_records';

addColumn(
    $pdo,
    $account,
    'hongbao_frozen',
    "`hongbao_frozen` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '红包潜在赔付冻结' AFTER `hongbao`"
);

addColumn(
    $pdo,
    $records,
    'frozen_amount',
    "`frozen_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '领取时冻结的潜在赔付额' AFTER `compensate_ledger_id`"
);

addColumn(
    $pdo,
    $records,
    'freeze_status',
    "`freeze_status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '0无 1冻结中 2已解冻' AFTER `frozen_amount`"
);

echo "done\n";
