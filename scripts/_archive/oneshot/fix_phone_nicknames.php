<?php
/**
 * 将昵称为 +86 手机号的用户，改为注册规则昵称：
 * 红宝 + 手机后三位 + 随机三位数（如 红宝834729）
 *
 * 用法:
 *   php scripts/fix_phone_nicknames.php --dry-run
 *   php scripts/fix_phone_nicknames.php
 */
$root = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv ?? [], true);

$ini = @parse_ini_file($root . '/.env', true) ?: [];
$d = $ini['database'] ?? [];
$prefix = isset($d['prefix']) ? $d['prefix'] : 'fa_';
$table = $prefix . 'user';

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
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

function isPlus86PhoneNickname($nick)
{
    $compact = preg_replace('/\s+/', '', trim((string)$nick));
    if ($compact === '') {
        return false;
    }
    if (preg_match('/^\+86\d{11}$/', $compact)) {
        return true;
    }
    if (preg_match('/^86\d{11}$/', $compact)) {
        return true;
    }
    return false;
}

/** 与 Auth::defaultRegisterNickname 一致 */
function makeRegisterNickname(PDO $pdo, $table, $mobile)
{
    $digits = preg_replace('/\D+/', '', (string)$mobile);
    if ($digits === '') {
        $digits = (string)mt_rand(100, 999);
    }
    $tail = strlen($digits) >= 3 ? substr($digits, -3) : str_pad($digits, 3, '0', STR_PAD_LEFT);
    $chk = $pdo->prepare("SELECT id FROM `{$table}` WHERE nickname = ? LIMIT 1");
    for ($i = 0; $i < 30; $i++) {
        $rand = str_pad((string)mt_rand(0, 999), 3, '0', STR_PAD_LEFT);
        $nick = '红宝' . $tail . $rand;
        $chk->execute([$nick]);
        if (!$chk->fetchColumn()) {
            return $nick;
        }
    }
    return '红宝' . $tail . substr((string)time(), -3);
}

$st = $pdo->query(
    "SELECT id, mobile, nickname FROM `{$table}`
     WHERE nickname LIKE '+86%' OR nickname LIKE '86%'"
);
$rows = $st->fetchAll();

$candidates = [];
foreach ($rows as $u) {
    if (isPlus86PhoneNickname($u['nickname'])) {
        $candidates[] = $u;
    }
}

echo ($dryRun ? "[DRY-RUN] " : '') . 'candidates: ' . count($candidates) . "\n";

$upd = $pdo->prepare("UPDATE `{$table}` SET nickname = ? WHERE id = ?");
$updated = 0;
$skipped = 0;

foreach ($candidates as $u) {
    $id = (int)$u['id'];
    $old = (string)$u['nickname'];
    $mobile = (string)$u['mobile'];
    $seed = $mobile !== '' ? $mobile : $old;
    $newNick = makeRegisterNickname($pdo, $table, $seed);
    if ($newNick === $old) {
        $skipped++;
        echo "SKIP id={$id} nick={$old}\n";
        continue;
    }
    if ($dryRun) {
        echo "WOULD id={$id} {$old} => {$newNick}\n";
        $updated++;
        continue;
    }
    $upd->execute([$newNick, $id]);
    echo "OK   id={$id} {$old} => {$newNick}\n";
    $updated++;
}

echo ($dryRun ? '[DRY-RUN] ' : '') . "done updated={$updated} skipped={$skipped}\n";
