<?php
/**
 * Add notice_images to chat_groups for multi-image group announcements.
 */
$root = dirname(__DIR__);
$envFile = $root . '/.env';
if (is_file($envFile)) {
    $section = '';
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if ($line[0] === '[' && substr($line, -1) === ']') {
            $section = trim($line, '[]');
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        list($k, $v) = array_map('trim', explode('=', $line, 2));
        $v = trim($v, "\"'");
        $full = $section !== '' ? ($section . '.' . $k) : $k;
        putenv($full . '=' . $v);
        putenv($k . '=' . $v);
    }
}

$host = getenv('database.hostname') ?: (getenv('hostname') ?: '127.0.0.1');
$db = getenv('database.database') ?: (getenv('database') ?: 'fastadmin');
$user = getenv('database.username') ?: (getenv('username') ?: 'root');
$pass = getenv('database.password') ?: (getenv('password') ?: '');
$port = getenv('database.hostport') ?: (getenv('hostport') ?: '3306');
$prefix = getenv('database.prefix') ?: (getenv('prefix') ?: 'fa_');

$mysqli = @new mysqli($host, $user, $pass, $db, (int)$port);
if ($mysqli->connect_error) {
    fwrite(STDERR, 'db fail: ' . $mysqli->connect_error . PHP_EOL);
    exit(1);
}
$mysqli->set_charset('utf8mb4');
$table = $prefix . 'chat_groups';
$col = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE 'notice_images'");
if ($col && $col->num_rows > 0) {
    echo "notice_images already exists\n";
    exit(0);
}
$sql = "ALTER TABLE `{$table}` ADD COLUMN `notice_images` text NULL COMMENT '群公告配图JSON/逗号分隔' AFTER `notice_i18n`";
if (!$mysqli->query($sql)) {
    fwrite(STDERR, 'alter fail: ' . $mysqli->error . PHP_EOL);
    exit(1);
}
echo "added notice_images to {$table}\n";
