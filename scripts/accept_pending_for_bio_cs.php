<?php
/**
 * 将发给 BIO_客服(55555555) 的待处理好友申请全部自动通过
 * php scripts/accept_pending_for_bio_cs.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$p = $d['prefix'] ?? 'fa_';
$bioId = 55555555;
$now = time();

$pending = $pdo->query(
    "SELECT id, from_user_id, to_user_id FROM {$p}chat_friend_requests
     WHERE to_user_id={$bioId} AND status=0"
)->fetchAll(PDO::FETCH_ASSOC);

$n = 0;
foreach ($pending as $row) {
    $rid = (int)$row['id'];
    $from = (int)$row['from_user_id'];
    $to = (int)$row['to_user_id'];
    $pdo->prepare(
        "UPDATE {$p}chat_friend_requests SET status=1, handled_by=?, handletime=?, updatetime=? WHERE id=? AND status=0"
    )->execute([$to, $now, $now, $rid]);

    foreach ([[$from, $to], [$to, $from]] as $pair) {
        $a = $pair[0];
        $b = $pair[1];
        $ex = $pdo->query(
            "SELECT id,status FROM {$p}chat_contacts WHERE user_id={$a} AND peer_user_id={$b} LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if ($ex) {
            if ((int)$ex['status'] !== 1) {
                $pdo->prepare("UPDATE {$p}chat_contacts SET status=1 WHERE id=?")->execute([(int)$ex['id']]);
            }
        } else {
            $pdo->prepare(
                "INSERT INTO {$p}chat_contacts (user_id, peer_user_id, status, createtime) VALUES (?,?,1,?)"
            )->execute([$a, $b, $now]);
        }
    }
    $n++;
    echo "accepted request #{$rid} from {$from}\n";
}
echo "DONE accepted={$n}\n";
