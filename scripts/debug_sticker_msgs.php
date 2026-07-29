<?php
$root = dirname(__DIR__);
$env = @parse_ini_file($root . '/.env', true);
$d = is_array($env) ? ($env['database'] ?? []) : [];
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $d['hostname'] ?? '127.0.0.1',
    $d['hostport'] ?? 3306,
    $d['database'] ?? 'caijin_com_7111'
);
$pdo = new PDO($dsn, $d['username'] ?? 'root', $d['password'] ?? '');
$sql = "SELECT id,msg_type,content,extra,createtime FROM fa_chat_messages
        WHERE msg_type=6 OR content LIKE '%[流泪]%' OR content LIKE '%[表情%'
        ORDER BY id DESC LIMIT 15";
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
