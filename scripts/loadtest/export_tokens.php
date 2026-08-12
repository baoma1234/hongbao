<?php
/**
 * 为压测导出明文 token（库内只存 HMAC，无法反查已有 token）
 *
 * 用法（在项目根目录）：
 *   php scripts/loadtest/export_tokens.php --count=1000
 *   php scripts/loadtest/export_tokens.php --reuse-bots --count=5000
 *   php scripts/loadtest/export_tokens.php --user-ids=90000001,90000002
 *
 * 输出：scripts/loadtest/tokens.json
 * 格式：[{"user_id":123,"token":"uuid","device_fp":"load-123"}]
 */
$root = dirname(__DIR__, 2);
$count = 1000;
$reuseBots = false;
$userIds = [];
$outFile = __DIR__ . '/tokens.json';
$expireDays = 30;
$tokenPrefix = 'loadtest';

foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--count=(\d+)$/', $arg, $m)) {
        $count = max(1, min(20000, (int)$m[1]));
    } elseif ($arg === '--reuse-bots') {
        $reuseBots = true;
    } elseif (preg_match('/^--user-ids=(.+)$/', $arg, $m)) {
        $userIds = array_filter(array_map('intval', explode(',', $m[1])));
    } elseif (preg_match('/^--out=(.+)$/', $arg, $m)) {
        $outFile = $m[1];
        if (!preg_match('/^[A-Za-z]:\\\\|^\/', $outFile)) {
            $outFile = $root . '/' . ltrim($outFile, '/');
        }
    } elseif (preg_match('/^--expire-days=(\d+)$/', $arg, $m)) {
        $expireDays = max(1, (int)$m[1]);
    }
}

$envFile = $root . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, "missing .env\n");
    exit(1);
}
$env = parse_ini_file($envFile, true);
$d = $env['database'] ?? [];
$prefix = $d['prefix'] ?? 'fa_';
$userT = $prefix . 'user';
$accT = $prefix . 'fans_account';
$tokT = $prefix . 'user_token';

$cfgFile = $root . '/application/config.php';
$tokenKey = 'H1Ln476zwoJmU0Y2bCPD5QEqOcyZTkvG';
$hashAlgo = 'ripemd160';
if (is_file($cfgFile)) {
    $cfg = include $cfgFile;
    if (is_array($cfg) && !empty($cfg['token']) && is_array($cfg['token'])) {
        $tokenKey = (string)($cfg['token']['key'] ?? $tokenKey);
        $hashAlgo = (string)($cfg['token']['hashalgo'] ?? $hashAlgo);
    }
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $d['hostname'] ?? '127.0.0.1',
        (int)($d['hostport'] ?? 3306),
        $d['database'] ?? ''
    ),
    $d['username'] ?? 'root',
    $d['password'] ?? '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

function encryptToken($plain, $algo, $key)
{
    if ($algo === '' || $key === '') {
        return $plain;
    }
    return hash_hmac($algo, $plain, $key);
}

function makeUuid()
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function pickUserIds(PDO $pdo, $userT, $accT, $reuseBots, array $explicit, $count)
{
    if ($explicit) {
        return array_values(array_unique(array_filter(array_map('intval', $explicit))));
    }
    if ($reuseBots) {
        $sql = "SELECT u.id FROM `{$userT}` u
                INNER JOIN `{$accT}` a ON a.user_id = u.id
                WHERE u.status = 'normal' AND IFNULL(a.is_bot,0) = 1
                ORDER BY u.id ASC LIMIT " . (int)$count;
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        if ($rows) {
            return array_map('intval', $rows);
        }
        fwrite(STDERR, "warn: no bot users found, fallback to any normal users\n");
    }
    $sql = "SELECT id FROM `{$userT}` WHERE status = 'normal' ORDER BY id ASC LIMIT " . (int)$count;
    return array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
}

$uids = pickUserIds($pdo, $userT, $accT, $reuseBots, $userIds, $count);
if (!$uids) {
    fwrite(STDERR, "no users; run scripts/seed_bot_users.php first or pass --user-ids\n");
    exit(1);
}
if (count($uids) > $count) {
    $uids = array_slice($uids, 0, $count);
}

$expireAt = time() + $expireDays * 86400;
$delOld = $pdo->prepare("DELETE FROM `{$tokT}` WHERE user_id = ?");
$ins = $pdo->prepare(
    "INSERT INTO `{$tokT}` (token, user_id, createtime, expiretime) VALUES (?,?,?,?)"
);

$out = [];
$pdo->beginTransaction();
try {
    foreach ($uids as $uid) {
        $plain = makeUuid();
        $stored = encryptToken($plain, $hashAlgo, $tokenKey);
        // 每用户只保留一个压测 token，避免 session.replaced 互踢
        $delOld->execute([$uid]);
        $ins->execute([$stored, $uid, time(), $expireAt]);
        $out[] = [
            'user_id'   => $uid,
            'token'     => $plain,
            'device_fp' => $tokenPrefix . '-' . $uid,
        ];
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
file_put_contents($outFile, $json . "\n");
echo "exported " . count($out) . " tokens -> {$outFile}\n";
echo "expire_at=" . date('Y-m-d H:i:s', $expireAt) . "\n";
