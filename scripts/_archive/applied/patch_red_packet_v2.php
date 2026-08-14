<?php
/**
 * 红包 v2：埋雷/手气赔付、抽水返佣字段、皮肤与群规则
 * php scripts/patch_red_packet_v2.php
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

function columnExists(PDO $pdo, $table, $column)
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetchColumn();
}

function indexExists(PDO $pdo, $table, $index)
{
    $st = $pdo->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name=?");
    $st->execute([$index]);
    return (bool)$st->fetchColumn();
}

function addColumn(PDO $pdo, $table, $column, $ddl)
{
    if (columnExists($pdo, $table, $column)) {
        echo "SKIP {$table}.{$column}\n";
        return;
    }
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
        echo "OK   {$table}.{$column}\n";
    } catch (PDOException $e) {
        echo "FAIL {$table}.{$column}: " . $e->getMessage() . "\n";
    }
}

function addIndex(PDO $pdo, $table, $name, $ddl)
{
    if (indexExists($pdo, $table, $name)) {
        echo "SKIP idx {$table}.{$name}\n";
        return;
    }
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD {$ddl}");
        echo "OK   idx {$table}.{$name}\n";
    } catch (PDOException $e) {
        echo "FAIL idx {$table}.{$name}: " . $e->getMessage() . "\n";
    }
}

$packets = $prefix . 'chat_red_packets';
$records = $prefix . 'chat_red_packet_records';
$groups = $prefix . 'chat_groups';
$ledger = $prefix . 'fans_ledger';

// A. packets
try {
    $pdo->exec("ALTER TABLE `{$packets}` MODIFY COLUMN `packet_type` tinyint(3) unsigned NOT NULL DEFAULT '2' COMMENT '1普通均分 2手气包 3埋雷包'");
    echo "OK   {$packets}.packet_type comment\n";
} catch (PDOException $e) {
    echo "SKIP {$packets}.packet_type modify: " . $e->getMessage() . "\n";
}
try {
    $pdo->exec("ALTER TABLE `{$packets}` MODIFY COLUMN `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1可抢 2已抢完 3已过期 4已关闭 5已结算'");
    echo "OK   {$packets}.status comment\n";
} catch (PDOException $e) {
    echo "SKIP {$packets}.status modify\n";
}

addColumn($pdo, $packets, 'mine_digit', "`mine_digit` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '埋雷尾数0-9' AFTER `packet_type`");
addColumn($pdo, $packets, 'skin_id', "`skin_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '红包背景皮肤ID' AFTER `blessing`");
addColumn($pdo, $packets, 'bg_image', "`bg_image` varchar(255) NOT NULL DEFAULT '' COMMENT '背景图URL快照' AFTER `skin_id`");
addColumn($pdo, $packets, 'platform_fee_rate', "`platform_fee_rate` decimal(5,4) unsigned NOT NULL DEFAULT '0.0300' COMMENT '平台抽水比例' AFTER `total_count`");
addColumn($pdo, $packets, 'platform_fee', "`platform_fee` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '平台抽水金额' AFTER `platform_fee_rate`");
addColumn($pdo, $packets, 'pool_amount', "`pool_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '可抢金额池' AFTER `platform_fee`");
addColumn($pdo, $packets, 'sender_pay_amount', "`sender_pay_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '发包人实扣' AFTER `pool_amount`");
addColumn($pdo, $packets, 'agent_user_id', "`agent_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '代理用户ID' AFTER `from_user_id`");
addColumn($pdo, $packets, 'agent_rebate_rate', "`agent_rebate_rate` decimal(5,4) unsigned NOT NULL DEFAULT '0.0000' COMMENT '代理返佣比例' AFTER `agent_user_id`");
addColumn($pdo, $packets, 'agent_rebate_amount', "`agent_rebate_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '代理返佣金额' AFTER `agent_rebate_rate`");
addColumn($pdo, $packets, 'compensate_amount', "`compensate_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '赔付总额' AFTER `agent_rebate_amount`");
addColumn($pdo, $packets, 'compensate_user_id', "`compensate_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '赔付人' AFTER `compensate_amount`");
addColumn($pdo, $packets, 'compensate_status', "`compensate_status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '0无 1待赔 2已赔 3失败' AFTER `compensate_user_id`");
addColumn($pdo, $packets, 'compensate_time', "`compensate_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '赔付时间' AFTER `compensate_status`");
addColumn($pdo, $packets, 'expire_status', "`expire_status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '0未过期 1已过期' AFTER `expiretime`");
addColumn($pdo, $packets, 'settled_time', "`settled_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '结算完成时间' AFTER `finished_time`");
addIndex($pdo, $packets, 'idx_type_status', 'KEY `idx_type_status` (`packet_type`,`status`)');
addIndex($pdo, $packets, 'idx_agent', 'KEY `idx_agent` (`agent_user_id`,`id`)');
addIndex($pdo, $packets, 'idx_compensate', 'KEY `idx_compensate` (`compensate_status`,`id`)');

// backfill pool/sender for old rows
try {
    $pdo->exec("UPDATE `{$packets}` SET pool_amount=total_amount, sender_pay_amount=total_amount WHERE pool_amount=0 AND total_amount>0");
    echo "OK   backfill pool_amount\n";
} catch (PDOException $e) {
    echo "SKIP backfill: " . $e->getMessage() . "\n";
}

// B. records
addColumn($pdo, $records, 'amount_cent', "`amount_cent` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '金额分' AFTER `amount`");
addColumn($pdo, $records, 'tail_digit', "`tail_digit` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '金额分尾数0-9' AFTER `amount_cent`");
addColumn($pdo, $records, 'is_worst', "`is_worst` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '手气最差' AFTER `is_best`");
addColumn($pdo, $records, 'is_mine_hit', "`is_mine_hit` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '是否中雷' AFTER `is_worst`");
addColumn($pdo, $records, 'need_compensate', "`need_compensate` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '需赔付' AFTER `is_mine_hit`");
addColumn($pdo, $records, 'compensate_amount', "`compensate_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '应赔金额' AFTER `need_compensate`");
addColumn($pdo, $records, 'compensate_status', "`compensate_status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '0无 1待 2已 3失败' AFTER `compensate_amount`");
addColumn($pdo, $records, 'compensate_ledger_id', "`compensate_ledger_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '赔付流水ID' AFTER `compensate_status`");
addIndex($pdo, $records, 'idx_mine_hit', 'KEY `idx_mine_hit` (`packet_id`,`is_mine_hit`)');
addIndex($pdo, $records, 'idx_worst', 'KEY `idx_worst` (`packet_id`,`is_worst`)');
try {
    $pdo->exec("UPDATE `{$records}` SET amount_cent=ROUND(amount*100), tail_digit=MOD(ROUND(amount*100),10) WHERE amount_cent=0 AND amount>0");
    echo "OK   backfill amount_cent\n";
} catch (PDOException $e) {
    echo "SKIP amount_cent backfill\n";
}

// C. skins
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}chat_red_packet_skins` (
      `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
      `name` varchar(64) NOT NULL DEFAULT '',
      `packet_type` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '0通用 2手气 3埋雷',
      `image` varchar(255) NOT NULL DEFAULT '' COMMENT '封面750x1000',
      `thumb` varchar(255) NOT NULL DEFAULT '' COMMENT '缩略300x400',
      `width` int(10) unsigned NOT NULL DEFAULT '750',
      `height` int(10) unsigned NOT NULL DEFAULT '1000',
      `weigh` int(11) NOT NULL DEFAULT '0',
      `status` enum('normal','hidden') NOT NULL DEFAULT 'normal',
      `createtime` int(10) unsigned NOT NULL DEFAULT '0',
      `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
      PRIMARY KEY (`id`),
      KEY `idx_type_status` (`packet_type`,`status`,`weigh`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='红包背景皮肤'");
    echo "OK   chat_red_packet_skins\n";
} catch (PDOException $e) {
    echo "FAIL skins: " . $e->getMessage() . "\n";
}

// D. groups
$after = columnExists($pdo, $groups, 'chat_mode') ? 'chat_mode' : 'status';
addColumn($pdo, $groups, 'is_vip_group', "`is_vip_group` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT 'VIP红包群' AFTER `{$after}`");
addColumn($pdo, $groups, 'rp_min_amount', "`rp_min_amount` decimal(12,2) unsigned NOT NULL DEFAULT '10.00' COMMENT '本群红包最低金额' AFTER `is_vip_group`");
addColumn($pdo, $groups, 'rp_min_count', "`rp_min_count` int(10) unsigned NOT NULL DEFAULT '5' COMMENT '最少个数' AFTER `rp_min_amount`");
addColumn($pdo, $groups, 'rp_max_count', "`rp_max_count` int(10) unsigned NOT NULL DEFAULT '10' COMMENT '最多个数' AFTER `rp_min_count`");
addColumn($pdo, $groups, 'rp_agent_rebate_rate', "`rp_agent_rebate_rate` decimal(5,4) unsigned NOT NULL DEFAULT '0.0100' COMMENT '代理返佣比例' AFTER `rp_max_count`");
addColumn($pdo, $groups, 'rp_enabled_types', "`rp_enabled_types` varchar(32) NOT NULL DEFAULT '2,3' COMMENT '允许类型' AFTER `rp_agent_rebate_rate`");

// E. config + settlements
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}chat_red_packet_config` (
      `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
      `cfg_key` varchar(64) NOT NULL DEFAULT '',
      `cfg_value` varchar(255) NOT NULL DEFAULT '',
      `remark` varchar(255) NOT NULL DEFAULT '',
      `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_key` (`cfg_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='红包全局配置'");
    echo "OK   chat_red_packet_config\n";
} catch (PDOException $e) {
    echo "FAIL config: " . $e->getMessage() . "\n";
}

$now = time();
$defaults = [
    ['min_amount', '10.00', '普通群最低金额'],
    ['min_count', '5', '普通群最少个数'],
    ['max_count', '10', '普通群最多个数'],
    ['vip_min_count', '5', 'VIP群最少个数'],
    ['vip_max_count', '10', 'VIP群最多个数'],
    ['platform_fee_rate', '0.0300', '平台抽水3%'],
    ['agent_rebate_rate_default', '0.0100', '代理默认返佣1%'],
    ['agent_rebate_rate_vip', '0.0100', 'VIP群返佣1%'],
    ['skin_width', '750', '背景图宽'],
    ['skin_height', '1000', '背景图高'],
];
$st = $pdo->prepare("INSERT INTO `{$prefix}chat_red_packet_config` (cfg_key,cfg_value,remark,updatetime) VALUES (?,?,?,?)
    ON DUPLICATE KEY UPDATE cfg_value=VALUES(cfg_value), remark=VALUES(remark), updatetime=VALUES(updatetime)");
foreach ($defaults as $row) {
    $st->execute([$row[0], $row[1], $row[2], $now]);
}
echo "OK   config seeds\n";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}chat_red_packet_settlements` (
      `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      `packet_id` bigint(20) unsigned NOT NULL DEFAULT '0',
      `packet_no` varchar(40) NOT NULL DEFAULT '',
      `settle_type` varchar(32) NOT NULL DEFAULT '' COMMENT 'platform_fee|agent_rebate|compensate|refund',
      `from_user_id` int(10) unsigned NOT NULL DEFAULT '0',
      `to_user_id` int(10) unsigned NOT NULL DEFAULT '0',
      `amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
      `ledger_id` int(10) unsigned NOT NULL DEFAULT '0',
      `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1成功 2失败',
      `remark` varchar(255) NOT NULL DEFAULT '',
      `createtime` int(10) unsigned NOT NULL DEFAULT '0',
      PRIMARY KEY (`id`),
      KEY `idx_packet` (`packet_id`,`settle_type`),
      KEY `idx_packet_no` (`packet_no`),
      KEY `idx_to_user` (`to_user_id`,`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='红包结算明细'");
    echo "OK   chat_red_packet_settlements\n";
} catch (PDOException $e) {
    echo "FAIL settlements: " . $e->getMessage() . "\n";
}

// F. ledger
addColumn($pdo, $ledger, 'biz_no', "`biz_no` varchar(40) NOT NULL DEFAULT '' COMMENT '业务单号' AFTER `channel`");
addColumn($pdo, $ledger, 'ref_type', "`ref_type` varchar(32) NOT NULL DEFAULT '' COMMENT '关联类型' AFTER `biz_no`");
addColumn($pdo, $ledger, 'ref_id', "`ref_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '关联ID' AFTER `ref_type`");
addIndex($pdo, $ledger, 'idx_biz_no', 'KEY `idx_biz_no` (`biz_no`)');
addIndex($pdo, $ledger, 'idx_ref', 'KEY `idx_ref` (`ref_type`,`ref_id`)');
addIndex($pdo, $ledger, 'idx_user_type_time', 'KEY `idx_user_type_time` (`user_id`,`type`,`createtime`)');

echo "DONE\n";
