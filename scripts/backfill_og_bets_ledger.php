<?php
/**
 * 回填 OG 注单到资金流水（og_live / 真人视讯）— PDO 版
 * php scripts/backfill_og_bets_ledger.php
 */
$root = dirname(__DIR__);
$e = parse_ini_file($root . '/.env', true);
$d = $e['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$p = $d['prefix'] ?? 'fa_';

// relink u-format players
$st = $pdo->query("SELECT id, player_id FROM {$p}fans_og_bet WHERE user_id=0 AND player_id LIKE 'u%' LIMIT 10000");
$relink = 0;
$updBet = $pdo->prepare("UPDATE {$p}fans_og_bet SET user_id=?, updatetime=? WHERE id=?");
foreach ($st as $row) {
    $pid = (string)$row['player_id'];
    if (!preg_match('/^u0*([1-9]\d*)$/i', $pid, $m)) {
        continue;
    }
    $uid = (int)$m[1];
    $exists = $pdo->query("SELECT id FROM {$p}user WHERE id={$uid} LIMIT 1")->fetchColumn();
    if (!$exists) {
        continue;
    }
    $updBet->execute([$uid, time(), (int)$row['id']]);
    $relink++;
}
echo "relinked={$relink}\n";

$bets = $pdo->query("SELECT * FROM {$p}fans_og_bet WHERE user_id>0 ORDER BY id ASC LIMIT 10000")->fetchAll(PDO::FETCH_ASSOC);
$findByRef = $pdo->prepare("SELECT id FROM {$p}fans_ledger WHERE user_id=? AND type='og_live' AND ref_type='og_bet' AND ref_id=? LIMIT 1");
$findByBiz = $pdo->prepare("SELECT id FROM {$p}fans_ledger WHERE user_id=? AND type='og_live' AND biz_no=? LIMIT 1");
$accSt = $pdo->prepare("SELECT hongbao,rights,balance FROM {$p}fans_account WHERE user_id=? LIMIT 1");
$ins = $pdo->prepare(
    "INSERT INTO {$p}fans_ledger (user_id,type,rights_change,balance_change,hongbao_change,rights_after,balance_after,hongbao_after,remark,channel,biz_no,ref_type,ref_id,admin_id,createtime)
     VALUES (?,?,0,0,?,?,?,?,?,?,?,?,?,0,?)"
);
$upd = $pdo->prepare(
    "UPDATE {$p}fans_ledger SET hongbao_change=?, rights_after=?, balance_after=?, hongbao_after=?, remark=?, channel='og_live', biz_no=?, ref_type='og_bet', ref_id=? WHERE id=?"
);

$n = 0;
foreach ($bets as $bet) {
    $uid = (int)$bet['user_id'];
    $betId = (int)$bet['id'];
    $txid = trim((string)$bet['transaction_id']);
    $winlose = round((float)$bet['winlose_amount'], 2);
    $debit = round((float)$bet['debit_amount'], 2);
    $credit = round((float)$bet['credit_amount'], 2);
    $gameName = trim((string)$bet['game_name']);
    $betPlace = trim((string)$bet['bet_place']);
    $roundId = (int)$bet['round_id'];
    $debitAt = (int)$bet['debit_at'];
    $parts = ['真人视讯'];
    if ($gameName !== '') {
        $parts[] = $gameName;
    }
    if ($betPlace !== '') {
        $parts[] = $betPlace;
    }
    if ($roundId > 0) {
        $parts[] = '局#' . $roundId;
    }
    if ($debit > 0.00001 || $credit > 0.00001) {
        $parts[] = sprintf('投%.2f/派%.2f', $debit, $credit);
    }
    $remark = mb_substr(implode(' · ', $parts), 0, 250);
    $bizNo = $txid !== '' ? mb_substr($txid, 0, 40) : ('ogbet' . $betId);
    $createtime = $debitAt > 0 ? $debitAt : (int)$bet['createtime'];

    $accSt->execute([$uid]);
    $acc = $accSt->fetch(PDO::FETCH_ASSOC) ?: [];
    $hongbaoAfter = round((float)($acc['hongbao'] ?? 0), 2);
    $rightsAfter = round((float)($acc['rights'] ?? 0), 2);
    $balanceAfter = round((float)($acc['balance'] ?? 0), 2);

    $findByRef->execute([$uid, $betId]);
    $existId = (int)$findByRef->fetchColumn();
    if ($existId <= 0 && $bizNo !== '') {
        $findByBiz->execute([$uid, $bizNo]);
        $existId = (int)$findByBiz->fetchColumn();
    }
    if ($existId > 0) {
        $upd->execute([$winlose, $rightsAfter, $balanceAfter, $hongbaoAfter, $remark, $bizNo, $betId, $existId]);
    } else {
        $ins->execute([
            $uid, 'og_live', $winlose, $rightsAfter, $balanceAfter, $hongbaoAfter,
            $remark, 'og_live', $bizNo, 'og_bet', $betId, $createtime,
        ]);
    }
    $n++;
}
echo "ledgered={$n}\n";
echo "og_live_total=" . $pdo->query("SELECT COUNT(*) FROM {$p}fans_ledger WHERE type='og_live'")->fetchColumn() . "\n";
foreach ($pdo->query("SELECT user_id,hongbao_change,remark FROM {$p}fans_ledger WHERE type='og_live' ORDER BY id DESC LIMIT 5") as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
echo "DONE\n";
