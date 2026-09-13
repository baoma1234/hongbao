<?php
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
$id = 44444444;
$rel = '/uploads/avatars/44444444/35d0e5d4ca87a972e555e1f3bdeb4d39.png';
$abs = $root . '/public' . $rel;
if (!is_file($abs)) {
    fwrite(STDERR, "avatar missing: {$abs}\n");
    exit(1);
}
$now = time();
$pdo->prepare("UPDATE {$p}user SET avatar=?, nickname=?, updatetime=? WHERE id=?")
    ->execute([$rel, '40ky_客服', $now, $id]);
$u = $pdo->query("SELECT id,nickname,mobile,avatar,status FROM {$p}user WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
echo json_encode($u, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
