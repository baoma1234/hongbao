<?php
/**
 * 下线「实时福利交互大厅 / 留言」：隐藏后台菜单
 * php scripts/disable_comment_hall.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};port=" . ($d['hostport'] ?? 3306) . ";dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']
);

$now = time();
$stmt = $pdo->prepare(
    "UPDATE fa_auth_rule SET status='hidden', ismenu=0, updatetime=? WHERE name LIKE 'fanshub/comment%'"
);
$stmt->execute([$now]);
echo 'hidden auth_rule rows=' . $stmt->rowCount() . PHP_EOL;
echo "done\n";
