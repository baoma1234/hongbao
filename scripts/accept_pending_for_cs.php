<?php
/**
 * 将发给指定客服的待处理好友申请全部自动通过
 * php scripts/accept_pending_for_cs.php [userId]
 * 默认 44444444；也可传 55555555
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
if (!$env || empty($env['database'])) {
    fwrite(STDERR, ".env database missing\n");
    exit(1);
}
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$p = $d['prefix'] ?? 'fa_';
$csId = isset($argv[1]) ? (int)$argv[1] : 44444444;
if ($csId <= 0) {
    fwrite(STDERR, "invalid user id\n");
    exit(1);
}
$now = time();

$u = $pdo->query("SELECT id,nickname,mobile FROM {$p}user WHERE id={$csId}")->fetch(PDO::FETCH_ASSOC);
if (!$u) {
    fwrite(STDERR, "CS user {$csId} not found\n");
    exit(1);
}
echo 'CS=' . json_encode($u, JSON_UNESCAPED_UNICODE) . "\n";

$pending = $pdo->query(
    "SELECT id, from_user_id, to_user_id FROM {$p}chat_friend_requests
     WHERE to_user_id={$csId} AND status=0"
)->fetchAll(PDO::FETCH_ASSOC);

$n = 0;
$upd = $pdo->prepare(
    "UPDATE {$p}chat_friend_requests
     SET status=1, handle_user_id=?, updatetime=?
     WHERE id=? AND status=0"
);
$findContact = $pdo->prepare(
    "SELECT id,status FROM {$p}chat_contacts WHERE user_id=? AND peer_user_id=? LIMIT 1"
);
$fixContact = $pdo->prepare(
    "UPDATE {$p}chat_contacts SET status=1 WHERE id=?"
);
$insContact = $pdo->prepare(
    "INSERT INTO {$p}chat_contacts (user_id, peer_user_id, status, createtime) VALUES (?,?,1,?)"
);

foreach ($pending as $row) {
    $rid = (int)$row['id'];
    $from = (int)$row['from_user_id'];
    $to = (int)$row['to_user_id'];
    $upd->execute([$to, $now, $rid]);
    if ($upd->rowCount() < 1) {
        echo "skip #{$rid} (already handled)\n";
        continue;
    }

    foreach ([[$from, $to], [$to, $from]] as $pair) {
        $a = $pair[0];
        $b = $pair[1];
        $findContact->execute([$a, $b]);
        $ex = $findContact->fetch(PDO::FETCH_ASSOC);
        if ($ex) {
            if ((int)$ex['status'] !== 1) {
                $fixContact->execute([(int)$ex['id']]);
            }
        } else {
            $insContact->execute([$a, $b, $now]);
        }
    }
    $n++;
    echo "accepted request #{$rid} from {$from}\n";
}
echo "DONE accepted={$n}\n";
