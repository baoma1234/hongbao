<?php
/**
 * 安装充值/提现相关表（兼容 MySQL 5.7）
 * php scripts/patch_fanshub_wallet_social.php
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

function tableExists(PDO $pdo, $table)
{
    $st = $pdo->prepare('SHOW TABLES LIKE ?');
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function columnExists(PDO $pdo, $table, $column)
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetchColumn();
}

function execSql(PDO $pdo, $sql, $label)
{
    try {
        $pdo->exec($sql);
        echo "OK   {$label}\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate column') !== false || stripos($msg, 'already exists') !== false) {
            echo "SKIP {$label}\n";
        } else {
            echo "FAIL {$label}: {$msg}\n";
        }
    }
}

$account = $prefix . 'fans_account';
if (tableExists($pdo, $account) && !columnExists($pdo, $account, 'turnover')) {
    execSql($pdo, "ALTER TABLE `{$account}` ADD COLUMN `turnover` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '累计流水' AFTER `balance`", 'fans_account.turnover');
} else {
    echo "SKIP fans_account.turnover\n";
}

execSql($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}fans_pay_channel` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('recharge','withdraw') NOT NULL DEFAULT 'recharge' COMMENT '通道类型',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '通道名称',
  `icon` varchar(255) NOT NULL DEFAULT '' COMMENT '图标URL',
  `tip` varchar(255) NOT NULL DEFAULT '' COMMENT '前台提示',
  `handler` varchar(64) NOT NULL DEFAULT 'manual' COMMENT '对接处理器',
  `config` text COMMENT '扩展JSON',
  `min_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `max_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '0不限',
  `weigh` int(11) NOT NULL DEFAULT '0',
  `status` enum('normal','hidden') NOT NULL DEFAULT 'normal',
  `createtime` int(10) unsigned DEFAULT NULL,
  `updatetime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type_status` (`type`,`status`,`weigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充值提现通道'", 'fans_pay_channel');

execSql($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}fans_recharge_order` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(40) NOT NULL DEFAULT '',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `channel_id` int(10) unsigned NOT NULL DEFAULT '0',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  `handler` varchar(64) NOT NULL DEFAULT '',
  `pay_info` text COMMENT '支付信息JSON',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `createtime` int(10) unsigned DEFAULT NULL,
  `updatetime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充值订单'", 'fans_recharge_order');

execSql($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}fans_withdraw_order` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(40) NOT NULL DEFAULT '',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `channel_id` int(10) unsigned NOT NULL DEFAULT '0',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `turnover_snapshot` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending','processing','paid','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `handler` varchar(64) NOT NULL DEFAULT '',
  `account_info` text COMMENT '收款信息JSON',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `createtime` int(10) unsigned DEFAULT NULL,
  `updatetime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提现订单'", 'fans_withdraw_order');

execSql($pdo, "CREATE TABLE IF NOT EXISTS `{$prefix}chat_contacts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `peer_user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1正常',
  `createtime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_peer` (`user_id`,`peer_user_id`),
  KEY `idx_peer` (`peer_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM好友'", 'chat_contacts');

$groups = $prefix . 'chat_groups';
if (tableExists($pdo, $groups)) {
    if (!columnExists($pdo, $groups, 'hide_member_list')) {
        execSql($pdo, "ALTER TABLE `{$groups}` ADD COLUMN `hide_member_list` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '1隐藏成员列表' AFTER `member_count`", 'chat_groups.hide_member_list');
    } else {
        echo "SKIP chat_groups.hide_member_list\n";
    }
    if (!columnExists($pdo, $groups, 'display_member_count')) {
        execSql($pdo, "ALTER TABLE `{$groups}` ADD COLUMN `display_member_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '展示人数0=实际' AFTER `hide_member_list`", 'chat_groups.display_member_count');
    } else {
        echo "SKIP chat_groups.display_member_count\n";
    }
}

$chk = tableExists($pdo, $prefix . 'fans_pay_channel');
echo ($chk ? "VERIFY fans_pay_channel exists\n" : "ERROR fans_pay_channel missing\n");

$payChannel = $prefix . 'fans_pay_channel';
if (tableExists($pdo, $payChannel)) {
    $merchantCols = [
        'submit_url'   => "varchar(512) NOT NULL DEFAULT '' COMMENT '提交URL'",
        'merchant_no'  => "varchar(64) NOT NULL DEFAULT '' COMMENT '商户号'",
        'merchant_key' => "varchar(255) NOT NULL DEFAULT '' COMMENT '商户密钥'",
        'pay_type'     => "varchar(32) NOT NULL DEFAULT '' COMMENT '支付类型'",
        'pay_channel'  => "varchar(64) NOT NULL DEFAULT '' COMMENT '支付通道'",
        'notify_url'   => "varchar(512) NOT NULL DEFAULT '' COMMENT '服务器通知URL'",
        'return_url'   => "varchar(512) NOT NULL DEFAULT '' COMMENT '页面跳转URL'",
        'product_name' => "varchar(128) NOT NULL DEFAULT '' COMMENT '商品名称'",
    ];
    foreach ($merchantCols as $col => $def) {
        if (!columnExists($pdo, $payChannel, $col)) {
            execSql($pdo, "ALTER TABLE `{$payChannel}` ADD COLUMN `{$col}` {$def}", "fans_pay_channel.{$col}");
        } else {
            echo "SKIP fans_pay_channel.{$col}\n";
        }
    }
}

echo "DONE\n";

// 群属性：开放群 / 隐私群 + 聊天 / 抢红包模式
$groups = $prefix . 'chat_groups';
if (tableExists($pdo, $groups)) {
    if (!columnExists($pdo, $groups, 'privacy_mode')) {
        execSql($pdo, "ALTER TABLE `{$groups}` ADD COLUMN `privacy_mode` enum('open','private') NOT NULL DEFAULT 'private' COMMENT 'open开放群 private隐私群' AFTER `display_member_count`", 'chat_groups.privacy_mode');
    } else {
        echo "SKIP chat_groups.privacy_mode\n";
    }
    if (!columnExists($pdo, $groups, 'chat_mode')) {
        execSql($pdo, "ALTER TABLE `{$groups}` ADD COLUMN `chat_mode` enum('chat','grab') NOT NULL DEFAULT 'chat' COMMENT 'chat聊天 grab抢红包' AFTER `privacy_mode`", 'chat_groups.chat_mode');
    } else {
        echo "SKIP chat_groups.chat_mode\n";
    }
    // 同步历史数据：hide_member_list=0 视为开放群
    try {
        $pdo->exec("UPDATE `{$groups}` SET privacy_mode='open', hide_member_list=0 WHERE hide_member_list=0 AND privacy_mode='private'");
        echo "OK   chat_groups sync open from hide_member_list\n";
    } catch (PDOException $e) {
        echo "SKIP chat_groups sync: {$e->getMessage()}\n";
    }
}
