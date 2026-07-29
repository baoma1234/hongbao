<?php
/**
 * 打印 IM 实际加载的数据库用户（不输出密码）
 * php im-server/scripts/check_db_config.php
 */
$cfg = require dirname(__DIR__) . '/config/app.php';
$db = $cfg['db'];
echo 'env_file_expected=' . dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env' . PHP_EOL;
echo 'db.host=' . $db['host'] . PHP_EOL;
echo 'db.database=' . $db['database'] . PHP_EOL;
echo 'db.username=' . $db['username'] . PHP_EOL;
echo 'db.password_set=' . ($db['password'] !== '' ? 'yes' : 'no') . PHP_EOL;
echo 'db.prefix=' . $db['prefix'] . PHP_EOL;
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']);
    $pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $n = (int)$pdo->query('SELECT COUNT(*) FROM `' . $db['prefix'] . 'user_token`')->fetchColumn();
    echo "db.connect=ok user_token_rows={$n}\n";
} catch (Throwable $e) {
    echo 'db.connect=FAIL ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
