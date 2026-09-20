<?php
/**
 * 红宝雨：开启时间模式（定点 / 间隔循环）
 * php scripts/migrate_rp_rain_schedule_mode.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$table = ($d['prefix'] ?? 'fa_') . 'chat_rp_rain_task';
$cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
$need = [
    'schedule_mode' => "ALTER TABLE `{$table}` ADD COLUMN `schedule_mode` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1定点时刻 2每N分钟' AFTER `time_slots`",
    'interval_minutes' => "ALTER TABLE `{$table}` ADD COLUMN `interval_minutes` int(10) unsigned NOT NULL DEFAULT '5' COMMENT '模式2:每隔多少分钟' AFTER `schedule_mode`",
    'interval_count' => "ALTER TABLE `{$table}` ADD COLUMN `interval_count` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '模式2:每轮发包数' AFTER `interval_minutes`",
];
foreach ($need as $col => $sql) {
    if (in_array($col, $cols, true)) {
        echo "SKIP {$col}\n";
        continue;
    }
    $pdo->exec($sql);
    echo "ADD  {$col}\n";
}
// 最近轮次可能含「手动/间隔」中文，扩到 64
$row = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'last_slot_key'")->fetch(PDO::FETCH_ASSOC);
$type = strtolower((string)($row['Type'] ?? ''));
if (strpos($type, 'varchar(32)') !== false) {
    $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `last_slot_key` varchar(64) NOT NULL DEFAULT '' COMMENT '已触发轮次标记'");
    echo "MOD  last_slot_key varchar(64)\n";
} else {
    echo "SKIP last_slot_key ({$type})\n";
}
echo "DONE schedule mode columns\n";
