<?php
/**
 * 现有社区帖子浏览量随机设为 5000–30000
 * php scripts/seed_notice_views.php
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
$table = $prefix . 'fans_notice';
$cols = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'views_count'")->fetch(PDO::FETCH_ASSOC);
if (!$cols) {
    fwrite(STDERR, "views_count column missing, run migrate_notice_community.php first\n");
    exit(1);
}
$ids = $pdo->query("SELECT id FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
$upd = $pdo->prepare("UPDATE `{$table}` SET views_count=? WHERE id=?");
$n = 0;
foreach ($ids as $id) {
    $views = random_int(5000, 30000);
    $upd->execute([$views, (int)$id]);
    $n++;
    echo "id={$id} views={$views}\n";
}
echo "DONE updated={$n}\n";
