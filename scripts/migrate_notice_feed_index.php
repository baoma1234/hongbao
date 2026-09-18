<?php
/**
 * 帖子列表覆盖索引：status + weigh + publishtime + id；分类维度同理
 * php scripts/migrate_notice_feed_index.php
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
$table = 'fa_fans_notice';
$indexes = [
    'idx_feed' => 'ADD INDEX `idx_feed` (`status`, `weigh`, `publishtime`, `id`)',
    'idx_feed_cat' => 'ADD INDEX `idx_feed_cat` (`status`, `category`, `weigh`, `publishtime`, `id`)',
];
$existing = [];
foreach ($pdo->query("SHOW INDEX FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $existing[$row['Key_name']] = true;
}
foreach ($indexes as $name => $ddl) {
    if (!empty($existing[$name])) {
        echo "SKIP {$name}\n";
        continue;
    }
    $pdo->exec("ALTER TABLE `{$table}` {$ddl}");
    echo "OK   {$name}\n";
}
echo "DONE\n";
