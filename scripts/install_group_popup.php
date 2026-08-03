<?php
/**
 * 群弹窗表 + 展示记录表 + 后台菜单
 * php scripts/install_group_popup.php
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

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}chat_group_popups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '群ID',
  `title` varchar(128) NOT NULL DEFAULT '' COMMENT '标题(中文)',
  `title_i18n` text COMMENT '标题多语言JSON',
  `content` text COMMENT '正文(中文)',
  `content_i18n` text COMMENT '正文多语言JSON',
  `images` text COMMENT '图片JSON数组',
  `show_mode` varchar(16) NOT NULL DEFAULT 'always' COMMENT 'always每次|once仅一次',
  `weigh` int(11) NOT NULL DEFAULT '0' COMMENT '排序越大越先',
  `status` varchar(16) NOT NULL DEFAULT 'normal' COMMENT 'normal|hidden',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_group_status_weigh` (`group_id`,`status`,`weigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='群进群弹窗'");
echo "OK table chat_group_popups\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}chat_group_popup_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `popup_id` int(10) unsigned NOT NULL DEFAULT '0',
  `group_id` int(10) unsigned NOT NULL DEFAULT '0',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `action` varchar(32) NOT NULL DEFAULT 'view' COMMENT 'view|dismiss_forever',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_popup_user` (`popup_id`,`user_id`),
  KEY `idx_group_time` (`group_id`,`createtime`),
  KEY `idx_user_time` (`user_id`,`createtime`),
  KEY `idx_action_popup_user` (`action`,`popup_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='群弹窗展示/关闭记录'");
echo "OK table chat_group_popup_logs\n";

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
    ['fanshub/grouppopup', '群弹窗', 'fa fa-window-restore', 1, 72, [
        ['fanshub/grouppopup/index', '查看'],
        ['fanshub/grouppopup/add', '添加'],
        ['fanshub/grouppopup/edit', '编辑'],
        ['fanshub/grouppopup/del', '删除'],
        ['fanshub/grouppopup/multi', '批量更新'],
    ]],
    ['fanshub/grouppopuplog', '群弹窗记录', 'fa fa-history', 1, 71, [
        ['fanshub/grouppopuplog/index', '查看'],
        ['fanshub/grouppopuplog/del', '删除'],
    ]],
];

$allRuleIds = [];
foreach ($menus as $m) {
    $name = $m[0];
    $pid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
    if (!$pid) {
        $insert->execute(['file', $imPid, $name, $m[1], $m[2], '', '', '', $m[3], null, $now, $now, $m[4], 'normal']);
        $pid = (int)$pdo->lastInsertId();
        echo "OK  {$name}\n";
    } else {
        echo "SKIP {$name}\n";
    }
    $allRuleIds[] = (int)$pid;
    foreach ($m[5] as $c) {
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
