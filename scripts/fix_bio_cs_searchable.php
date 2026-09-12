<?php
/**
 * 将 BIO 客服 55555555 调整为：可后台搜索、可转账、可多点登录；不托管、不自动加好友
 * php scripts/fix_bio_cs_searchable.php
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
$id = 55555555;
$mobileE164 = '+8618888888887';
$nick = 'BIO_客服';
$now = time();

$u = $pdo->query("SELECT id FROM {$p}user WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
if (!$u) {
    fwrite(STDERR, "user {$id} not found\n");
    exit(1);
}

// 手机号与默认客服同格式，便于后台/加好友搜索
$pdo->prepare("UPDATE {$p}user SET nickname=?, mobile=?, username=?, status='normal', updatetime=? WHERE id=?")
    ->execute([$nick, $mobileE164, '18888888887', $now, $id]);

// 普通账号：is_bot=0 才能出现在「用户账户」列表；has_recharged=1 可转账
$pdo->prepare("UPDATE {$p}fans_account SET is_bot=0, has_recharged=1, status='normal', updatetime=? WHERE user_id=?")
    ->execute([$now, $id]);

// 不托管：删除代聊登记
$n = $pdo->exec("DELETE FROM {$p}chat_agent_accounts WHERE user_id={$id}");
echo "deleted agents={$n}\n";

$user = $pdo->query("SELECT id,nickname,mobile,status FROM {$p}user WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
$acct = $pdo->query("SELECT user_id,hongbao,is_bot,has_recharged FROM {$p}fans_account WHERE user_id={$id}")->fetch(PDO::FETCH_ASSOC);
$agent = $pdo->query("SELECT id FROM {$p}chat_agent_accounts WHERE user_id={$id}")->fetch(PDO::FETCH_ASSOC);

// 模拟后台搜索：is_bot=0 + mobile like
$hit = $pdo->query(
    "SELECT a.user_id, u.nickname, u.mobile, a.is_bot
     FROM {$p}fans_account a
     INNER JOIN {$p}user u ON u.id=a.user_id
     WHERE a.is_bot=0 AND u.mobile LIKE '%18888888887%'
     LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);

echo 'user=' . json_encode($user, JSON_UNESCAPED_UNICODE) . "\n";
echo 'acct=' . json_encode($acct, JSON_UNESCAPED_UNICODE) . "\n";
echo 'agent=' . json_encode($agent, JSON_UNESCAPED_UNICODE) . "\n";
echo 'search_hit=' . json_encode($hit, JSON_UNESCAPED_UNICODE) . "\n";
echo "OK — 多点登录靠 fanshub.php multi_login_user_ids；不会自动加新用户好友（仅默认客服 88888888）\n";
