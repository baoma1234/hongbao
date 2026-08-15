<?php
/**
 * 红宝页（消息 Tab）弹窗表 + 记录 + 后台菜单
 * php scripts/install_messages_popup.php
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

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}chat_messages_popups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(128) NOT NULL DEFAULT '' COMMENT '标题',
  `content` text COMMENT '正文',
  `images` text COMMENT '图片JSON数组',
  `jump_type` varchar(32) NOT NULL DEFAULT 'none' COMMENT 'community|notice|url|none',
  `jump_extra` varchar(255) NOT NULL DEFAULT '' COMMENT 'notice分类或外链URL',
  `btn_text` varchar(64) NOT NULL DEFAULT '查看' COMMENT '主按钮文案',
  `show_mode` varchar(16) NOT NULL DEFAULT 'daily' COMMENT 'always|daily|once',
  `weigh` int(11) NOT NULL DEFAULT '0' COMMENT '越大越先',
  `status` varchar(16) NOT NULL DEFAULT 'normal' COMMENT 'normal|hidden',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_status_weigh` (`status`,`weigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='红宝消息页弹窗'");
echo "OK table chat_messages_popups\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}chat_messages_popup_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `popup_id` int(10) unsigned NOT NULL DEFAULT '0',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `action` varchar(32) NOT NULL DEFAULT 'view' COMMENT 'view|dismiss_day|dismiss_once',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_popup_user` (`popup_id`,`user_id`),
  KEY `idx_action_popup_user` (`action`,`popup_id`,`user_id`),
  KEY `idx_user_time` (`user_id`,`createtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='红宝页弹窗展示/关闭记录'");
echo "OK table chat_messages_popup_logs\n";

$cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}chat_messages_popups`")->fetchColumn();
if ($cnt === 0) {
    $now = time();
    $ins = $pdo->prepare("INSERT INTO `{$prefix}chat_messages_popups`
      (title, content, images, jump_type, jump_extra, btn_text, show_mode, weigh, status, createtime, updatetime)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([
        '发现红宝社群',
        "官方社群与公告都在「红宝」页顶部 Tab。\n点下方按钮可直接进入社群看看。",
        '[]',
        'community',
        '',
        '进入社群',
        'daily',
        100,
        'normal',
        $now,
        $now,
    ]);
    $ins->execute([
        '最新公告',
        '平台规则与活动公告请到「公告」查看。',
        '[]',
        'notice',
        'latest',
        '查看公告',
        'daily',
        90,
        'hidden',
        $now,
        $now,
    ]);
    echo "OK seeded sample popups\n";
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
    ['fanshub/messagespopup', '红宝页弹窗', 'fa fa-commenting', 1, 73, [
        ['fanshub/messagespopup/index', '查看'],
        ['fanshub/messagespopup/add', '添加'],
        ['fanshub/messagespopup/edit', '编辑'],
        ['fanshub/messagespopup/del', '删除'],
        ['fanshub/messagespopup/multi', '批量更新'],
    ]],
];

$allRuleIds = [];
foreach ($menus as $m) {
    $name = $m[0];
    $pid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
    if (!$pid) {
        $insert->execute(['file', (int)$imPid, $name, $m[1], $m[2], '', '', '', $m[3], null, $now, $now, $m[4], 'normal']);
        $pid = (int)$pdo->lastInsertId();
        echo "OK menu {$name} #{$pid}\n";
    } else {
        echo "SKIP menu {$name} #{$pid}\n";
    }
    $allRuleIds[] = (int)$pid;
    foreach ($m[5] as $child) {
        $cname = $child[0];
        $exists = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($cname) . " LIMIT 1")->fetchColumn();
        if ($exists) {
            echo "SKIP {$cname}\n";
            $allRuleIds[] = (int)$exists;
            continue;
        }
        $insert->execute(['file', (int)$pid, $cname, $child[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
        $allRuleIds[] = (int)$pdo->lastInsertId();
        echo "OK {$cname}\n";
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

echo "DONE\n";
