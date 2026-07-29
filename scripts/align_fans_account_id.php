<?php
/**
 * 将 fa_fans_account.id 对齐为会员 user_id（id 即会员ID）
 * 用法: php scripts/align_fans_account_id.php
 */
require dirname(__DIR__) . '/im-server/vendor/autoload.php';

$cfg = require dirname(__DIR__) . '/im-server/config/app.php';
\Im\Support\Db::init($cfg['db']);

$t = \Im\Support\Db::table('fans_account');
$rows = \Im\Support\Db::fetchAll("SELECT id, user_id FROM {$t} ORDER BY id ASC");
echo "rows=" . count($rows) . "\n";

$need = [];
foreach ($rows as $r) {
    if ((int)$r['id'] !== (int)$r['user_id']) {
        $need[] = $r;
    }
}
echo "need_realign=" . count($need) . "\n";
if (!$need) {
    echo "already aligned\n";
    exit(0);
}

// 两步避免主键冲突：先挪到高位，再写回 user_id
$offset = 100000000;
\Im\Support\Db::exec("UPDATE {$t} SET id = id + ?", [$offset]);
echo "step1: shifted by {$offset}\n";

$updated = 0;
foreach ($rows as $r) {
    $uid = (int)$r['user_id'];
    $oldShifted = (int)$r['id'] + $offset;
    \Im\Support\Db::exec("UPDATE {$t} SET id = ? WHERE id = ?", [$uid, $oldShifted]);
    $updated++;
}
echo "step2: realigned={$updated}\n";

$max = (int)\Im\Support\Db::fetch("SELECT MAX(id) AS m FROM {$t}")['m'];
$next = max($max + 1, 1);
\Im\Support\Db::exec("ALTER TABLE {$t} AUTO_INCREMENT = " . $next);
echo "auto_increment={$next}\n";

$check = \Im\Support\Db::fetchAll("SELECT id,user_id FROM {$t} WHERE id <> user_id LIMIT 5");
echo "mismatch_left=" . count($check) . "\n";
foreach ($check as $c) {
    echo "  {$c['id']} vs {$c['user_id']}\n";
}
echo "done\n";
