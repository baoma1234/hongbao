<?php
/**
 * 后台慢查询相关索引（结算/抢包记录/用户活跃/管理日志）
 * php scripts/migrate_admin_perf_indexes.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$indexes = [
    'fa_chat_red_packet_settlements' => [
        'idx_status_type' => '(`status`, `settle_type`)',
        'idx_status_id'   => '(`status`, `id`)',
    ],
    'fa_chat_red_packet_records' => [
        'idx_createtime_id' => '(`createtime`, `id`)',
    ],
    'fa_user' => [
        'idx_jointime'  => '(`jointime`)',
        'idx_logintime' => '(`logintime`)',
        'idx_prevtime'  => '(`prevtime`)',
    ],
    'fa_admin_log' => [
        'idx_createtime'   => '(`createtime`)',
        'idx_admin_time'   => '(`admin_id`, `createtime`)',
    ],
    'fa_attachment' => [
        'idx_mimetype' => '(`mimetype`(32))',
    ],
    'fa_fans_account' => [
        'idx_bot_createtime' => '(`is_bot`, `createtime`)',
    ],
];

foreach ($indexes as $table => $list) {
    $existing = [];
    foreach ($pdo->query("SHOW INDEX FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[$row['Key_name']] = true;
    }
    foreach ($list as $name => $cols) {
        if (!empty($existing[$name])) {
            echo "SKIP {$table}.{$name}\n";
            continue;
        }
        $t0 = microtime(true);
        echo "ADD  {$table}.{$name} ... ";
        flush();
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$cols}");
        echo 'ok ' . round((microtime(true) - $t0), 1) . "s\n";
    }
}
echo "DONE\n";
