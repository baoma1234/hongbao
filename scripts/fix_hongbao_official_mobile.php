<?php
/**
 * 红宝官方 22222222：手机 18888888880 → 18000000000
 * Usage: php scripts/fix_hongbao_official_mobile.php
 */
$root = dirname(__DIR__);
$envFile = $root . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, "ERROR: .env not found\n");
    exit(1);
}
$env = parse_ini_file($envFile, true);
$db = $env['database'] ?? [];
$host = (string)($db['hostname'] ?? '127.0.0.1');
$port = (string)($db['hostport'] ?? '3306');
$name = (string)($db['database'] ?? '');
$user = (string)($db['username'] ?? '');
$pass = (string)($db['password'] ?? '');
$prefix = (string)($db['prefix'] ?? 'fa_');
if ($name === '' || $user === '') {
    fwrite(STDERR, "ERROR: database credentials missing\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$table = $prefix . 'user';
$id = 22222222;
$oldLocal = '18888888880';
$newLocal = '18000000000';
$newE164 = '+8618000000000';

$row = $pdo->prepare("SELECT id, username, mobile FROM `{$table}` WHERE id=? LIMIT 1");
$row->execute([$id]);
$userRow = $row->fetch(PDO::FETCH_ASSOC);
if (!$userRow) {
    fwrite(STDERR, "ERROR: user {$id} not found\n");
    exit(1);
}
echo "BEFORE id={$userRow['id']} username={$userRow['username']} mobile={$userRow['mobile']}\n";

$clash = $pdo->prepare("SELECT id FROM `{$table}` WHERE (mobile=? OR mobile=? OR username=?) AND id<>? LIMIT 1");
$clash->execute([$newLocal, $newE164, $newLocal, $id]);
if ($clash->fetch(PDO::FETCH_ASSOC)) {
    fwrite(STDERR, "ERROR: {$newLocal} already used by another user\n");
    exit(1);
}

$st = $pdo->prepare("UPDATE `{$table}` SET mobile=?, username=? WHERE id=?");
$st->execute([$newLocal, $newLocal, $id]);
echo "OK updated {$st->rowCount()} row(s) to mobile/username={$newLocal}\n";

$after = $pdo->prepare("SELECT id, username, mobile FROM `{$table}` WHERE id=? LIMIT 1");
$after->execute([$id]);
$a = $after->fetch(PDO::FETCH_ASSOC);
echo "AFTER id={$a['id']} username={$a['username']} mobile={$a['mobile']}\n";
