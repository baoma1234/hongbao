<?php
/**
 * 裂变红包 V1：活动表 + 资格表，并种子一条进行中任务（奖金池1000 / 100份 / 72h / 单人5）
 * 用法: php scripts/patch_fans_fission_v1.php
 */
$root = dirname(__DIR__);
$ini = parse_ini_file($root . '/.env', true);
if (empty($ini['database'])) {
    fwrite(STDERR, "missing .env database\n");
    exit(1);
}
$d = $ini['database'];
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $d['hostname'],
        (int)($d['hostport'] ?? 3306),
        $d['database']
    ),
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pre = $d['prefix'] ?? 'fa_';

$act = $pre . 'fans_fission_activity';
$qual = $pre . 'fans_fission_qual';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `{$act}` (
      `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
      `title` varchar(64) NOT NULL DEFAULT '全网裂变红包',
      `pool_amount` decimal(12,2) NOT NULL DEFAULT '1000.00' COMMENT '奖金池(元)',
      `global_cap` int(10) unsigned NOT NULL DEFAULT 100 COMMENT '全局资格上限',
      `user_cap` int(10) unsigned NOT NULL DEFAULT 5 COMMENT '单人资格上限',
      `duration_hours` int(10) unsigned NOT NULL DEFAULT 72,
      `global_quals` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '已发放全局资格份数',
      `status` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT '0草稿 1进行中 2开奖成功 3超时作废',
      `start_time` int(10) unsigned NOT NULL DEFAULT 0,
      `end_time` int(10) unsigned NOT NULL DEFAULT 0,
      `settled_time` int(10) unsigned NOT NULL DEFAULT 0,
      `createtime` int(10) unsigned NOT NULL DEFAULT 0,
      `updatetime` int(10) unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      KEY `idx_status_end` (`status`,`end_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='裂变红包活动'"
);
echo "OK table {$act}\n";

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `{$qual}` (
      `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
      `activity_id` int(10) unsigned NOT NULL DEFAULT 0,
      `user_id` int(10) unsigned NOT NULL DEFAULT 0,
      `source` varchar(32) NOT NULL DEFAULT '' COMMENT 'join|invite_reward|invitee',
      `ref_user_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '关联对方UID',
      `win_amount` decimal(12,2) DEFAULT NULL COMMENT '开奖金额，未开奖为NULL',
      `createtime` int(10) unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      KEY `idx_act_user` (`activity_id`,`user_id`),
      KEY `idx_act` (`activity_id`),
      KEY `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='裂变红包资格份'"
);
echo "OK table {$qual}\n";

$running = (int)$pdo->query("SELECT COUNT(*) FROM `{$act}` WHERE `status`=1")->fetchColumn();
if ($running > 0) {
    echo "skip seed: already have running activity\n";
    exit(0);
}

$now = time();
$hours = 72;
$st = $pdo->prepare(
    "INSERT INTO `{$act}` (`title`,`pool_amount`,`global_cap`,`user_cap`,`duration_hours`,`global_quals`,`status`,`start_time`,`end_time`,`settled_time`,`createtime`,`updatetime`)
     VALUES (?,?,?,?,?,0,1,?,?,0,?,?)"
);
$st->execute(['全网裂变红包', '1000.00', 100, 5, $hours, $now, $now + $hours * 3600, $now, $now]);
echo "OK seeded activity id=" . $pdo->lastInsertId() . " end=" . date('Y-m-d H:i:s', $now + $hours * 3600) . "\n";
