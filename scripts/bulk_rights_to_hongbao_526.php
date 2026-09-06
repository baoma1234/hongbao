<?php
/**
 * 全量：用户股份 → 红宝，汇率 1 股 = 5.26 红宝
 *
 * 用法：
 *   php scripts/bulk_rights_to_hongbao_526.php           # dry-run 预览
 *   php scripts/bulk_rights_to_hongbao_526.php --apply   # 正式执行
 *   php scripts/bulk_rights_to_hongbao_526.php --apply --include-bot
 *
 * 默认跳过 is_bot=1；冻结账户也会强制兑完（直接 SQL，并写 ledger）。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
$envFile = $root . DIRECTORY_SEPARATOR . '.env';
if (!is_file($envFile)) {
    fwrite(STDERR, ".env missing\n");
    exit(1);
}
$cfg = parse_ini_file($envFile, true);
$db = $cfg['database'] ?? [];
$host = $db['hostname'] ?? '127.0.0.1';
$name = $db['database'] ?? '';
$user = $db['username'] ?? '';
$pass = $db['password'] ?? '';
$prefix = $db['prefix'] ?? 'fa_';

$apply = in_array('--apply', $argv, true);
$includeBot = in_array('--include-bot', $argv, true);
$rate = 5.26;
$batchRemark = '全量股份按1:5.26兑红宝';
$channel = 'bulk_r2h_526';
$type = 'admin_adjust';

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$botClause = $includeBot ? '' : ' AND is_bot=0';

$sumSql = "SELECT
  COUNT(*) AS cnt_all,
  SUM(CASE WHEN rights>0 {$botClause} THEN 1 ELSE 0 END) AS cnt_target,
  ROUND(COALESCE(SUM(CASE WHEN rights>0 {$botClause} THEN rights ELSE 0 END),0),2) AS sum_rights,
  ROUND(COALESCE(SUM(CASE WHEN rights>0 {$botClause} THEN rights_locked ELSE 0 END),0),2) AS sum_locked,
  ROUND(COALESCE(SUM(CASE WHEN rights>0 {$botClause} THEN hongbao ELSE 0 END),0),2) AS sum_hongbao_before,
  SUM(CASE WHEN rights>0 {$botClause} AND status<>'normal' THEN 1 ELSE 0 END) AS cnt_frozen
FROM {$prefix}fans_account";

$st = $pdo->query($sumSql);
$stats = $st->fetch();
$sumRights = (float)$stats['sum_rights'];
$creditTotal = round($sumRights * $rate, 2);

echo "=== bulk rights → hongbao @ {$rate} ===\n";
echo 'mode: ' . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
echo 'include_bot: ' . ($includeBot ? 'yes' : 'no') . "\n";
echo 'cnt_target: ' . (int)$stats['cnt_target'] . "\n";
echo 'cnt_frozen_in_target: ' . (int)$stats['cnt_frozen'] . "\n";
echo 'sum_rights: ' . number_format($sumRights, 2, '.', '') . "\n";
echo 'sum_locked: ' . number_format((float)$stats['sum_locked'], 2, '.', '') . "\n";
echo 'sum_hongbao_before(of targets): ' . number_format((float)$stats['sum_hongbao_before'], 2, '.', '') . "\n";
echo 'credit_total: ' . number_format($creditTotal, 2, '.', '') . "\n";

if ($sumRights <= 0) {
    echo "nothing to convert\n";
    exit(0);
}

$rows = $pdo->query(
    "SELECT user_id, rights, rights_locked, hongbao, status, is_bot
     FROM {$prefix}fans_account
     WHERE rights>0 {$botClause}
     ORDER BY user_id ASC"
)->fetchAll();

echo 'rows: ' . count($rows) . "\n";

if (!$apply) {
    $preview = array_slice($rows, 0, 15);
    echo "--- preview top 15 ---\n";
    foreach ($preview as $r) {
        $rights = round((float)$r['rights'], 2);
        $credit = round($rights * $rate, 2);
        echo sprintf(
            "uid=%s rights=%s credit=%s hongbao=%s status=%s bot=%s\n",
            $r['user_id'],
            number_format($rights, 2, '.', ''),
            number_format($credit, 2, '.', ''),
            number_format((float)$r['hongbao'], 2, '.', ''),
            $r['status'],
            $r['is_bot']
        );
    }
    echo "Dry-run only. Re-run with --apply to execute.\n";
    exit(0);
}

$ok = 0;
$fail = 0;
$sumCredited = 0.0;
$now = time();

$pdo->beginTransaction();
try {
    $sel = $pdo->prepare(
        "SELECT user_id, rights, rights_locked, hongbao, status
         FROM {$prefix}fans_account WHERE user_id=? FOR UPDATE"
    );
    $upd = $pdo->prepare(
        "UPDATE {$prefix}fans_account
         SET rights=0, rights_locked=0, rights_lock_day=NULL, balance=0,
             hongbao=?, updatetime=?
         WHERE user_id=?"
    );
    $ins = $pdo->prepare(
        "INSERT INTO {$prefix}fans_ledger
         (user_id, type, rights_change, balance_change, hongbao_change,
          rights_after, balance_after, hongbao_after, remark, channel, admin_id, createtime)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    );

    foreach ($rows as $r) {
        $uid = (int)$r['user_id'];
        $sel->execute([$uid]);
        $acc = $sel->fetch();
        if (!$acc) {
            $fail++;
            continue;
        }
        $rights = round((float)$acc['rights'], 2);
        if ($rights <= 0) {
            continue;
        }
        $credit = round($rights * $rate, 2);
        $hongbaoAfter = round((float)$acc['hongbao'] + $credit, 2);

        $upd->execute([$hongbaoAfter, $now, $uid]);
        $ins->execute([
            $uid,
            $type,
            -$rights,
            0,
            $credit,
            0,
            0,
            $hongbaoAfter,
            $batchRemark . ' ' . number_format($rights, 2, '.', '') . '股→' . number_format($credit, 2, '.', ''),
            $channel,
            0,
            $now,
        ]);
        $ok++;
        $sumCredited = round($sumCredited + $credit, 2);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

// 校验
$after = $pdo->query(
    "SELECT
      ROUND(COALESCE(SUM(CASE WHEN rights>0 {$botClause} THEN rights ELSE 0 END),0),2) AS remain_rights,
      ROUND(COALESCE(SUM(hongbao),0),2) AS sum_hongbao_all
     FROM {$prefix}fans_account"
)->fetch();

echo "=== done ===\n";
echo "ok={$ok} fail={$fail}\n";
echo 'sum_credited: ' . number_format($sumCredited, 2, '.', '') . "\n";
echo 'remain_rights(target filter): ' . number_format((float)$after['remain_rights'], 2, '.', '') . "\n";
echo 'sum_hongbao_all: ' . number_format((float)$after['sum_hongbao_all'], 2, '.', '') . "\n";
echo "IM wallet cache: users may need refresh; bust on next open.\n";
