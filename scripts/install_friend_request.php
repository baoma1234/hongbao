<?php
/**
 * 安装好友申请表 + 后台菜单 + 客服回复字段
 * php scripts/install_friend_request.php
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

// 1) 申请表
$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}chat_friend_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `from_user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `to_user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `message` varchar(200) NOT NULL DEFAULT '',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `handle_user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_to_status` (`to_user_id`,`status`),
  KEY `idx_from_status` (`from_user_id`,`status`),
  KEY `idx_status_time` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM好友申请'");
echo "OK table chat_friend_requests\n";

// 2) agent friend_reply 列
$cols = $pdo->query("SHOW COLUMNS FROM `{$prefix}chat_agent_accounts` LIKE 'friend_reply'")->fetchAll();
if (!$cols) {
    $pdo->exec("ALTER TABLE `{$prefix}chat_agent_accounts` ADD COLUMN `friend_reply` varchar(500) NOT NULL DEFAULT '' COMMENT '被加好友自动回复' AFTER `scope`");
    echo "OK column friend_reply\n";
} else {
    echo "SKIP column friend_reply\n";
}

// 3) 菜单
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
    ['fanshub/friendrequest', '好友申请', 'fa fa-user-plus', 1, [
        ['fanshub/friendrequest/index', '查看'],
        ['fanshub/friendrequest/approve', '通过'],
        ['fanshub/friendrequest/reject', '拒绝'],
        ['fanshub/friendrequest/del', '删除'],
    ]],
    ['fanshub/csreply', '客服加友回复', 'fa fa-commenting', 1, [
        ['fanshub/csreply/index', '查看'],
        ['fanshub/csreply/save', '保存'],
    ]],
];

$allRuleIds = [];
foreach ($menus as $m) {
    $name = $m[0];
    $pid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
    if (!$pid) {
        $insert->execute(['file', $imPid, $name, $m[1], $m[2], '', '', '', $m[3], null, $now, $now, 0, 'normal']);
        $pid = (int)$pdo->lastInsertId();
        echo "OK  {$name}\n";
    } else {
        echo "SKIP {$name}\n";
    }
    $allRuleIds[] = (int)$pid;
    foreach ($m[4] as $c) {
        $cid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($c[0]) . " LIMIT 1")->fetchColumn();
        if ($cid) {
            echo "SKIP {$c[0]}\n";
            $allRuleIds[] = (int)$cid;
            continue;
        }
        $insert->execute(['file', $pid, $c[0], $c[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
        $allRuleIds[] = (int)$pdo->lastInsertId();
        echo "OK   {$c[0]}\n";
    }
}

$g = $pdo->query('SELECT rules FROM ' . $prefix . 'auth_group WHERE id=1')->fetch(PDO::FETCH_ASSOC);
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
        $pdo->prepare('UPDATE ' . $prefix . 'auth_group SET rules=? WHERE id=1')->execute([$new]);
        echo 'GRANTED group#1 +' . count($missing) . " rules\n";
    }
}

echo "DONE 请清后台缓存；并重启 IM 服务\n";
