<?php
/**
 * 红宝雨：增加 round_target / round_sent（抢完再发下一包）
 * php scripts/migrate_rp_rain_sequential.php
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
    'round_target' => "ALTER TABLE `{$table}` ADD COLUMN `round_target` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '本轮计划发包数' AFTER `round_packet_ids`",
    'round_sent'   => "ALTER TABLE `{$table}` ADD COLUMN `round_sent` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '本轮已发包数' AFTER `round_target`",
];
foreach ($need as $col => $sql) {
    if (in_array($col, $cols, true)) {
        echo "SKIP {$col}\n";
        continue;
    }
    $pdo->exec($sql);
    echo "ADD  {$col}\n";
}
echo "DONE sequential rain columns\n";
