<?php
/**
 * 极光推送：设备表、日志表、群 notify_mute、后台菜单
 * php scripts/install_jpush.php
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

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}chat_push_devices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `registration_id` varchar(128) NOT NULL DEFAULT '' COMMENT '极光 Registration ID',
  `platform` varchar(16) NOT NULL DEFAULT '' COMMENT 'ios|android',
  `enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '用户推送开关',
  `last_login_time` int(10) unsigned NOT NULL DEFAULT '0',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rid` (`registration_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_user_enabled` (`user_id`,`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='极光推送设备'");
echo "OK chat_push_devices\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}chat_push_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '0=系统/IM',
  `channel` varchar(32) NOT NULL DEFAULT 'jpush' COMMENT 'jpush|local',
  `scene` varchar(64) NOT NULL DEFAULT '' COMMENT 'im_msg|admin_single|admin_batch',
  `title` varchar(128) NOT NULL DEFAULT '',
  `content` varchar(512) NOT NULL DEFAULT '',
  `target_type` varchar(32) NOT NULL DEFAULT '' COMMENT 'user|users|all|registration',
  `target_ids` text COMMENT '目标UID/RID JSON',
  `platform` varchar(16) NOT NULL DEFAULT 'all' COMMENT 'all|ios|android',
  `msg_id` varchar(64) NOT NULL DEFAULT '' COMMENT '极光返回msg_id',
  `status` varchar(16) NOT NULL DEFAULT 'ok' COMMENT 'ok|fail',
  `result` text COMMENT '响应原文',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_admin_time` (`admin_id`,`createtime`),
  KEY `idx_scene_time` (`scene`,`createtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='推送日志'");
echo "OK chat_push_logs\n";

$cols = $pdo->query("SHOW COLUMNS FROM `{$prefix}chat_group_members` LIKE 'notify_mute'")->fetchAll();
if (!$cols) {
    $pdo->exec("ALTER TABLE `{$prefix}chat_group_members` ADD COLUMN `notify_mute` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=消息不提醒(不推送/不提示音)' AFTER `mute_until`");
    echo "OK added notify_mute\n";
} else {
    echo "SKIP notify_mute\n";
}

$rule = $prefix . 'auth_rule';
$now = time();
$imPid = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_im' LIMIT 1")->fetchColumn();
if (!$imPid) {
    $imPid = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
}
if (!$imPid) {
    fwrite(STDERR, "fanshub menu missing\n");
    exit(1);
}
$insert = $pdo->prepare("INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

$menus = [
    ['fanshub/push', '极光推送', 'fa fa-bell', 1, 74, [
        ['fanshub/push/index', '查看'],
        ['fanshub/push/send', '发送'],
        ['fanshub/push/del', '删除日志'],
    ]],
    ['fanshub/pushdevice', '推送设备', 'fa fa-mobile', 1, 73, [
        ['fanshub/pushdevice/index', '查看'],
        ['fanshub/pushdevice/del', '删除'],
        ['fanshub/pushdevice/multi', '批量更新'],
    ]],
];

$allRuleIds = [];
foreach ($menus as $m) {
    $name = $m[0];
    $pid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
    if (!$pid) {
        $insert->execute(['file', (int)$imPid, $name, $m[1], $m[2], '', '', '', $m[3], null, $now, $now, $m[4], 'normal']);
        $pid = (int)$pdo->lastInsertId();
        echo "OK menu {$name}\n";
    } else {
        echo "SKIP menu {$name}\n";
    }
    $allRuleIds[] = (int)$pid;
    foreach ($m[5] as $child) {
        $cname = $child[0];
        $exists = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($cname) . " LIMIT 1")->fetchColumn();
        if ($exists) {
            $allRuleIds[] = (int)$exists;
            echo "SKIP {$cname}\n";
            continue;
        }
        $insert->execute(['file', (int)$pid, $cname, $child[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
        $allRuleIds[] = (int)$pdo->lastInsertId();
        echo "OK {$cname}\n";
    }
}

$g = $pdo->query("SELECT rules FROM {$prefix}auth_group WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if ($g && $allRuleIds) {
    $have = array_flip(array_filter(explode(',', (string)$g['rules'])));
    $missing = [];
    foreach ($allRuleIds as $rid) {
        if ($rid > 0 && !isset($have[$rid])) {
            $missing[] = $rid;
        }
    }
    if ($missing) {
        $new = trim((string)$g['rules'] . ',' . implode(',', $missing), ',');
        $pdo->prepare("UPDATE {$prefix}auth_group SET rules=? WHERE id=1")->execute([$new]);
        echo 'GRANTED +' . count($missing) . "\n";
    }
}
echo "DONE\n";
