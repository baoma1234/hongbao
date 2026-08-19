<?php
/**
 * 红宝尾数牛牛 V1：表结构 + 群字段 + 全局配置 + 后台菜单
 * php scripts/patch_niuniu_v1.php
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

function colExists(PDO $pdo, $table, $col)
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch();
}

function ensureCol(PDO $pdo, $table, $col, $ddl)
{
    if (colExists($pdo, $table, $col)) {
        echo "SKIP col {$table}.{$col}\n";
        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
    echo "OK   col {$table}.{$col}\n";
}

$g = $prefix . 'chat_groups';
ensureCol($pdo, $g, 'niuniu_enabled', "`niuniu_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '尾数牛牛开关' AFTER `rp_robot_only`");
ensureCol($pdo, $g, 'niuniu_desc', "`niuniu_desc` varchar(500) NOT NULL DEFAULT '' COMMENT '尾数牛牛群内说明' AFTER `niuniu_enabled`");

$rounds = $prefix . 'chat_niuniu_rounds';
$exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($rounds))->fetchColumn();
if (!$exists) {
    $pdo->exec("CREATE TABLE `{$rounds}` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `group_id` bigint unsigned NOT NULL,
      `starter_user_id` bigint unsigned NOT NULL DEFAULT 0,
      `status` tinyint NOT NULL DEFAULT 1 COMMENT '1购入中 2领取中 3已结算 4作废 5流局退回',
      `share_price` decimal(12,2) NOT NULL DEFAULT 100.00,
      `buy_seconds` int NOT NULL DEFAULT 120,
      `claim_seconds` int NOT NULL DEFAULT 60,
      `buy_end_at` int unsigned NOT NULL DEFAULT 0,
      `claim_end_at` int unsigned NOT NULL DEFAULT 0,
      `settle_at` int unsigned NOT NULL DEFAULT 0,
      `share_count` int NOT NULL DEFAULT 0,
      `pool_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
      `fee_rate` decimal(8,4) NOT NULL DEFAULT 0.0300,
      `fee_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
      `distributable` decimal(14,2) NOT NULL DEFAULT 0.00,
      `niuniu_rate` decimal(8,4) NOT NULL DEFAULT 0.6000,
      `secondary_rate` decimal(8,4) NOT NULL DEFAULT 0.4000,
      `niuniu_pool` decimal(14,2) NOT NULL DEFAULT 0.00,
      `secondary_pool` decimal(14,2) NOT NULL DEFAULT 0.00,
      `niuniu_per_share` decimal(14,4) NOT NULL DEFAULT 0.0000,
      `secondary_per_share` decimal(14,4) NOT NULL DEFAULT 0.0000,
      `niuniu_share_count` int NOT NULL DEFAULT 0,
      `secondary_share_count` int NOT NULL DEFAULT 0,
      `low_share_count` int NOT NULL DEFAULT 0,
      `settle_case` tinyint NOT NULL DEFAULT 0 COMMENT '1双组 2无牛牛 3无次级 4全低流局',
      `drand_round` bigint unsigned NOT NULL DEFAULT 0,
      `drand_randomness` varchar(128) NOT NULL DEFAULT '',
      `drand_url` varchar(255) NOT NULL DEFAULT '',
      `platform_user_id` bigint unsigned NOT NULL DEFAULT 0,
      `buy_msg_id` bigint unsigned NOT NULL DEFAULT 0,
      `claim_msg_id` bigint unsigned NOT NULL DEFAULT 0,
      `result_msg_id` bigint unsigned NOT NULL DEFAULT 0,
      `createtime` int unsigned NOT NULL DEFAULT 0,
      `updatetime` int unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      KEY `idx_group_status` (`group_id`,`status`),
      KEY `idx_buy_end` (`status`,`buy_end_at`),
      KEY `idx_claim_end` (`status`,`claim_end_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='红宝尾数牛牛对局'");
    echo "OK   table {$rounds}\n";
} else {
    echo "SKIP table {$rounds}\n";
}

$shares = $prefix . 'chat_niuniu_shares';
$exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($shares))->fetchColumn();
if (!$exists) {
    $pdo->exec("CREATE TABLE `{$shares}` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `round_id` bigint unsigned NOT NULL,
      `group_id` bigint unsigned NOT NULL,
      `user_id` bigint unsigned NOT NULL,
      `share_no` int NOT NULL DEFAULT 0,
      `amount` decimal(12,2) NOT NULL DEFAULT 100.00,
      `tail_digits` char(2) DEFAULT NULL COMMENT '00-99 购入结束后生成',
      `digit_a` tinyint DEFAULT NULL,
      `digit_b` tinyint DEFAULT NULL,
      `digit_sum` tinyint DEFAULT NULL,
      `niu_point` tinyint DEFAULT NULL COMMENT '个位 0=牛牛',
      `niu_tier` tinyint NOT NULL DEFAULT 0 COMMENT '3牛牛 2次级7-9 1低1-6',
      `niu_label` varchar(16) NOT NULL DEFAULT '',
      `claimed` tinyint(1) NOT NULL DEFAULT 0,
      `claimed_at` int unsigned NOT NULL DEFAULT 0,
      `win_amount` decimal(14,4) NOT NULL DEFAULT 0.0000,
      `paid` tinyint(1) NOT NULL DEFAULT 0,
      `createtime` int unsigned NOT NULL DEFAULT 0,
      `updatetime` int unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      KEY `idx_round_user` (`round_id`,`user_id`),
      KEY `idx_round_tier` (`round_id`,`niu_tier`),
      KEY `idx_group` (`group_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='红宝尾数牛牛份数'");
    echo "OK   table {$shares}\n";
} else {
    echo "SKIP table {$shares}\n";
}

// 全局配置键（复用红包配置表，独立后台页管理）
$cfgTable = $prefix . 'chat_red_packet_config';
$defaults = [
    'niuniu_share_price'      => ['100.00', '每份购入积分'],
    'niuniu_buy_seconds'      => ['120', '购入倒计时秒数'],
    'niuniu_claim_seconds'    => ['60', '购入结束后领取/结算等待秒数'],
    'niuniu_fee_rate'         => ['0.0300', '平台手续费比例'],
    'niuniu_pool_rate'        => ['0.6000', '牛牛组奖金占比'],
    'niuniu_secondary_rate'   => ['0.4000', '次级牛7-9奖金占比'],
    'niuniu_platform_user_id' => ['56960815', '手续费入账用户ID'],
    'niuniu_drand_api'        => ['https://api.drand.sh', 'drand API 根地址'],
    'niuniu_drand_period'     => ['30', 'drand 出块周期秒'],
    'niuniu_enabled_global'   => ['1', '全局总开关 1开0关'],
    'niuniu_rule_text'        => [
        "1. 参与得1份资格，消耗100积分进入奖池，单人份数不限\n2. 购入结束后手动点击领取红包才展示尾数牛数；红包仅用于比对，金额不入账\n3. 牛牛瓜分可分配池60%，牛7-9瓜分40%；牛1-6无奖金\n4. 无牛牛时60%并入次级；无次级时40%并入牛牛；全低流局扣费后退回\n5. 倒计时结束0份则作废，不扣手续费",
        '默认规则说明（群可覆盖）',
    ],
];
$ins = $pdo->prepare("INSERT INTO `{$cfgTable}` (cfg_key,cfg_value,remark,updatetime) VALUES (?,?,?,?)");
$chk = $pdo->prepare("SELECT id FROM `{$cfgTable}` WHERE cfg_key=? LIMIT 1");
foreach ($defaults as $k => $pair) {
    $chk->execute([$k]);
    if ($chk->fetchColumn()) {
        echo "SKIP cfg {$k}\n";
        continue;
    }
    $ins->execute([$k, $pair[0], $pair[1], $now]);
    echo "OK   cfg {$k}\n";
}

// 菜单
$rule = $prefix . 'auth_rule';
$insert = $pdo->prepare(
    "INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
);
function ensureMenu(PDO $pdo, $insert, $rule, $pid, $name, $title, $icon, $ismenu, $weigh, $now, $remark = '')
{
    $id = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
    if ($id) {
        echo "SKIP menu {$name}\n";
        return (int)$id;
    }
    $insert->execute([
        'file', (int)$pid, $name, $title, $icon, '', '', $remark,
        (int)$ismenu, null, $now, $now, (int)$weigh, 'normal',
    ]);
    echo "OK   menu {$name}\n";
    return (int)$pdo->lastInsertId();
}

$playId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_play' LIMIT 1")->fetchColumn();
$imId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_im' LIMIT 1")->fetchColumn();
$parentId = $playId ?: $imId ?: (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if ($parentId > 0) {
    $cfgId = ensureMenu($pdo, $insert, $rule, $parentId, 'fanshub/niuniuconfig', '红包尾数牛牛配置', 'fa fa-bullseye', 1, 68, $now, '单独全局配置');
    ensureMenu($pdo, $insert, $rule, $cfgId, 'fanshub/niuniuconfig/index', '查看/保存', 'fa fa-circle-o', 0, 0, $now);
    $listId = ensureMenu($pdo, $insert, $rule, $parentId, 'fanshub/niuniu', '尾数牛牛对局', 'fa fa-list', 1, 67, $now, '对局列表');
    foreach (['index' => '查看', 'detail' => '详情'] as $act => $title) {
        ensureMenu($pdo, $insert, $rule, $listId, 'fanshub/niuniu/' . $act, $title, 'fa fa-circle-o', 0, 0, $now);
    }
}

echo "DONE niuniu v1\n";
