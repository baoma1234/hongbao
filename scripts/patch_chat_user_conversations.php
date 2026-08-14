<?php
/**
 * Create fa_chat_user_conversations + optional backfill from recent messages.
 *
 * Usage:
 *   php scripts/patch_chat_user_conversations.php
 *   php scripts/patch_chat_user_conversations.php --backfill
 *   php scripts/patch_chat_user_conversations.php --backfill --limit=50000
 */
$root = dirname(__DIR__);
require $root . '/im-server/vendor/autoload.php';
$cfg = require $root . '/im-server/config/app.php';
Im\Support\Db::init($cfg['db']);

$backfill = in_array('--backfill', $argv, true);
$limit = 30000;
foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = max(1000, min(200000, (int)substr($arg, 8)));
    }
}

$sqlFile = $root . '/sql/chat_user_conversations.sql';
$sql = file_get_contents($sqlFile);
Im\Support\Db::exec($sql);
echo "OK create/ensure fa_chat_user_conversations\n";

if (!$backfill) {
    echo "Skip backfill (pass --backfill to seed from recent messages)\n";
    exit(0);
}

$table = Im\Support\Db::table('chat_user_conversations');
$msg = Im\Support\Db::table('chat_messages');
$now = time();

// Private: walk recent messages, upsert both peers (latest wins via GREATEST)
echo "Backfill private from last {$limit} private messages...\n";
$rows = Im\Support\Db::fetchAll(
    "SELECT id, conversation_id, from_user_id, to_user_id, createtime
     FROM {$msg}
     WHERE conversation_type=1 AND status=1
     ORDER BY id DESC
     LIMIT " . (int)$limit
);
$n = 0;
foreach ($rows as $r) {
    $cid = (string)$r['conversation_id'];
    $mid = (int)$r['id'];
    $ts = (int)$r['createtime'];
    $from = (int)$r['from_user_id'];
    $to = (int)$r['to_user_id'];
    foreach ([[$from, $to], [$to, $from]] as $pair) {
        list($uid, $peer) = $pair;
        if ($uid <= 0) {
            continue;
        }
        Im\Support\Db::exec(
            "INSERT INTO {$table}
             (user_id, conversation_type, conversation_id, peer_user_id, group_id, last_msg_id, last_msg_time, updatetime)
             VALUES (?,?,?,?,0,?,?,?)
             ON DUPLICATE KEY UPDATE
               last_msg_time = IF(VALUES(last_msg_id) > last_msg_id, VALUES(last_msg_time), last_msg_time),
               last_msg_id = IF(VALUES(last_msg_id) > last_msg_id, VALUES(last_msg_id), last_msg_id),
               peer_user_id = VALUES(peer_user_id),
               updatetime = VALUES(updatetime)",
            [$uid, 1, $cid, $peer, $mid, $ts, $now]
        );
        $n++;
    }
}
echo "Private upserts≈{$n}\n";

// Groups: last message per group for members (active groups only)
echo "Backfill group lasts...\n";
$glast = Im\Support\Db::fetchAll(
    "SELECT conversation_id, MAX(id) AS max_id
     FROM {$msg}
     WHERE conversation_type=2 AND status=1
     GROUP BY conversation_id
     ORDER BY max_id DESC
     LIMIT 2000"
);
$gn = 0;
$memTable = Im\Support\Db::table('chat_group_members');
foreach ($glast as $g) {
    $cid = (string)$g['conversation_id'];
    $gid = (int)$cid;
    $mid = (int)$g['max_id'];
    if ($gid <= 0 || $mid <= 0) {
        continue;
    }
    $msgRow = Im\Support\Db::fetch("SELECT createtime FROM {$msg} WHERE id=? LIMIT 1", [$mid]);
    $ts = (int)($msgRow['createtime'] ?? $now);
    $members = Im\Support\Db::fetchAll(
        "SELECT user_id FROM {$memTable} WHERE group_id=? AND status=1 LIMIT 5000",
        [$gid]
    );
    foreach ($members as $m) {
        $uid = (int)$m['user_id'];
        if ($uid <= 0) {
            continue;
        }
        Im\Support\Db::exec(
            "INSERT INTO {$table}
             (user_id, conversation_type, conversation_id, peer_user_id, group_id, last_msg_id, last_msg_time, updatetime)
             VALUES (?,?,?,0,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               last_msg_time = IF(VALUES(last_msg_id) > last_msg_id, VALUES(last_msg_time), last_msg_time),
               last_msg_id = IF(VALUES(last_msg_id) > last_msg_id, VALUES(last_msg_id), last_msg_id),
               group_id = VALUES(group_id),
               updatetime = VALUES(updatetime)",
            [$uid, 2, $cid, $gid, $mid, $ts, $now]
        );
        $gn++;
    }
}
echo "Group upserts≈{$gn}\n";
echo "DONE\n";
