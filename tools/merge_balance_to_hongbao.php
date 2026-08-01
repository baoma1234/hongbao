<?php
/**
 * Merge fa_fans_account.balance into hongbao, zero balance.
 * php tools/merge_balance_to_hongbao.php
 */
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=caijin_com_7111;charset=utf8mb4',
    'caijin_com_7111',
    'zJ3EkWE47y',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$before = $pdo->query("SELECT COUNT(*) AS c, ROUND(SUM(balance),2) AS sb, ROUND(SUM(IFNULL(hongbao,0)),2) AS sh FROM fa_fans_account WHERE IFNULL(balance,0)<>0")->fetch(PDO::FETCH_ASSOC);
echo "accounts_with_balance={$before['c']} sum_balance={$before['sb']} sum_hongbao_those={$before['sh']}\n";

$pdo->beginTransaction();
try {
    // ledger audit rows for non-zero balance merges
    $rows = $pdo->query("SELECT user_id, balance, IFNULL(hongbao,0) AS hongbao, rights FROM fa_fans_account WHERE IFNULL(balance,0)<>0")->fetchAll(PDO::FETCH_ASSOC);
    $now = time();
    $ins = $pdo->prepare(
        "INSERT INTO fa_fans_ledger
        (user_id,type,rights_change,balance_change,hongbao_change,rights_after,balance_after,hongbao_after,remark,channel,admin_id,createtime)
        VALUES (?,?,0,?,?,?,?,?,?, 'system',0,?)"
    );
    foreach ($rows as $r) {
        $uid = (int)$r['user_id'];
        $bal = round((float)$r['balance'], 2);
        $hb = round((float)$r['hongbao'], 2);
        $rights = round((float)$r['rights'], 2);
        $hbAfter = round($hb + $bal, 2);
        $ins->execute([
            $uid,
            'balance_merge_hongbao',
            sprintf('%.2f', -$bal),
            sprintf('%.2f', $bal),
            sprintf('%.2f', $rights),
            '0.00',
            sprintf('%.2f', $hbAfter),
            '余额并入红宝',
            $now,
        ]);
    }

    $pdo->exec("UPDATE fa_fans_account SET hongbao = ROUND(IFNULL(hongbao,0) + IFNULL(balance,0), 2), balance = 0 WHERE IFNULL(balance,0) <> 0");
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$after = $pdo->query("SELECT ROUND(SUM(IFNULL(balance,0)),2) AS sb, ROUND(SUM(IFNULL(hongbao,0)),2) AS sh FROM fa_fans_account")->fetch(PDO::FETCH_ASSOC);
echo "done sum_balance={$after['sb']} sum_hongbao={$after['sh']} merged_rows=" . count($rows) . "\n";
