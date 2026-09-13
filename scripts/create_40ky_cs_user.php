<?php
/**
 * 创建/校正 40ky 客服账号：ID 44444444
 * 功能对齐 BIO_客服 55555555：
 * - 可后台搜索（is_bot=0）
 * - 可转账（has_recharged=1）
 * - 多点登录：fanshub.php multi_login_user_ids
 * - 自动通过好友：auto_accept_friend_user_ids / AdminService 硬编码
 * - 不托管（不进 chat_agent_accounts）、不是默认客服
 * php scripts/create_40ky_cs_user.php
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
$prefix = $d['prefix'] ?? 'fa_';

$id = 44444444;
$mobileLocal = '18888888840';
$mobileE164 = '+8618888888840';
$nick = '40ky_客服';
$hongbao = 1500.00;
$plainPwd = 'KyCs4444';
$now = time();

// 头像：scripts/assets/40ky-cs-avatar.png（入库）> 已有 uploads 官方图 > BIO 头像
$avatarRel = '';
$srcCandidates = [
    $root . '/scripts/assets/40ky-cs-avatar.png',
    $root . '/public/uploads/avatars/44444444/35d0e5d4ca87a972e555e1f3bdeb4d39.png',
];
$bioAvatarDir = $root . '/public/uploads/avatars/55555555';
if (is_dir($bioAvatarDir)) {
    $files = glob($bioAvatarDir . '/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
    if ($files) {
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $srcCandidates[] = $files[0];
    }
}
foreach ($srcCandidates as $c) {
    if (!is_file($c)) {
        continue;
    }
    $src = $c;
    $avatarDir = $root . '/public/uploads/avatars/' . $id;
    if (!is_dir($avatarDir)) {
        mkdir($avatarDir, 0755, true);
    }
    $bin = file_get_contents($src);
    $hash = md5($bin);
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION) ?: 'png');
    $avatarRel = '/uploads/avatars/' . $id . '/' . $hash . '.' . $ext;
    $avatarAbs = $root . '/public' . $avatarRel;
    if (!is_file($avatarAbs)) {
        file_put_contents($avatarAbs, $bin);
    }
    echo "AVATAR {$avatarRel}\n";
    break;
}

$salt = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6);
$pwd = md5(md5($plainPwd) . $salt);

$userTable = $prefix . 'user';
$acctTable = $prefix . 'fans_account';
$agentTable = $prefix . 'chat_agent_accounts';

$exist = $pdo->query("SELECT id FROM {$userTable} WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
$mobileHit = $pdo->query(
    "SELECT id FROM {$userTable} WHERE mobile IN (" . $pdo->quote($mobileLocal) . ',' . $pdo->quote($mobileE164) . ',' . $pdo->quote('+86' . $mobileLocal) . ')'
)->fetch(PDO::FETCH_ASSOC);
if ($mobileHit && (int)$mobileHit['id'] !== $id) {
    fwrite(STDERR, "MOBILE occupied by user #{$mobileHit['id']}\n");
    exit(1);
}

if ($exist) {
    echo "UPDATE user #{$id}\n";
    $sql = "UPDATE {$userTable} SET nickname=?, mobile=?, username=?, status='normal', password=?, salt=?, updatetime=?";
    $args = [$nick, $mobileE164, $mobileLocal, $pwd, $salt, $now];
    if ($avatarRel !== '') {
        $sql .= ', avatar=?';
        $args[] = $avatarRel;
    }
    $sql .= ' WHERE id=?';
    $args[] = $id;
    $pdo->prepare($sql)->execute($args);
} else {
    echo "INSERT user #{$id}\n";
    $pdo->prepare("INSERT INTO {$userTable}
        (id, group_id, username, nickname, password, salt, email, mobile, avatar, level, score,
         jointime, joinip, logintime, loginip, prevtime, status, createtime, updatetime)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
        $id, 1, $mobileLocal, $nick, $pwd, $salt, '', $mobileE164, $avatarRel, 1, 0,
        $now, '127.0.0.1', $now, '127.0.0.1', $now, 'normal', $now, $now,
    ]);
}

$acct = $pdo->query("SELECT user_id FROM {$acctTable} WHERE user_id={$id}")->fetch(PDO::FETCH_ASSOC);
if ($acct) {
    echo "UPDATE fans_account (is_bot=0, has_recharged=1)\n";
    $pdo->prepare("UPDATE {$acctTable} SET hongbao=?, is_bot=0, has_recharged=1, status='normal', updatetime=? WHERE user_id=?")
        ->execute([$hongbao, $now, $id]);
} else {
    echo "INSERT fans_account\n";
    try {
        $pdo->prepare("INSERT INTO {$acctTable}
            (id, user_id, rights, balance, hongbao, flow_stage, member_level, status, is_bot, has_recharged, createtime, updatetime)
            VALUES (?,?,0,0,?,?,1,'normal',0,1,?,?)")
            ->execute([$id, $id, $hongbao, 'stage1', $now, $now]);
    } catch (Throwable $e) {
        $pdo->prepare("INSERT INTO {$acctTable} (id, user_id, hongbao, status, is_bot, has_recharged, createtime, updatetime)
            VALUES (?,?,?,'normal',0,1,?,?)")
            ->execute([$id, $id, $hongbao, $now, $now]);
    }
}

$n = $pdo->exec("DELETE FROM {$agentTable} WHERE user_id={$id}");
echo "ensure no agent deleted={$n}\n";

$u = $pdo->query("SELECT id, nickname, mobile, avatar, status FROM {$userTable} WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
$a = $pdo->query("SELECT user_id, hongbao, is_bot, has_recharged, status FROM {$acctTable} WHERE user_id={$id}")->fetch(PDO::FETCH_ASSOC);
echo 'OK user=' . json_encode($u, JSON_UNESCAPED_UNICODE) . "\n";
echo 'OK acct=' . json_encode($a, JSON_UNESCAPED_UNICODE) . "\n";
echo "LOGIN mobile={$mobileLocal} password={$plainPwd}\n";
echo "NOTE: multi-login + auto-accept friend via config / AdminService hardcoded IDs\n";
