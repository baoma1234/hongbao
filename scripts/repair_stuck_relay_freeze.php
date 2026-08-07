<?php
/**
 * 修复：接龙放弃续发后未解冻的最差者冻结
 * 特征：packet 已结算(status=5) + type=5 + freeze_status=1 + compensate_status=2
 *
 * 注意：强制读项目根 .env 的 database（忽略 im-server/config/local.php），
 * 避免本机 local 指到 127.0.0.1 时修错库。
 *
 * 用法:
 *   php scripts/repair_stuck_relay_freeze.php --dry-run
 *   php scripts/repair_stuck_relay_freeze.php
 *   php scripts/repair_stuck_relay_freeze.php 21162495
 */
$dry = in_array('--dry-run', $argv, true);
$onlyUid = 0;
foreach ($argv as $i => $a) {
    if ($i === 0 || $a === '--dry-run') {
        continue;
    }
    if (ctype_digit((string)$a)) {
        $onlyUid = (int)$a;
    }
}

$root = dirname(__DIR__);
require $root . '/im-server/vendor/autoload.php';

use Im\Support\Db;
use Im\Support\RedisClient;
use Im\Service\WalletService;

$ini = parse_ini_file($root . '/.env', true);
if (empty($ini['database'])) {
    fwrite(STDERR, "missing .env [database]\n");
    exit(1);
}
$d = $ini['database'];
$db = [
    'host'     => (string)($d['hostname'] ?? '127.0.0.1'),
    'port'     => (int)($d['hostport'] ?? 3306),
    'database' => (string)($d['database'] ?? ''),
    'username' => (string)($d['username'] ?? ''),
    'password' => (string)($d['password'] ?? ''),
    'charset'  => 'utf8mb4',
    'prefix'   => (string)($d['prefix'] ?? 'fa_'),
];
echo "DB host={$db['host']} db={$db['database']}\n";
Db::init($db);
if (!empty($ini['redis'])) {
    $r = $ini['redis'];
    RedisClient::init([
        'host'     => (string)($r['host'] ?? '127.0.0.1'),
        'port'     => (int)($r['port'] ?? 6379),
        'password' => (string)($r['password'] ?? ''),
        'db'       => (int)($r['select'] ?? $r['db'] ?? 2),
        'prefix'   => (string)($r['prefix'] ?? 'im:'),
    ]);
}

$cfg = ['red_packet' => []];
$sql = 'SELECT r.id AS record_id, r.user_id, r.packet_id, r.packet_no, r.frozen_amount,'
    . ' r.freeze_status, r.compensate_status, r.is_worst, p.group_id, p.settled_time'
    . ' FROM ' . Db::table('chat_red_packet_records') . ' r'
    . ' INNER JOIN ' . Db::table('chat_red_packets') . ' p ON p.id=r.packet_id'
    . ' WHERE r.freeze_status=1 AND r.frozen_amount>0'
    . ' AND p.packet_type=5 AND p.scope_type=2 AND p.status=5'
    . ' AND r.compensate_status=2';
$bind = [];
if ($onlyUid > 0) {
    $sql .= ' AND r.user_id=?';
    $bind[] = $onlyUid;
}
$sql .= ' ORDER BY r.id ASC';

$rows = Db::fetchAll($sql, $bind) ?: [];
echo 'found ' . count($rows) . " stuck freeze record(s)" . ($dry ? ' [DRY-RUN]' : '') . "\n";
if (!$rows) {
    exit(0);
}

$wallet = new WalletService($cfg);
$ok = 0;
$fail = 0;
$sum = 0.0;

foreach ($rows as $row) {
    $rid = (int)$row['record_id'];
    $uid = (int)$row['user_id'];
    $pid = (int)$row['packet_id'];
    $amt = round((float)$row['frozen_amount'], 2);
    $pno = (string)$row['packet_no'];
    echo sprintf("#%d uid=%d packet=%d %s amount=%.2f ... ", $rid, $uid, $pid, $pno, $amt);
    if ($amt <= 0 || $uid <= 0) {
        echo "SKIP\n";
        $fail++;
        continue;
    }
    if ($dry) {
        echo "DRY\n";
        $sum += $amt;
        $ok++;
        continue;
    }
    try {
        Db::begin();
        $fresh = Db::fetch(
            'SELECT id, freeze_status, frozen_amount FROM ' . Db::table('chat_red_packet_records')
            . ' WHERE id=? FOR UPDATE',
            [$rid]
        );
        if (!$fresh || (int)$fresh['freeze_status'] !== 1) {
            Db::rollBack();
            echo "SKIP already\n";
            continue;
        }
        $amt = round((float)$fresh['frozen_amount'], 2);
        $wallet->unfreeze(
            $uid,
            $amt,
            'red_packet_unfreeze',
            '红宝接龙放弃续发解冻(补修)',
            ['biz_no' => $pno, 'ref_type' => 'red_packet', 'ref_id' => $pid]
        );
        Db::exec(
            'UPDATE ' . Db::table('chat_red_packet_records')
            . ' SET freeze_status=2 WHERE id=? AND freeze_status=1',
            [$rid]
        );
        Db::commit();
        echo "OK\n";
        $sum += $amt;
        $ok++;
    } catch (\Throwable $e) {
        try {
            Db::rollBack();
        } catch (\Throwable $e2) {
        }
        echo 'FAIL ' . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "done ok={$ok} fail={$fail} sum={$sum}\n";
if ($onlyUid > 0) {
    $acc = Db::fetch(
        'SELECT user_id, hongbao, hongbao_frozen FROM ' . Db::table('fans_account') . ' WHERE user_id=?',
        [$onlyUid]
    );
    echo 'account ' . json_encode($acc, JSON_UNESCAPED_UNICODE) . "\n";
}
