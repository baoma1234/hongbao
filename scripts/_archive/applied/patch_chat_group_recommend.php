<?php
/**
 * 为 chat_groups 增加推荐社群字段
 * php scripts/patch_chat_group_recommend.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

function loadEnv($file)
{
    $out = [];
    if (!is_file($file)) return $out;
    $section = '';
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === ';' || $line[0] === '#') continue;
        if ($line[0] === '[' && substr($line, -1) === ']') {
            $section = trim($line, '[]');
            continue;
        }
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        $out[$k] = $v;
        if ($section !== '') {
            $out[$section . '.' . $k] = $v;
        }
    }
    return $out;
}

$env = loadEnv(dirname(__DIR__) . '/.env');
$host = $env['database.hostname'] ?? $env['hostname'] ?? '127.0.0.1';
$dbn = $env['database.database'] ?? $env['database'] ?? 'fastadmin';
$user = $env['database.username'] ?? $env['username'] ?? 'root';
$pass = $env['database.password'] ?? $env['password'] ?? '';
$port = $env['database.hostport'] ?? $env['hostport'] ?? '3306';
$prefix = $env['database.prefix'] ?? $env['prefix'] ?? 'fa_';

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbn};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$table = $prefix . 'chat_groups';
$col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'is_recommend'")->fetch(PDO::FETCH_ASSOC);
if ($col) {
    echo "is_recommend already exists\n";
    exit(0);
}
$pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `is_recommend` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '推荐社群 1是 0否' AFTER `privacy_mode`");
echo "added is_recommend on {$table}\n";
