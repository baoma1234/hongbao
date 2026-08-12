<?php
/**
 * 删除 seed_bot_users 批量注册的机器人（fa_fans_account.is_bot=1）
 *
 * 默认只预览，不删数据。真正删除必须加 --confirm
 *
 * 用法：
 *   php scripts/purge_bot_users.php
 *   php scripts/purge_bot_users.php --mobile-prefix=10000000001 --confirm
 *   php scripts/purge_bot_users.php --ids=90000001,90000002 --confirm
 *   php scripts/purge_bot_users.php --all-bots --confirm   # 危险：所有 is_bot=1
 */
$root = dirname(__DIR__);
$confirm = false;
$allBots = false;
$mobilePrefix = '10000000001';
$userIds = [];
$batch = 500;

foreach ($argv ?? [] as $arg) {
    if ($arg === '--confirm') {
        $confirm = true;
    } elseif ($arg === '--all-bots') {
        $allBots = true;
        $mobilePrefix = '';
    } elseif ($arg === '--dry-run') {
        $confirm = false;
    } elseif (preg_match('/^--mobile-prefix=(.+)$/', $arg, $m)) {
        $mobilePrefix = preg_replace('/\D+/', '', $m[1]);
    } elseif (preg_match('/^--ids=(.+)$/', $arg, $m)) {
        $userIds = array_values(array_filter(array_map('intval', explode(',', $m[1]))));
        $mobilePrefix = '';
    } elseif (preg_match('/^--batch=(\d+)$/', $arg, $m)) {
        $batch = max(50, min(5000, (int)$m[1]));
    }
}

$envFile = $root . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, "missing .env\n");
    exit(1);
}
$env = parse_ini_file($envFile, true);
$d = $env['database'] ?? [];
$prefix = $d['prefix'] ?? 'fa_';
$userT = $prefix . 'user';
$accT = $prefix . 'fans_account';

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
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

function tableExists(PDO $pdo, $table)
{
    $st = $pdo->prepare('SHOW TABLES LIKE ?');
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function countByUserIds(PDO $pdo, $table, $col, array $ids)
{
    if (!$ids || !tableExists($pdo, $table)) {
        return 0;
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IN ({$ph})");
    $st->execute($ids);
    return (int)$st->fetchColumn();
}

function deleteByUserIds(PDO $pdo, $table, $col, array $ids, $batch)
{
    if (!$ids || !tableExists($pdo, $table)) {
        return 0;
    }
    $total = 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    while (true) {
        $n = $pdo->exec(
            "DELETE FROM `{$table}` WHERE `{$col}` IN ({$ph}) LIMIT " . (int)$batch
        );
        if ($n === false) {
            break;
        }
        $total += (int)$n;
        if ($n < $batch) {
            break;
        }
    }
    return $total;
}

function deleteMessagesForUsers(PDO $pdo, $table, array $ids, $batch)
{
    if (!$ids || !tableExists($pdo, $table)) {
        return 0;
    }
    $total = 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (['from_user_id', 'to_user_id'] as $col) {
        while (true) {
            $sql = "DELETE FROM `{$table}` WHERE `{$col}` IN ({$ph}) LIMIT " . (int)$batch;
            $st = $pdo->prepare($sql);
            $st->execute($ids);
            $n = $st->rowCount();
            $total += $n;
            if ($n < $batch) {
                break;
            }
        }
    }
    return $total;
}

function deleteFriendRequests(PDO $pdo, $table, array $ids, $batch)
{
    if (!$ids || !tableExists($pdo, $table)) {
        return 0;
    }
    $total = 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (['from_user_id', 'to_user_id'] as $col) {
        while (true) {
            $st = $pdo->prepare(
                "DELETE FROM `{$table}` WHERE `{$col}` IN ({$ph}) LIMIT " . (int)$batch
            );
            $st->execute($ids);
            $n = $st->rowCount();
            $total += $n;
            if ($n < $batch) {
                break;
            }
        }
    }
    return $total;
}

// 选取待删用户
if ($userIds) {
    $sql = "SELECT u.id, u.mobile, u.nickname
            FROM `{$userT}` u
            INNER JOIN `{$accT}` a ON a.user_id = u.id
            WHERE u.id IN (" . implode(',', array_map('intval', $userIds)) . ")
              AND IFNULL(a.is_bot,0) = 1
            ORDER BY u.id ASC";
    $rows = $pdo->query($sql)->fetchAll();
} else {
    $where = 'IFNULL(a.is_bot,0) = 1';
    $params = [];
    if (!$allBots && $mobilePrefix !== '') {
        $where .= ' AND (u.mobile LIKE ? OR u.username LIKE ?)';
        $params[] = $mobilePrefix . '%';
        $params[] = $mobilePrefix . '%';
    }
    $sql = "SELECT u.id, u.mobile, u.nickname
            FROM `{$userT}` u
            INNER JOIN `{$accT}` a ON a.user_id = u.id
            WHERE {$where}
            ORDER BY u.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
}

if (!$rows) {
    echo "no bot users matched\n";
    exit(0);
}

$ids = array_map(static function ($r) {
    return (int)$r['id'];
}, $rows);

echo 'matched bots: ' . count($ids) . "\n";
if (count($rows) <= 20) {
    foreach ($rows as $r) {
        echo "  id={$r['id']} mobile={$r['mobile']} nick={$r['nickname']}\n";
    }
} else {
    echo '  first: id=' . $rows[0]['id'] . ' mobile=' . $rows[0]['mobile'] . "\n";
    echo '  last:  id=' . $rows[count($rows) - 1]['id'] . ' mobile=' . $rows[count($rows) - 1]['mobile'] . "\n";
}

$related = [
    [$prefix . 'chat_red_packet_records', 'user_id'],
    [$prefix . 'chat_group_members', 'user_id'],
    [$prefix . 'chat_conversation_read', 'user_id'],
    [$prefix . 'chat_user_stickers', 'user_id'],
    [$prefix . 'chat_group_msg_cleared', 'user_id'],
    [$prefix . 'chat_conversation_deleted', 'user_id'],
    [$prefix . 'chat_agent_accounts', 'user_id'],
    [$prefix . 'fans_ledger', 'user_id'],
    [$prefix . 'fans_wallet_bind', 'user_id'],
    [$prefix . 'fans_pay_wallet', 'user_id'],
    [$prefix . 'fans_invite', 'inviter_user_id'],
    [$prefix . 'fans_invite', 'invitee_user_id'],
    [$prefix . 'user_token', 'user_id'],
];

echo "related rows (estimate):\n";
foreach ($related as [$tbl, $col]) {
    $n = countByUserIds($pdo, $tbl, $col, $ids);
    if ($n > 0) {
        echo "  {$tbl}.{$col}: {$n}\n";
    }
}
$msgTbl = $prefix . 'chat_messages';
if (tableExists($pdo, $msgTbl)) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM `{$msgTbl}` WHERE from_user_id IN ({$ph}) OR to_user_id IN ({$ph})"
    );
    $st->execute(array_merge($ids, $ids));
    $n = (int)$st->fetchColumn();
    if ($n > 0) {
        echo "  {$msgTbl}: {$n}\n";
    }
}
$frTbl = $prefix . 'chat_friend_requests';
if (tableExists($pdo, $frTbl)) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM `{$frTbl}` WHERE from_user_id IN ({$ph}) OR to_user_id IN ({$ph})"
    );
    $st->execute(array_merge($ids, $ids));
    $n = (int)$st->fetchColumn();
    if ($n > 0) {
        echo "  {$frTbl}: {$n}\n";
    }
}

if (!$confirm) {
    echo "\nDRY-RUN only. To delete, rerun with --confirm\n";
    echo "Example: php scripts/purge_bot_users.php --mobile-prefix=10000000001 --confirm\n";
    exit(0);
}

echo "\nDELETING...\n";
$pdo->beginTransaction();
try {
    $stats = [];
    $stats['chat_red_packet_records'] = deleteByUserIds($pdo, $prefix . 'chat_red_packet_records', 'user_id', $ids, $batch);
    $stats['chat_messages'] = deleteMessagesForUsers($pdo, $prefix . 'chat_messages', $ids, $batch);
    $stats['chat_friend_requests'] = deleteFriendRequests($pdo, $prefix . 'chat_friend_requests', $ids, $batch);
    foreach ($related as [$tbl, $col]) {
        $key = $tbl . '.' . $col;
        $stats[$key] = deleteByUserIds($pdo, $tbl, $col, $ids, $batch);
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stAcc = $pdo->prepare("DELETE FROM `{$accT}` WHERE user_id IN ({$ph})");
    $stAcc->execute($ids);
    $stats['fans_account'] = $stAcc->rowCount();
    $stUser = $pdo->prepare("DELETE FROM `{$userT}` WHERE id IN ({$ph})");
    $stUser->execute($ids);
    $stats['user'] = $stUser->rowCount();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

echo "done deleted users=" . count($ids) . "\n";
foreach ($stats as $k => $n) {
    if ($n > 0) {
        echo "  {$k}: {$n}\n";
    }
}

// 顺带删本地压测 token 文件（若存在）
$tokFile = $root . '/scripts/loadtest/tokens.json';
if (is_file($tokFile)) {
    @unlink($tokFile);
    echo "removed scripts/loadtest/tokens.json\n";
}
