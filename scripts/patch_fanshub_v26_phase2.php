<?php
/**
 * 福利大厅 v26：二期团长态字段 + 签到表
 * php scripts/patch_fanshub_v26_phase2.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$prefix = isset($d['prefix']) ? $d['prefix'] : 'fa_';
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']
);

define('APP_PATH', $root . '/application/');
require $root . '/thinkphp/base.php';
\think\App::initCommon();

$accountTable = $prefix . 'fans_account';
$cols = [
    'user_mode' => "ADD COLUMN `user_mode` enum('newbie','master') NOT NULL DEFAULT 'newbie' COMMENT '用户态' AFTER `member_level`",
    'fission_streak_days' => "ADD COLUMN `fission_streak_days` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '连续签到天数' AFTER `user_mode`",
    'fission_streak_qualified' => "ADD COLUMN `fission_streak_qualified` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '7天暴击资格' AFTER `fission_streak_days`",
    'fission_last_checkin_date' => "ADD COLUMN `fission_last_checkin_date` date DEFAULT NULL COMMENT '最后签到日' AFTER `fission_streak_qualified`",
    'sub_withdrawn_count' => "ADD COLUMN `sub_withdrawn_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '直属下线提现数' AFTER `fission_last_checkin_date`",
    'honor_tier_claimed' => "ADD COLUMN `honor_tier_claimed` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '已领荣誉段位' AFTER `sub_withdrawn_count`",
    'first_withdraw_done' => "ADD COLUMN `first_withdraw_done` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '已完成新手提现' AFTER `honor_tier_claimed`",
];

foreach ($cols as $name => $sql) {
    $exists = $pdo->query("SHOW COLUMNS FROM `{$accountTable}` LIKE '{$name}'")->fetch();
    if ($exists) {
        echo "SKIP  {$accountTable}.{$name}\n";
    } else {
        $pdo->exec("ALTER TABLE `{$accountTable}` {$sql}");
        echo "OK    {$accountTable}.{$name}\n";
    }
}

$checkinTable = $prefix . 'fans_checkin';
$exists = $pdo->query("SHOW TABLES LIKE '{$checkinTable}'")->fetch();
if ($exists) {
    echo "SKIP  {$checkinTable} exists\n";
} else {
    $pdo->exec("CREATE TABLE `{$checkinTable}` (
      `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
      `user_id` int(10) unsigned NOT NULL DEFAULT '0',
      `checkin_date` date NOT NULL,
      `mode` enum('normal','violent') NOT NULL DEFAULT 'normal',
      `base_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
      `bonus_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
      `bonus_unlocked` tinyint(1) unsigned NOT NULL DEFAULT '0',
      `streak_day` tinyint(3) unsigned NOT NULL DEFAULT '1',
      `day7_settled` tinyint(1) unsigned NOT NULL DEFAULT '0',
      `createtime` int(10) unsigned DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_user_date` (`user_id`,`checkin_date`),
      KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='星火签到记录'");
    echo "OK    {$checkinTable} created\n";
}

$path = APP_PATH . 'extra' . DS . 'fanshub.php';
$config = is_file($path) ? include $path : [];
if (!is_array($config)) {
    $config = [];
}
$defaults = [
    'phase2_enabled' => true,
    'checkin_base_amount' => 1,
    'checkin_violent_bonus' => 4,
    'register_bonus_rights' => 1,
    'honor_tiers' => [
        ['id' => 1, 'name' => '青铜团长', 'threshold' => 1, 'rights' => 10, 'balance' => 0],
        ['id' => 2, 'name' => '白金团长', 'threshold' => 5, 'rights' => 50, 'balance' => 100],
        ['id' => 3, 'name' => '钻石团长', 'threshold' => 10, 'rights' => 100, 'balance' => 300],
        ['id' => 4, 'name' => '最强王者', 'threshold' => 20, 'rights' => 200, 'balance' => 800],
        ['id' => 5, 'name' => '荣耀王者', 'threshold' => 50, 'rights' => 500, 'balance' => 2000],
    ],
];
$changed = false;
foreach ($defaults as $k => $v) {
    if (!array_key_exists($k, $config)) {
        $config[$k] = $v;
        $changed = true;
    }
}
if ($changed) {
    \app\common\library\FansHubService::saveFanshubConfig($config);
    echo "OK    fanshub.php phase2 keys merged\n";
} else {
    echo "SKIP  fanshub.php phase2 keys exist\n";
}

echo "DONE  phase2 schema ready\n";
