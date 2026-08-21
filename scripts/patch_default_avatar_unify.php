<?php
/**
 * Replace SVG data-URI / empty / old default avatars with unified default.
 * php scripts/patch_default_avatar_unify.php
 * php scripts/patch_default_avatar_unify.php --dry-run
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
if (!$env || empty($env['database'])) {
    fwrite(STDERR, "Missing .env database\n");
    exit(1);
}
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$table = $prefix . 'user';
$default = '/uploads/20260813/f48cc40355dd0f6d814e68ff6e414443.png';
$dry = in_array('--dry-run', $argv, true);

$countSql = "SELECT COUNT(*) FROM `{$table}` WHERE
  avatar IS NULL OR avatar='' OR avatar LIKE 'data:image/svg%'
  OR avatar LIKE '%/uploads/brand/default-avatar.png%'";
$n = (int)$pdo->query($countSql)->fetchColumn();
echo "Matched users: {$n}\n";
echo "Default avatar: {$default}\n";

if ($dry) {
    echo "Dry-run only, no update.\n";
    exit(0);
}

$sql = "UPDATE `{$table}` SET avatar=:av WHERE
  avatar IS NULL OR avatar='' OR avatar LIKE 'data:image/svg%'
  OR avatar LIKE '%/uploads/brand/default-avatar.png%'";
$st = $pdo->prepare($sql);
$st->execute([':av' => $default]);
echo "Updated rows: " . $st->rowCount() . "\n";
