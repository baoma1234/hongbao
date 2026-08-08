<?php
/**
 * 尾数牛牛：连开开关 + 自动机器人任务表 + 菜单
 * php scripts/patch_niuniu_auto_v1.php
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
ensureCol($pdo, $g, 'niuniu_loop', "`niuniu_loop` tinyint(1) NOT NULL DEFAULT 0 COMMENT '尾数牛牛连开中' AFTER `niuniu_desc`");
ensureCol($pdo, $g, 'niuniu_loop_starter', "`niuniu_loop_starter` bigint unsigned NOT NULL DEFAULT 0 COMMENT '连开发起人' AFTER `niuniu_loop`");

$task = $prefix . 'chat_niuniu_auto_task';
$exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($task))->fetchColumn();
if (!$exists) {
    $pdo->exec("CREATE TABLE `{$task}` (
      `id` int unsigned NOT NULL AUTO_INCREMENT,
      `name` varchar(64) NOT NULL DEFAULT '',
      `group_id` bigint unsigned NOT NULL DEFAULT 0,
      `actor_mode` tinyint unsigned NOT NULL DEFAULT 2 COMMENT '1=UID池 2=is_bot账户',
      `buy_user_ids` varchar(512) NOT NULL DEFAULT '' COMMENT '购入UID池,逗号',
      `buyer_count_min` int unsigned NOT NULL DEFAULT 3 COMMENT '每局随机购入人数下限',
      `buyer_count_max` int unsigned NOT NULL DEFAULT 8 COMMENT '每局随机购入人数上限',
      `shares_min` int unsigned NOT NULL DEFAULT 1 COMMENT '每人随机份数下限',
      `shares_max` int unsigned NOT NULL DEFAULT 3 COMMENT '每人随机份数上限',
      `buy_delay_min_ms` int unsigned NOT NULL DEFAULT 800,
      `buy_delay_max_ms` int unsigned NOT NULL DEFAULT 8000,
      `claim_delay_min_ms` int unsigned NOT NULL DEFAULT 500,
      `claim_delay_max_ms` int unsigned NOT NULL DEFAULT 5000,
      `auto_buy` tinyint(1) NOT NULL DEFAULT 1,
      `auto_claim` tinyint(1) NOT NULL DEFAULT 1,
      `last_round_id` bigint unsigned NOT NULL DEFAULT 0,
      `last_error` varchar(255) NOT NULL DEFAULT '',
      `status` enum('normal','hidden') NOT NULL DEFAULT 'hidden',
      `remark` varchar(255) NOT NULL DEFAULT '',
      `createtime` int unsigned DEFAULT NULL,
      `updatetime` int unsigned DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_status_group` (`status`,`group_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='尾数牛牛自动购入/领取'");
    echo "OK   table {$task}\n";
} else {
    echo "SKIP table {$task}\n";
}

$cfgTable = $prefix . 'chat_red_packet_config';
$ins = $pdo->prepare("INSERT INTO `{$cfgTable}` (cfg_key,cfg_value,remark,updatetime) VALUES (?,?,?,?)");
$chk = $pdo->prepare("SELECT id FROM `{$cfgTable}` WHERE cfg_key=? LIMIT 1");
foreach ([
    'niuniu_loop_gap_sec' => ['5', '连开局间间隔秒'],
] as $k => $pair) {
    $chk->execute([$k]);
    if ($chk->fetchColumn()) {
        echo "SKIP cfg {$k}\n";
        continue;
    }
    $ins->execute([$k, $pair[0], $pair[1], $now]);
    echo "OK   cfg {$k}\n";
}

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

$imId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_im' LIMIT 1")->fetchColumn();
$parentId = $imId ?: (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if ($parentId > 0) {
    $id = ensureMenu($pdo, $insert, $rule, $parentId, 'fanshub/niuniuauto', '尾数牛牛自动任务', 'fa fa-android', 1, 66, $now, '随机人数购入/自动领取');
    foreach ([
        'index' => '查看',
        'add' => '添加',
        'edit' => '编辑',
        'del' => '删除',
        'multi' => '批量',
        'runonce' => '立即执行',
        'restartim' => '重启聊天服务',
    ] as $act => $title) {
        ensureMenu($pdo, $insert, $rule, $id, 'fanshub/niuniuauto/' . $act, $title, 'fa fa-circle-o', 0, 0, $now);
    }
}

echo "DONE niuniu auto v1\n";
