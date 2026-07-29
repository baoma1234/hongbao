<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=caijin_com_7111;charset=utf8mb4', 'caijin_com_7111', 'zJ3EkWE47y');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function colExists(PDO $pdo, $table, $col)
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

if (!colExists($pdo, 'fa_fans_account', 'hongbao')) {
    $pdo->exec("ALTER TABLE fa_fans_account ADD COLUMN hongbao decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '红宝余额' AFTER balance");
    echo "added account.hongbao\n";
} else {
    echo "account.hongbao exists\n";
}

if (!colExists($pdo, 'fa_fans_ledger', 'hongbao_change')) {
    $pdo->exec("ALTER TABLE fa_fans_ledger ADD COLUMN hongbao_change decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '红宝变动' AFTER balance_change");
    echo "added ledger.hongbao_change\n";
} else {
    echo "ledger.hongbao_change exists\n";
}

if (!colExists($pdo, 'fa_fans_ledger', 'hongbao_after')) {
    $pdo->exec("ALTER TABLE fa_fans_ledger ADD COLUMN hongbao_after decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '红宝结余' AFTER balance_after");
    echo "added ledger.hongbao_after\n";
} else {
    echo "ledger.hongbao_after exists\n";
}

echo "ok\n";
