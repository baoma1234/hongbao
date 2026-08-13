<?php
/**
 * 清理「对方用户已删除」后的无效会话列表残留
 *
 * 清理内容：
 * - Redis inbox / pins：私聊对方不在 fa_user
 * - Redis：已删用户本人的 inbox/pins/hidden/convlist/unread
 * - DB：chat_contacts / chat_user_remarks / chat_conversation_deleted 指向已删用户
 * - Redis ub:{id} 用户摘要缓存
 *
 * 用法：
 *   php scripts/cleanup_orphan_conversations.php
 *   php scripts/cleanup_orphan_conversations.php --execute
 *   php scripts/cleanup_orphan_conversations.php --execute --batch=500
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$cfg = require $root . '/config/app.php';
Im\Support\Db::init($cfg['db']);
Im\Support\RedisClient::init($cfg['redis']);

$execute = false;
$batch = 500;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if ($arg === '--execute') {
        $execute = true;
        continue;
    }
    if (preg_match('/^--batch=(\d+)$/', $arg, $m)) {
        $batch = max(50, min(2000, (int)$m[1]));
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php cleanup_orphan_conversations.php [--execute] [--batch=500]\n";
        exit(0);
    }
}

$prefix = (string)($cfg['redis']['prefix'] ?? 'im:');
$userTable = Im\Support\Db::table('user');
$contactTable = Im\Support\Db::table('chat_contacts');
$remarkTable = Im\Support\Db::table('chat_user_remarks');
$deletedTable = Im\Support\Db::table('chat_conversation_deleted');

echo '[' . date('Y-m-d H:i:s') . '] orphan conversations cleanup mode='
    . ($execute ? 'EXECUTE' : 'DRY-RUN') . " batch={$batch}\n";

$r = Im\Support\RedisClient::conn();

$stats = [
    'inbox_keys'         => 0,
    'inbox_owner_gone'   => 0,
    'inbox_peer_removed' => 0,
    'pins_peer_removed'  => 0,
    'ub_deleted'         => 0,
    'contacts_deleted'   => 0,
    'remarks_deleted'    => 0,
    'conv_del_deleted'   => 0,
];

/** @var array<int,bool>|null */
$userExistsCache = null;

function userExists(int $uid, string $userTable, ?array &$cache): bool
{
    if ($uid <= 0) {
        return false;
    }
    if ($cache === null) {
        $cache = [];
        $rows = Im\Support\Db::fetchAll("SELECT id FROM {$userTable}");
        foreach ($rows as $row) {
            $cache[(int)$row['id']] = true;
        }
        echo '  loaded users=' . count($cache) . "\n";
    }
    return isset($cache[$uid]);
}

function scanKeys(\Redis $r, string $match, int $count = 200): Generator
{
    $cursor = null;
    do {
        $keys = $r->scan($cursor, $match, $count);
        if ($keys === false) {
            break;
        }
        foreach ($keys as $key) {
            yield (string)$key;
        }
    } while ($cursor > 0);
}

// 1) Redis inbox
foreach (scanKeys($r, $prefix . 'inbox:*', $batch) as $key) {
    $stats['inbox_keys']++;
    $uid = (int)substr($key, strlen($prefix . 'inbox:'));
    if ($uid <= 0) {
        continue;
    }
    if (!userExists($uid, $userTable, $userExistsCache)) {
        $stats['inbox_owner_gone']++;
        echo "  drop inbox owner_gone uid={$uid}\n";
        if ($execute) {
            $r->del($key);
            $r->del($prefix . 'pins:' . $uid);
            $r->del($prefix . 'hidden:' . $uid);
            $r->del($prefix . 'convlist:' . $uid);
            $r->del($prefix . 'convlist:' . $uid . ':50');
            $r->del($prefix . 'convlist:' . $uid . ':100');
            $r->del($prefix . 'ub:' . $uid);
            $cursor = null;
            do {
                $uks = $r->scan($cursor, $prefix . 'unread:' . $uid . ':*', 200);
                if ($uks === false) {
                    break;
                }
                if ($uks) {
                    $r->del(...$uks);
                }
            } while ($cursor > 0);
        }
        continue;
    }

    $members = $r->zRange($key, 0, -1);
    if (!is_array($members) || !$members) {
        continue;
    }
    $drop = [];
    foreach ($members as $member) {
        $member = (string)$member;
        $parts = explode(':', $member, 2);
        if (count($parts) !== 2 || (int)$parts[0] !== 1) {
            continue;
        }
        $cid = (string)$parts[1];
        $bits = explode('_', $cid);
        if (count($bits) !== 2) {
            continue;
        }
        $a = (int)$bits[0];
        $b = (int)$bits[1];
        $peer = ($a === $uid) ? $b : (($b === $uid) ? $a : 0);
        if ($peer <= 0) {
            continue;
        }
        if (!userExists($peer, $userTable, $userExistsCache)) {
            $drop[] = $member;
        }
    }
    if (!$drop) {
        continue;
    }
    $stats['inbox_peer_removed'] += count($drop);
    echo '  inbox uid=' . $uid . ' remove_private=' . count($drop) . ' e.g. ' . $drop[0] . "\n";
    if ($execute) {
        foreach ($drop as $member) {
            $r->zRem($key, $member);
            $r->zRem($prefix . 'pins:' . $uid, $member);
            $parts = explode(':', $member, 2);
            if (count($parts) === 2) {
                $r->del($prefix . 'unread:' . $uid . ':' . $parts[0] . ':' . $parts[1]);
            }
        }
        $r->del($prefix . 'convlist:' . $uid);
        $r->del($prefix . 'convlist:' . $uid . ':50');
        $r->del($prefix . 'convlist:' . $uid . ':100');
    }
}

// 2) pins 里残留（inbox 已空但仍有 pin）
foreach (scanKeys($r, $prefix . 'pins:*', $batch) as $key) {
    $uid = (int)substr($key, strlen($prefix . 'pins:'));
    if ($uid <= 0) {
        continue;
    }
    if (!userExists($uid, $userTable, $userExistsCache)) {
        if ($execute) {
            $r->del($key);
        }
        continue;
    }
    $members = $r->zRange($key, 0, -1);
    if (!is_array($members)) {
        continue;
    }
    foreach ($members as $member) {
        $member = (string)$member;
        $parts = explode(':', $member, 2);
        if (count($parts) !== 2 || (int)$parts[0] !== 1) {
            continue;
        }
        $bits = explode('_', (string)$parts[1]);
        if (count($bits) !== 2) {
            continue;
        }
        $a = (int)$bits[0];
        $b = (int)$bits[1];
        $peer = ($a === $uid) ? $b : (($b === $uid) ? $a : 0);
        if ($peer > 0 && !userExists($peer, $userTable, $userExistsCache)) {
            $stats['pins_peer_removed']++;
            if ($execute) {
                $r->zRem($key, $member);
            }
        }
    }
}

// 3) 过期 ub 缓存（已删用户）
foreach (scanKeys($r, $prefix . 'ub:*', $batch) as $key) {
    $uid = (int)substr($key, strlen($prefix . 'ub:'));
    if ($uid > 0 && !userExists($uid, $userTable, $userExistsCache)) {
        $stats['ub_deleted']++;
        if ($execute) {
            $r->del($key);
        }
    }
}

// 4) DB 孤儿联系人 / 备注 / 删除记录
try {
    $n = (int)(Im\Support\Db::fetch(
        "SELECT COUNT(*) AS c FROM {$contactTable} c"
        . " LEFT JOIN {$userTable} u1 ON u1.id=c.user_id"
        . " LEFT JOIN {$userTable} u2 ON u2.id=c.peer_user_id"
        . ' WHERE u1.id IS NULL OR u2.id IS NULL'
    )['c'] ?? 0);
    $stats['contacts_deleted'] = $n;
    echo "  orphan contacts={$n}\n";
    if ($execute && $n > 0) {
        Im\Support\Db::exec(
            "DELETE c FROM {$contactTable} c"
            . " LEFT JOIN {$userTable} u1 ON u1.id=c.user_id"
            . " LEFT JOIN {$userTable} u2 ON u2.id=c.peer_user_id"
            . ' WHERE u1.id IS NULL OR u2.id IS NULL'
        );
    }
} catch (Throwable $e) {
    echo '  contacts skip: ' . $e->getMessage() . "\n";
}

try {
    $n = (int)(Im\Support\Db::fetch(
        "SELECT COUNT(*) AS c FROM {$remarkTable} c"
        . " LEFT JOIN {$userTable} u1 ON u1.id=c.user_id"
        . " LEFT JOIN {$userTable} u2 ON u2.id=c.peer_user_id"
        . ' WHERE u1.id IS NULL OR u2.id IS NULL'
    )['c'] ?? 0);
    $stats['remarks_deleted'] = $n;
    echo "  orphan remarks={$n}\n";
    if ($execute && $n > 0) {
        Im\Support\Db::exec(
            "DELETE c FROM {$remarkTable} c"
            . " LEFT JOIN {$userTable} u1 ON u1.id=c.user_id"
            . " LEFT JOIN {$userTable} u2 ON u2.id=c.peer_user_id"
            . ' WHERE u1.id IS NULL OR u2.id IS NULL'
        );
    }
} catch (Throwable $e) {
    echo '  remarks skip: ' . $e->getMessage() . "\n";
}

try {
    $n = (int)(Im\Support\Db::fetch(
        "SELECT COUNT(*) AS c FROM {$deletedTable} d"
        . " LEFT JOIN {$userTable} u1 ON u1.id=d.user_id"
        . " LEFT JOIN {$userTable} u2 ON u2.id=d.peer_user_id"
        . ' WHERE u1.id IS NULL OR (d.peer_user_id>0 AND u2.id IS NULL)'
    )['c'] ?? 0);
    $stats['conv_del_deleted'] = $n;
    echo "  orphan conversation_deleted={$n}\n";
    if ($execute && $n > 0) {
        Im\Support\Db::exec(
            "DELETE d FROM {$deletedTable} d"
            . " LEFT JOIN {$userTable} u1 ON u1.id=d.user_id"
            . " LEFT JOIN {$userTable} u2 ON u2.id=d.peer_user_id"
            . ' WHERE u1.id IS NULL OR (d.peer_user_id>0 AND u2.id IS NULL)'
        );
    }
} catch (Throwable $e) {
    echo '  conversation_deleted skip: ' . $e->getMessage() . "\n";
}

// 5) 私聊消息任一侧用户已不存在（避免后台会话列表残留）
$msgTable = Im\Support\Db::table('chat_messages');
$stats['priv_msg_orphan'] = 0;
try {
    $n = (int)(Im\Support\Db::fetch(
        "SELECT COUNT(*) AS c FROM {$msgTable} m"
        . " LEFT JOIN {$userTable} uf ON uf.id=m.from_user_id"
        . " LEFT JOIN {$userTable} ut ON ut.id=m.to_user_id"
        . ' WHERE m.conversation_type=1 AND (uf.id IS NULL OR ut.id IS NULL)'
    )['c'] ?? 0);
    $stats['priv_msg_orphan'] = $n;
    echo "  orphan private messages={$n}\n";
    if ($execute && $n > 0) {
        Im\Support\Db::exec(
            "DELETE m FROM {$msgTable} m"
            . " LEFT JOIN {$userTable} uf ON uf.id=m.from_user_id"
            . " LEFT JOIN {$userTable} ut ON ut.id=m.to_user_id"
            . ' WHERE m.conversation_type=1 AND (uf.id IS NULL OR ut.id IS NULL)'
        );
    }
} catch (Throwable $e) {
    echo '  private messages skip: ' . $e->getMessage() . "\n";
}

// 6) fans_account 无对应用户
$accTable = Im\Support\Db::table('fans_account');
$stats['fans_account_orphan'] = 0;
try {
    $n = (int)(Im\Support\Db::fetch(
        "SELECT COUNT(*) AS c FROM {$accTable} a"
        . " LEFT JOIN {$userTable} u ON u.id=a.user_id WHERE u.id IS NULL"
    )['c'] ?? 0);
    $stats['fans_account_orphan'] = $n;
    echo "  orphan fans_account={$n}\n";
    if ($execute && $n > 0) {
        Im\Support\Db::exec(
            "DELETE a FROM {$accTable} a"
            . " LEFT JOIN {$userTable} u ON u.id=a.user_id WHERE u.id IS NULL"
        );
    }
} catch (Throwable $e) {
    echo '  fans_account skip: ' . $e->getMessage() . "\n";
}

echo "\nSummary:\n";
foreach ($stats as $k => $v) {
    echo "  {$k}={$v}\n";
}
if (!$execute) {
    echo "\nDry-run only. Re-run with --execute to apply.\n";
} else {
    echo "\nDone.\n";
}
