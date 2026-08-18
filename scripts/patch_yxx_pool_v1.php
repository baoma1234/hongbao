<?php
/**
 * 鱼虾蟹大奖池 + 红包雨：表结构
 * php scripts/patch_yxx_pool_v1.php
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
$prefix = $d['prefix'] ?? 'fa_';
$now = time();

function tableExists(PDO $pdo, $table)
{
    return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
}

$state = $prefix . 'fans_yxx_pool_state';
if (!tableExists($pdo, $state)) {
    $pdo->exec("CREATE TABLE `{$state}` (
      `id` tinyint unsigned NOT NULL DEFAULT 1,
      `gross_pool` bigint unsigned NOT NULL DEFAULT 0 COMMENT '爆点蓄水池总额',
      `last_rain_at` int unsigned NOT NULL DEFAULT 0,
      `rain_day` char(8) NOT NULL DEFAULT '' COMMENT 'Ymd',
      `rain_day_count` smallint unsigned NOT NULL DEFAULT 0,
      `status` varchar(16) NOT NULL DEFAULT 'normal' COMMENT 'normal/degraded/paused/locked',
      `updatetime` int unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='鱼虾蟹全局奖池状态'");
    $pdo->exec("INSERT INTO `{$state}` (id,gross_pool,updatetime) VALUES (1,0,{$now})");
    echo "OK   table {$state}\n";
} else {
    echo "SKIP table {$state}\n";
}

$rounds = $prefix . 'fans_yxx_rounds';
if (!tableExists($pdo, $rounds)) {
    $pdo->exec("CREATE TABLE `{$rounds}` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `round_index` bigint unsigned NOT NULL,
      `settle_face` varchar(16) NOT NULL DEFAULT '',
      `human_stake` bigint unsigned NOT NULL DEFAULT 0,
      `pool_inject` bigint unsigned NOT NULL DEFAULT 0,
      `boom_release` bigint unsigned NOT NULL DEFAULT 0,
      `gross_pool_after` bigint unsigned NOT NULL DEFAULT 0,
      `cycle_count` int unsigned NOT NULL DEFAULT 0,
      `hash_seed` varchar(128) NOT NULL DEFAULT '',
      `createtime` int unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_round_index` (`round_index`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='鱼虾蟹局结算归档'");
    echo "OK   table {$rounds}\n";
} else {
    echo "SKIP table {$rounds}\n";
}

$daily = $prefix . 'fans_yxx_daily_bet';
if (!tableExists($pdo, $daily)) {
    $pdo->exec("CREATE TABLE `{$daily}` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `user_id` bigint unsigned NOT NULL,
      `bet_date` char(8) NOT NULL COMMENT 'Ymd',
      `bet_count` int unsigned NOT NULL DEFAULT 0,
      `bet_total` bigint unsigned NOT NULL DEFAULT 0,
      `updatetime` int unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_user_date` (`user_id`,`bet_date`),
      KEY `idx_date_total` (`bet_date`,`bet_total`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='鱼虾蟹日下注资格'");
    echo "OK   table {$daily}\n";
} else {
    echo "SKIP table {$daily}\n";
}

$rain = $prefix . 'fans_yxx_rain_events';
if (!tableExists($pdo, $rain)) {
    $pdo->exec("CREATE TABLE `{$rain}` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `round_index` bigint unsigned NOT NULL DEFAULT 0,
      `release_amount` bigint unsigned NOT NULL DEFAULT 0,
      `participant_count` int unsigned NOT NULL DEFAULT 0,
      `hash_seed` varchar(128) NOT NULL DEFAULT '',
      `gross_pool_before` bigint unsigned NOT NULL DEFAULT 0,
      `gross_pool_after` bigint unsigned NOT NULL DEFAULT 0,
      `status` tinyint NOT NULL DEFAULT 1 COMMENT '1已派发 2熔断',
      `createtime` int unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      KEY `idx_round` (`round_index`),
      KEY `idx_time` (`createtime`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='鱼虾蟹红包雨事件'");
    echo "OK   table {$rain}\n";
} else {
    echo "SKIP table {$rain}\n";
}

$grants = $prefix . 'fans_yxx_rain_grants';
if (!tableExists($pdo, $grants)) {
    $pdo->exec("CREATE TABLE `{$grants}` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `event_id` bigint unsigned NOT NULL,
      `user_id` bigint unsigned NOT NULL,
      `amount` bigint unsigned NOT NULL DEFAULT 0,
      `weight` bigint unsigned NOT NULL DEFAULT 0,
      `popup_seen` tinyint(1) NOT NULL DEFAULT 0,
      `createtime` int unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_event_user` (`event_id`,`user_id`),
      KEY `idx_user_seen` (`user_id`,`popup_seen`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='鱼虾蟹红包雨派发'");
    echo "OK   table {$grants}\n";
} else {
    echo "SKIP table {$grants}\n";
}

echo "DONE yxx pool v1\n";
