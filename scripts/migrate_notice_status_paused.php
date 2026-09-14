<?php
/**
 * fa_fans_notice.status 增加 paused（暂停展示，数据保留，前台不显示）
 * 从项目根目录 .env 读库账号，各服务器通用。
 */
$root = dirname(__DIR__);
$envFile = $root . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, "ERROR: .env not found at {$envFile}\n");
    exit(1);
}
$env = parse_ini_file($envFile, true);
if (!is_array($env)) {
    fwrite(STDERR, "ERROR: failed to parse .env\n");
    exit(1);
}
$db = $env['database'] ?? [];
$host = (string)($db['hostname'] ?? '127.0.0.1');
$port = (string)($db['hostport'] ?? '3306');
$name = (string)($db['database'] ?? '');
$user = (string)($db['username'] ?? '');
$pass = (string)($db['password'] ?? '');
$prefix = (string)($db['prefix'] ?? 'fa_');
if ($name === '' || $user === '') {
    fwrite(STDERR, "ERROR: database.database / database.username missing in .env\n");
    exit(1);
}

$dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$table = $prefix . 'fans_notice';
$col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
if (!$col) {
    fwrite(STDERR, "ERROR: {$table}.status column missing\n");
    exit(1);
}
$type = (string)($col['Type'] ?? '');
if (stripos($type, "'paused'") !== false) {
    echo "OK already has paused: {$type}\n";
    exit(0);
}

$sql = "ALTER TABLE `{$table}` MODIFY COLUMN `status` ENUM('draft','published','paused') NOT NULL DEFAULT 'published' COMMENT 'draft草稿 published展示 paused暂停展示'";
$pdo->exec($sql);
$after = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
echo "OK migrated {$table}.status -> " . ($after['Type'] ?? '') . "\n";
