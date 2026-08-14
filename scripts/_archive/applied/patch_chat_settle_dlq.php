<?php
/**
 * 红包结算 DLQ 表（幂等）
 * Usage: php scripts/patch_chat_settle_dlq.php
 */
$imRoot = dirname(__DIR__) . '/im-server';
require $imRoot . '/vendor/autoload.php';
$cfg = require $imRoot . '/config/app.php';
Im\Support\Db::init($cfg['db']);
if (!Im\Support\SettleQueue::ensureTable()) {
    fwrite(STDERR, "FAIL ensureTable\n");
    exit(1);
}
$table = Im\Support\Db::table(Im\Support\SettleQueue::DLQ_TABLE);
Im\Support\Db::fetch('SELECT 1 AS ok FROM ' . $table . ' LIMIT 1');
echo "OK {$table} ready\n";
