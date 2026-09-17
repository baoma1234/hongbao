<?php
/**
 * fa_fans_notice 增加 video_cover 封面图字段
 * 从项目根目录 .env 读库账号，各服务器通用。
 * Usage: php scripts/migrate_notice_video_cover.php
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
$st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
$st->execute(['video_cover']);
if ($st->fetch(PDO::FETCH_ASSOC)) {
    echo "OK already has video_cover on {$table}\n";
    exit(0);
}
$pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `video_cover` varchar(512) NOT NULL DEFAULT '' COMMENT '视频封面图URL' AFTER `video`");
echo "OK added video_cover on {$table}\n";
