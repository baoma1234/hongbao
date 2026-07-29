<?php
$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=caijin_com_7111;charset=utf8mb4',
    'caijin_com_7111',
    'zJ3EkWE47y',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$uid = 56960815;
$row = $pdo->query("SELECT * FROM fa_fans_account WHERE user_id={$uid}")->fetch(PDO::FETCH_ASSOC);
echo "account=" . json_encode($row) . "\n";
if (!$row) {
    $now = time();
    $pdo->exec("INSERT INTO fa_fans_account (user_id,rights,balance,status,createtime,updatetime) VALUES ({$uid},0,200,'normal',{$now},{$now})");
} else {
    $pdo->exec("UPDATE fa_fans_account SET balance = balance + 200 WHERE user_id={$uid}");
}
$row = $pdo->query("SELECT user_id,balance,status FROM fa_fans_account WHERE user_id={$uid}")->fetch(PDO::FETCH_ASSOC);
echo "after=" . json_encode($row) . "\n";
