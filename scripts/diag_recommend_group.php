<?php
function loadEnv($f) {
    $o = []; $sec = '';
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
        if ($line[0] === '[' && substr($line, -1) === ']') { $sec = trim($line, '[]'); continue; }
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k); $v = trim($v, " \t\"'");
        $o[$k] = $v;
        if ($sec) $o[$sec . '.' . $k] = $v;
    }
    return $o;
}
$e = loadEnv(dirname(__DIR__) . '/.env');
$pdo = new PDO(
    'mysql:host=' . $e['database.hostname'] . ';port=' . ($e['database.hostport'] ?? '3306') . ';dbname=' . $e['database.database'] . ';charset=utf8mb4',
    $e['database.username'],
    $e['database.password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$row = $pdo->query('SELECT id,name,status,privacy_mode,hide_member_list,is_recommend,member_count FROM fa_chat_groups WHERE id=3')->fetch(PDO::FETCH_ASSOC);
echo "group3=\n"; print_r($row);
$col = $pdo->query("SHOW COLUMNS FROM fa_chat_groups LIKE 'is_recommend'")->fetch(PDO::FETCH_ASSOC);
echo "has_col=" . ($col ? 'yes' : 'no') . "\n";
$sql = "SELECT id,name,privacy_mode,is_recommend,status,hide_member_list FROM fa_chat_groups WHERE status IN (1,3)
 AND (privacy_mode='open' OR (IFNULL(privacy_mode,'')='' AND IFNULL(hide_member_list,1)=0))
 AND IFNULL(is_recommend,0)=1";
echo "recommend query:\n";
foreach ($pdo->query($sql) as $r) print_r($r);

// Also check what IM config DB is
$imCfg = dirname(__DIR__) . '/im-server/config/database.php';
if (is_file($imCfg)) {
    echo "im db config exists\n";
    include $imCfg;
}
$appCfg = dirname(__DIR__) . '/im-server/config/app.php';
if (is_file($appCfg)) {
    $c = include $appCfg;
    echo "im app keys: " . implode(',', array_keys((array)$c)) . "\n";
}
