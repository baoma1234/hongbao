<?php
/**
 * 创建/校正「深夜欲望」视频发送专用号：ID 11111111
 * - 手机：18888888811
 * - 固定出现在后台 /fanshub/videosend 发送账号下拉
 * - 不托管（不进 chat_agent_accounts）、不进代聊列表
 * - 不自动加好友、不多点登录（仅发视频身份）
 * php scripts/create_ye_videosend_user.php
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

$id = 11111111;
$mobileLocal = '18888888811';
$mobileE164 = '+8618888888811';
$nick = '深夜欲望';
$hongbao = 0.00;
$plainPwd = 'YeSend1111';
$now = time();

$avatarRel = '';
$defaultAvatar = $root . '/public/uploads/avatars/default.png';
$bioAvatarDir = $root . '/public/uploads/avatars/55555555';
$srcCandidates = [];
if (is_dir($bioAvatarDir)) {
    $files = glob($bioAvatarDir . '/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
    if ($files) {
        $srcCandidates[] = $files[0];
    }
}
if (is_file($defaultAvatar)) {
    $srcCandidates[] = $defaultAvatar;
}
foreach ($srcCandidates as $src) {
    if (!is_file($src)) {
        continue;
    }
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
    echo "UPDATE fans_account (is_bot=0)\n";
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
$ag = $pdo->query("SELECT COUNT(*) AS c FROM {$agentTable} WHERE user_id={$id}")->fetch(PDO::FETCH_ASSOC);
echo 'OK user=' . json_encode($u, JSON_UNESCAPED_UNICODE) . "\n";
echo 'OK acct=' . json_encode($a, JSON_UNESCAPED_UNICODE) . "\n";
echo 'OK agent_rows=' . (int)($ag['c'] ?? 0) . "\n";
echo "LOGIN mobile={$mobileLocal} password={$plainPwd}\n";
echo "NOTE: videosend_sender_user_ids in fanshub.php; NOT hosted in imagent\n";
