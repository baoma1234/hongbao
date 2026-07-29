<?php
$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=caijin_com_7111;charset=utf8mb4',
    'caijin_com_7111',
    'zJ3EkWE47y',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$uid = 56960815;
$row = $pdo->query("SELECT id, money FROM fa_user WHERE id={$uid}")->fetch(PDO::FETCH_ASSOC);
echo "before=" . json_encode($row) . "\n";
$pdo->exec("UPDATE fa_user SET money = money + 100 WHERE id={$uid}");
$row = $pdo->query("SELECT id, money FROM fa_user WHERE id={$uid}")->fetch(PDO::FETCH_ASSOC);
echo "after=" . json_encode($row) . "\n";

// also ensure agent member of group 1 can send rp (role)
$m = $pdo->query("SELECT * FROM fa_chat_group_members WHERE group_id=1 AND user_id={$uid}")->fetch(PDO::FETCH_ASSOC);
echo "member=" . json_encode($m, JSON_UNESCAPED_UNICODE) . "\n";
$g = $pdo->query("SELECT id,name,chat_mode,status,rp_enabled_types,rp_min_amount,rp_min_count FROM fa_chat_groups WHERE id=1")->fetch(PDO::FETCH_ASSOC);
echo "group=" . json_encode($g, JSON_UNESCAPED_UNICODE) . "\n";
