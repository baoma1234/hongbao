<?php
/**
 * 将已有会员 ID 覆盖为随机不重复八位数，并同步所有引用与私聊 conversation_id。
 * 邀请码规则：邀请码 = 八位 user_id（offset=0）。
 *
 * 用法: php scripts/migrate_user_ids_8digit.php
 * 可选: php scripts/migrate_user_ids_8digit.php --dry-run
 */
$root = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv ?? [], true);

$ini = @parse_ini_file($root . '/.env', true) ?: [];
$d = $ini['database'] ?? [];
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $d['hostname'] ?? '127.0.0.1',
        (int)($d['hostport'] ?? 3306),
        $d['database'] ?? ''
    ),
    $d['username'] ?? 'root',
    $d['password'] ?? '',
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

const ID_MIN = 10000000;
const ID_MAX = 99999999;

function tableExists(PDO $pdo, $table)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
    return (bool)$st->fetchColumn();
}

function columnExists(PDO $pdo, $table, $column)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    $st = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $pdo->quote($column));
    return (bool)$st->fetchColumn();
}

function allocEight(PDO $pdo, array &$used)
{
    for ($i = 0; $i < 200; $i++) {
        $id = random_int(ID_MIN, ID_MAX);
        if (isset($used[$id])) {
            continue;
        }
        $used[$id] = true;
        return $id;
    }
    throw new RuntimeException('failed to allocate unique 8-digit id');
}

echo $dryRun ? "[DRY-RUN]\n" : "[LIVE]\n";

// ---- collect old ids ----
$oldIds = [];
foreach ($pdo->query('SELECT id FROM fa_user')->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $oldIds[(int)$id] = true;
}
if (tableExists($pdo, 'fa_fans_account')) {
    foreach ($pdo->query('SELECT id, user_id FROM fa_fans_account')->fetchAll() as $row) {
        $oldIds[(int)$row['id']] = true;
        $oldIds[(int)$row['user_id']] = true;
    }
}
$oldIds = array_keys($oldIds);
sort($oldIds, SORT_NUMERIC);
$oldIds = array_values(array_filter($oldIds, function ($id) {
    return $id > 0;
}));

echo 'old_ids=' . count($oldIds) . ' [' . implode(',', $oldIds) . "]\n";

// ---- build map (already-8-digit keep if unique and in range) ----
$used = [];
$map = [];
foreach ($oldIds as $old) {
    if ($old >= ID_MIN && $old <= ID_MAX && !isset($used[$old])) {
        $map[$old] = $old;
        $used[$old] = true;
    }
}
foreach ($oldIds as $old) {
    if (isset($map[$old])) {
        continue;
    }
    $map[$old] = allocEight($pdo, $used);
}

echo "map:\n";
foreach ($map as $o => $n) {
    echo "  {$o} => {$n}\n";
}

// Save map for audit
$mapFile = $root . '/runtime/user_id_remap_' . date('Ymd_His') . '.json';
if (!$dryRun) {
    if (!is_dir(dirname($mapFile))) {
        @mkdir(dirname($mapFile), 0777, true);
    }
    file_put_contents($mapFile, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "map_file={$mapFile}\n";
}

/** @var array<int, array{0:string,1:string}> */
$refCols = [
    ['fa_attachment', 'user_id'],
    ['fa_cashback_apply', 'user_id'],
    ['fa_chat_agent_accounts', 'user_id'],
    ['fa_chat_contacts', 'user_id'],
    ['fa_chat_contacts', 'peer_user_id'],
    ['fa_chat_conversation_read', 'user_id'],
    ['fa_chat_groups', 'owner_user_id'],
    ['fa_chat_group_members', 'user_id'],
    ['fa_chat_messages', 'from_user_id'],
    ['fa_chat_messages', 'to_user_id'],
    ['fa_chat_red_packets', 'from_user_id'],
    ['fa_chat_red_packets', 'to_user_id'],
    ['fa_chat_red_packets', 'agent_user_id'],
    ['fa_chat_red_packets', 'compensate_user_id'],
    ['fa_chat_red_packet_records', 'user_id'],
    ['fa_chat_red_packet_settlements', 'from_user_id'],
    ['fa_chat_red_packet_settlements', 'to_user_id'],
    ['fa_chat_user_stickers', 'user_id'],
    ['fa_fans_checkin', 'user_id'],
    ['fa_fans_comment', 'user_id'],
    ['fa_fans_idempotent', 'user_id'],
    ['fa_fans_invite', 'inviter_user_id'],
    ['fa_fans_invite', 'invitee_user_id'],
    ['fa_fans_ledger', 'user_id'],
    ['fa_fans_login_log', 'user_id'],
    ['fa_fans_recharge_order', 'user_id'],
    ['fa_fans_secret', 'user_id'],
    ['fa_fans_task', 'user_id'],
    ['fa_fans_withdraw_order', 'user_id'],
    ['fa_invite_log', 'inviter_id'],
    ['fa_invite_log', 'invitee_id'],
    ['fa_test', 'user_id'],
    ['fa_user', 'parent_id'],
    ['fa_user_money_log', 'user_id'],
    ['fa_user_score_log', 'user_id'],
    ['fa_user_token', 'user_id'],
    // NOT fa_auth_group_access.uid (后台管理员)
];

function remapIntCol(PDO $pdo, $table, $col, array $map, $dryRun)
{
    if (!tableExists($pdo, $table) || !columnExists($pdo, $table, $col)) {
        return 0;
    }
    $n = 0;
    $st = $pdo->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE `{$col}` = ?");
    // Update larger old ids first? No collision risk: new are 8-digit, old are small.
    // But if some already 8-digit kept, updating A->B then C->A could matter.
    // Sort: change IDs that are keeping themselves last; others any order.
    foreach ($map as $old => $new) {
        if ($old === $new) {
            continue;
        }
        if ($dryRun) {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` = " . (int)$old)->fetchColumn();
            $n += $cnt;
            continue;
        }
        $st->execute([(int)$new, (int)$old]);
        $n += $st->rowCount();
    }
    return $n;
}

function remapConvIds(PDO $pdo, $table, $col, array $map, $dryRun)
{
    if (!tableExists($pdo, $table) || !columnExists($pdo, $table, $col)) {
        return 0;
    }
    $rows = $pdo->query("SELECT DISTINCT `{$col}` AS cid FROM `{$table}` WHERE `{$col}` REGEXP '^[0-9]+_[0-9]+$'")->fetchAll();
    $n = 0;
    $st = $dryRun ? null : $pdo->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE `{$col}` = ?");
    foreach ($rows as $row) {
        $cid = (string)$row['cid'];
        if (!preg_match('/^(\d+)_(\d+)$/', $cid, $m)) {
            continue;
        }
        $a = (int)$m[1];
        $b = (int)$m[2];
        if (!isset($map[$a]) && !isset($map[$b])) {
            continue;
        }
        $a2 = $map[$a] ?? $a;
        $b2 = $map[$b] ?? $b;
        $lo = min($a2, $b2);
        $hi = max($a2, $b2);
        $newCid = $lo . '_' . $hi;
        if ($newCid === $cid) {
            continue;
        }
        if ($dryRun) {
            echo "  conv {$cid} => {$newCid}\n";
            $n++;
            continue;
        }
        $st->execute([$newCid, $cid]);
        $n += $st->rowCount();
    }
    return $n;
}

if (!$dryRun) {
    $pdo->beginTransaction();
}

try {
    // 1) reference columns
    foreach ($refCols as $pair) {
        list($table, $col) = $pair;
        $n = remapIntCol($pdo, $table, $col, $map, $dryRun);
        if ($n > 0) {
            echo "ref {$table}.{$col} rows~={$n}\n";
        }
    }

    // 2) PK: fa_user.id
    if (!$dryRun) {
        $st = $pdo->prepare('UPDATE fa_user SET id = ? WHERE id = ?');
        foreach ($map as $old => $new) {
            if ($old === $new) {
                continue;
            }
            // only if this old id is a real user row
            $exists = (int)$pdo->query('SELECT COUNT(*) FROM fa_user WHERE id=' . (int)$old)->fetchColumn();
            if (!$exists) {
                continue;
            }
            $st->execute([(int)$new, (int)$old]);
            echo "user {$old} => {$new} affected={$st->rowCount()}\n";
        }
    } else {
        foreach ($map as $old => $new) {
            if ($old === $new) {
                continue;
            }
            $exists = (int)$pdo->query('SELECT COUNT(*) FROM fa_user WHERE id=' . (int)$old)->fetchColumn();
            if ($exists) {
                echo "user {$old} => {$new}\n";
            }
        }
    }

    // 3) fa_fans_account id + user_id
    if (tableExists($pdo, 'fa_fans_account')) {
        if (!$dryRun) {
            // Shift to temp first to avoid rare PK clash among concurrent new ids
            $tempBase = 1900000000;
            $pdo->exec("UPDATE fa_fans_account SET id = id + {$tempBase}, user_id = user_id + {$tempBase}");
            $st = $pdo->prepare('UPDATE fa_fans_account SET id = ?, user_id = ? WHERE id = ?');
            foreach ($map as $old => $new) {
                $st->execute([(int)$new, (int)$new, (int)($old + $tempBase)]);
            }
            // Any leftover (shouldn't) — leave as-is
            $left = $pdo->query("SELECT id,user_id FROM fa_fans_account WHERE id >= {$tempBase} LIMIT 5")->fetchAll();
            if ($left) {
                echo "WARN leftover accounts after remap:\n";
                print_r($left);
            }
        } else {
            echo "fans_account: would remap " . count($map) . " rows via temp shift\n";
        }
    }

    // 4) conversation_id strings
    echo "conversation remap:\n";
    $c1 = remapConvIds($pdo, 'fa_chat_messages', 'conversation_id', $map, $dryRun);
    $c2 = remapConvIds($pdo, 'fa_chat_conversation_read', 'conversation_id', $map, $dryRun);
    $c3 = remapConvIds($pdo, 'fa_chat_red_packets', 'conversation_id', $map, $dryRun);
    echo "  messages={$c1} read={$c2} packets={$c3}\n";

    // 5) platform_user_id in red packet config
    if (tableExists($pdo, 'fa_chat_red_packet_config')) {
        $row = $pdo->query("SELECT cfg_value FROM fa_chat_red_packet_config WHERE cfg_key='platform_user_id' LIMIT 1")->fetch();
        if ($row) {
            $oldP = (int)$row['cfg_value'];
            $newP = $map[$oldP] ?? $oldP;
            echo "platform_user_id {$oldP} => {$newP}\n";
            if (!$dryRun && $newP !== $oldP) {
                $st = $pdo->prepare("UPDATE fa_chat_red_packet_config SET cfg_value=?, updatetime=? WHERE cfg_key='platform_user_id'");
                $st->execute([(string)$newP, time()]);
            }
        }
    }

    // 6) wipe tokens (force re-login)
    if (!$dryRun && tableExists($pdo, 'fa_user_token')) {
        $n = $pdo->exec('DELETE FROM fa_user_token');
        echo "cleared_tokens={$n}\n";
    } else {
        echo "cleared_tokens=dry-run-skip\n";
    }

    // 7) AUTO_INCREMENT (DDL 会隐式提交，须放在事务外)
    if (!$dryRun) {
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        $pdo->exec('ALTER TABLE fa_user AUTO_INCREMENT=' . (ID_MAX + 1));
        if (tableExists($pdo, 'fa_fans_account')) {
            $pdo->exec('ALTER TABLE fa_fans_account AUTO_INCREMENT=' . (ID_MAX + 1));
        }
        echo 'auto_increment=' . (ID_MAX + 1) . "\n";
    } elseif ($pdo->inTransaction()) {
        // dry-run should not be in transaction
    }

    echo "DB_OK\n";
} catch (Throwable $e) {
    if (!$dryRun && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

// Sync IM red_packet runtime file
if (!$dryRun) {
    $rpFile = $root . '/im-server/config/red_packet_runtime.php';
    if (is_file($rpFile)) {
        $cfg = include $rpFile;
        if (is_array($cfg) && isset($cfg['red_packet']['platform_user_id'])) {
            $oldP = (int)$cfg['red_packet']['platform_user_id'];
            $newP = $map[$oldP] ?? $oldP;
            $cfg['red_packet']['platform_user_id'] = $newP;
            $export = var_export($cfg, true);
            file_put_contents($rpFile, "<?php\n// auto-updated by migrate_user_ids_8digit.php\nreturn {$export};\n");
            echo "runtime platform_user_id={$newP}\n";
        }
    }
    // also patch app.php default comment value — leave default; runtime overrides
}

// Flush IM redis (presence / recent / tokens cache)
if (!$dryRun) {
    $local = is_file($root . '/im-server/config/local.php') ? include $root . '/im-server/config/local.php' : [];
    $r = $local['redis'] ?? [];
    if (!empty($r['host']) && class_exists('Redis')) {
        try {
            $redis = new Redis();
            $redis->connect($r['host'], (int)($r['port'] ?? 6379), 2.0);
            if (!empty($r['password'])) {
                $redis->auth($r['password']);
            }
            $redis->select((int)($r['db'] ?? 2));
            $redis->flushDB();
            echo "redis_flushed db=" . (int)($r['db'] ?? 2) . "\n";
        } catch (Throwable $e) {
            echo 'redis_flush_skip: ' . $e->getMessage() . "\n";
        }
    }
}

// Verify
echo "verify users:\n";
foreach ($pdo->query('SELECT id,username,mobile FROM fa_user ORDER BY id')->fetchAll() as $u) {
    $ok = ((int)$u['id'] >= ID_MIN && (int)$u['id'] <= ID_MAX) ? 'OK' : 'BAD';
    echo "  [{$ok}] id={$u['id']} {$u['username']}\n";
}
$mismatch = $pdo->query('SELECT id,user_id FROM fa_fans_account WHERE id<>user_id LIMIT 5')->fetchAll();
echo 'account_mismatch=' . count($mismatch) . "\n";

echo "done\n";
