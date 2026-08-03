<?php
/**
 * One-shot: set default CS (88888888) friend_reply welcome text.
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

$reply = "您好，欢迎来到红宝！\n我是红宝官方客服，将竭诚为您服务。\n如您在使用过程中需要任何帮助，请随时联系我，我会及时为您解答与处理。";
$now = time();
$table = $prefix . 'chat_agent_accounts';
$stmt = $mysqli->prepare("UPDATE {$table} SET friend_reply=?, updatetime=? WHERE user_id=88888888");
$stmt->bind_param('si', $reply, $now);
$stmt->execute();
echo 'agent affected=' . $stmt->affected_rows . PHP_EOL;

$r = $mysqli->query("SELECT user_id, friend_reply FROM {$table} WHERE user_id=88888888");
while ($row = $r->fetch_assoc()) {
    echo 'user_id=' . $row['user_id'] . PHP_EOL;
    echo $row['friend_reply'] . PHP_EOL;
}

echo "enabled agents:\n";
$agents = $mysqli->query("SELECT user_id, label, status FROM {$table} WHERE status=1 ORDER BY id");
while ($row = $agents->fetch_assoc()) {
    echo $row['user_id'] . "\t" . $row['label'] . PHP_EOL;
}
