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
$pdo = new PDO($dsn, $d['username'] ?? 'root', $d['password'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$sql = file_get_contents($root . '/sql/chat_group_msg_cleared.sql');
$pdo->exec($sql);
echo "applied chat_group_msg_cleared.sql\n";
