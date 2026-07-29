<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=caijin_com_7111;charset=utf8mb4', 'caijin_com_7111', 'zJ3EkWE47y');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function colExists(PDO $pdo, $table, $col)
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

if (!colExists($pdo, 'fa_fans_account', 'rights_locked')) {
    $pdo->exec("ALTER TABLE fa_fans_account ADD COLUMN rights_locked decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '当日兑入锁定股份(T+1可兑出)' AFTER rights");
    echo "added account.rights_locked\n";
} else {
    echo "account.rights_locked exists\n";
}

if (!colExists($pdo, 'fa_fans_account', 'rights_lock_day')) {
    $pdo->exec("ALTER TABLE fa_fans_account ADD COLUMN rights_lock_day date DEFAULT NULL COMMENT '锁定股份归属日' AFTER rights_locked");
    echo "added account.rights_lock_day\n";
} else {
    echo "account.rights_lock_day exists\n";
}

echo "ok\n";
