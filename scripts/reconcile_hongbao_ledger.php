<?php
/**
 * 红宝余额 ↔ 流水结余日检（只读）
 *
 * 规则：
 * 1) fans_account.hongbao ≈ 最新流水 hongbao_after（容差默认 0.01）
 * 2) fans_account.hongbao_frozen ≈ SUM(records.frozen_amount WHERE freeze_status=1)
 * 3) 不允许负余额 / 负冻结
 *
 * Usage:
 *   php scripts/reconcile_hongbao_ledger.php
 *   php scripts/reconcile_hongbao_ledger.php --limit=50 --json
 *   php scripts/reconcile_hongbao_ledger.php --user=123
 * Exit: 0=无差异, 1=有差异或错误
 */
$imRoot = dirname(__DIR__) . '/im-server';
require $imRoot . '/vendor/autoload.php';
$cfg = require $imRoot . '/config/app.php';
Im\Support\Db::init($cfg['db']);

$opts = [
    'limit' => 50,
    'user'  => 0,
    'json'  => false,
    'tol'   => 0.01,
];
foreach ($argv ?? [] as $arg) {
    if ($arg === '--json') {
        $opts['json'] = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $opts['limit'] = max(1, min(500, (int)$m[1]));
    } elseif (preg_match('/^--user=(\d+)$/', $arg, $m)) {
        $opts['user'] = (int)$m[1];
    } elseif (preg_match('/^--tol=([\d.]+)$/', $arg, $m)) {
        $opts['tol'] = max(0.0, (float)$m[1]);
    }
}

$acc = Im\Support\Db::table('fans_account');
$led = Im\Support\Db::table('fans_ledger');
$rec = Im\Support\Db::table('chat_red_packet_records');
$tol = (float)$opts['tol'];
$limit = (int)$opts['limit'];

$hasBot = false;
try {
    $cols = Im\Support\Db::fetchAll("SHOW COLUMNS FROM {$acc} LIKE 'is_bot'");
    $hasBot = !empty($cols);
} catch (\Throwable $e) {
    $hasBot = false;
}

$botFilter = $hasBot ? ' AND IFNULL(a.is_bot,0)=0' : '';
$userFilter = $opts['user'] > 0 ? (' AND a.user_id=' . (int)$opts['user']) : '';
$userFilterPlain = $opts['user'] > 0 ? (' AND user_id=' . (int)$opts['user']) : '';

try {
    $balMismatches = Im\Support\Db::fetchAll("
SELECT a.user_id,
       ROUND(a.hongbao, 2) AS hongbao,
       ROUND(a.hongbao_frozen, 2) AS hongbao_frozen,
       ROUND(IFNULL(l.hongbao_after, 0), 2) AS ledger_after,
       IFNULL(l.id, 0) AS ledger_id,
       ROUND(ABS(a.hongbao - IFNULL(l.hongbao_after, 0)), 2) AS diff
FROM {$acc} a
LEFT JOIN (
  SELECT user_id, MAX(id) AS max_id
  FROM {$led}
  GROUP BY user_id
) m ON m.user_id = a.user_id
LEFT JOIN {$led} l ON l.id = m.max_id
WHERE a.status = 'normal'
  {$botFilter}
  {$userFilter}
HAVING ABS(hongbao - ledger_after) > {$tol}
   OR (ledger_id = 0 AND ABS(hongbao) > {$tol})
ORDER BY diff DESC
LIMIT {$limit}
") ?: [];

    $freezeMismatches = Im\Support\Db::fetchAll("
SELECT a.user_id,
       ROUND(a.hongbao_frozen, 2) AS account_frozen,
       ROUND(IFNULL(f.open_frozen, 0), 2) AS records_frozen,
       ROUND(ABS(a.hongbao_frozen - IFNULL(f.open_frozen, 0)), 2) AS diff
FROM {$acc} a
LEFT JOIN (
  SELECT user_id, SUM(frozen_amount) AS open_frozen
  FROM {$rec}
  WHERE freeze_status = 1
  GROUP BY user_id
) f ON f.user_id = a.user_id
WHERE a.status = 'normal'
  {$botFilter}
  {$userFilter}
HAVING ABS(account_frozen - records_frozen) > {$tol}
ORDER BY diff DESC
LIMIT {$limit}
") ?: [];

    $botNeg = $hasBot ? ' AND IFNULL(is_bot,0)=0' : '';
    $neg = Im\Support\Db::fetchAll("
SELECT user_id, hongbao, hongbao_frozen
FROM {$acc}
WHERE status='normal'
  {$botNeg}
  {$userFilterPlain}
  AND (hongbao < -{$tol} OR hongbao_frozen < -{$tol})
LIMIT {$limit}
") ?: [];
} catch (\Throwable $e) {
    $err = ['ok' => false, 'error' => $e->getMessage()];
    if ($opts['json']) {
        echo json_encode($err, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        fwrite(STDERR, 'ERROR ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}

$summary = [
    'balance_mismatches' => count($balMismatches),
    'freeze_mismatches'  => count($freezeMismatches),
    'negative_balances'  => count($neg),
    'has_is_bot_column'  => $hasBot,
    'tol'                => $tol,
    'limit'              => $limit,
    'ts'                 => time(),
];
$ok = $summary['balance_mismatches'] === 0
    && $summary['freeze_mismatches'] === 0
    && $summary['negative_balances'] === 0;

$out = [
    'ok'               => $ok,
    'summary'          => $summary,
    'balance_samples'  => $balMismatches,
    'freeze_samples'   => $freezeMismatches,
    'negative_samples' => $neg,
];

if ($opts['json']) {
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($ok ? 0 : 1);
}

echo ($ok ? 'OK' : 'FAIL') . " hongbao ledger reconcile\n";
echo "  balance mismatches: {$summary['balance_mismatches']}\n";
echo "  freeze mismatches:  {$summary['freeze_mismatches']}\n";
echo "  negative balances:  {$summary['negative_balances']}\n";
if (!$hasBot) {
    echo "  note: fans_account.is_bot missing on this DB; bot filter skipped\n";
}
if ($balMismatches) {
    echo "--- balance samples ---\n";
    foreach (array_slice($balMismatches, 0, 20) as $r) {
        echo sprintf(
            "  uid=%s hongbao=%s ledger_after=%s ledger_id=%s diff=%s\n",
            $r['user_id'],
            $r['hongbao'],
            $r['ledger_after'],
            $r['ledger_id'],
            $r['diff']
        );
    }
}
if ($freezeMismatches) {
    echo "--- freeze samples ---\n";
    foreach (array_slice($freezeMismatches, 0, 20) as $r) {
        echo sprintf(
            "  uid=%s account_frozen=%s records_frozen=%s diff=%s\n",
            $r['user_id'],
            $r['account_frozen'],
            $r['records_frozen'],
            $r['diff']
        );
    }
}
if ($neg) {
    echo "--- negative samples ---\n";
    foreach (array_slice($neg, 0, 20) as $r) {
        echo sprintf(
            "  uid=%s hongbao=%s frozen=%s\n",
            $r['user_id'],
            $r['hongbao'],
            $r['hongbao_frozen']
        );
    }
}
exit($ok ? 0 : 1);
