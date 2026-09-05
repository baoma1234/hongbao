<?php
/**
 * 大厅装修：轮播 / 分类 / 游戏格 / 邀请条 + 后台菜单
 * php scripts/install_lobby_home.php
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

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}fans_lobby_banners` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(64) NOT NULL DEFAULT '' COMMENT '备注名',
  `image` varchar(255) NOT NULL DEFAULT '' COMMENT '图片',
  `link_type` varchar(32) NOT NULL DEFAULT 'none' COMMENT 'none|fission|messages|url',
  `link_url` varchar(255) NOT NULL DEFAULT '',
  `weigh` int(11) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'normal' COMMENT 'normal|hidden',
  `createtime` int(10) unsigned NOT NULL DEFAULT 0,
  `updatetime` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status_weigh` (`status`,`weigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大厅轮播图'");
echo "OK fans_lobby_banners\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}fans_lobby_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cat_key` varchar(32) NOT NULL DEFAULT '' COMMENT 'hot/games/notice…',
  `title` varchar(64) NOT NULL DEFAULT '' COMMENT '分类名',
  `icon` varchar(255) NOT NULL DEFAULT '' COMMENT '图标URL或相对路径',
  `icon_static` varchar(128) NOT NULL DEFAULT '' COMMENT '打包static相对路径如logo.png',
  `action` varchar(32) NOT NULL DEFAULT 'filter' COMMENT 'filter|notice|commission|url',
  `action_url` varchar(255) NOT NULL DEFAULT '',
  `weigh` int(11) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'normal',
  `createtime` int(10) unsigned NOT NULL DEFAULT 0,
  `updatetime` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cat_key` (`cat_key`),
  KEY `idx_status_weigh` (`status`,`weigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大厅分类'");
echo "OK fans_lobby_categories\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}fans_lobby_games` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `game_key` varchar(32) NOT NULL DEFAULT '' COMMENT 'jielong/saolei…',
  `title` varchar(64) NOT NULL DEFAULT '',
  `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '封面图',
  `badge` varchar(16) NOT NULL DEFAULT '' COMMENT 'hot/new/空',
  `cats` varchar(255) NOT NULL DEFAULT '' COMMENT '逗号分隔分类key',
  `group_match` varchar(128) NOT NULL DEFAULT '' COMMENT '单群匹配正则',
  `sum_group_match` varchar(128) NOT NULL DEFAULT '' COMMENT '多群合计正则',
  `coming_soon` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `weigh` int(11) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'normal' COMMENT 'normal|hidden',
  `createtime` int(10) unsigned NOT NULL DEFAULT 0,
  `updatetime` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_game_key` (`game_key`),
  KEY `idx_status_weigh` (`status`,`weigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大厅游戏格'");
echo "OK fans_lobby_games\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}fans_lobby_invites` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(64) NOT NULL DEFAULT '',
  `image` varchar(255) NOT NULL DEFAULT '',
  `link_type` varchar(32) NOT NULL DEFAULT 'share' COMMENT 'share|url|none',
  `link_url` varchar(255) NOT NULL DEFAULT '',
  `weigh` int(11) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'normal',
  `createtime` int(10) unsigned NOT NULL DEFAULT 0,
  `updatetime` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status_weigh` (`status`,`weigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大厅邀请条'");
echo "OK fans_lobby_invites\n";

// seeds
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}fans_lobby_banners`")->fetchColumn();
if ($cnt === 0) {
    $pdo->prepare("INSERT INTO `{$prefix}fans_lobby_banners` (title,image,link_type,link_url,weigh,status,createtime,updatetime) VALUES (?,?,?,?,?,?,?,?)")
        ->execute(['默认轮播', 'home/lobby/750x400.png', 'fission', '', 100, 'normal', $now, $now]);
    echo "OK seed banner\n";
}

$cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}fans_lobby_categories`")->fetchColumn();
if ($cnt === 0) {
    $ins = $pdo->prepare("INSERT INTO `{$prefix}fans_lobby_categories` (cat_key,title,icon,icon_static,action,action_url,weigh,status,createtime,updatetime) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $ins->execute(['hot', '热门推荐', 'home/lobby/1.png', '', 'filter', '', 100, 'normal', $now, $now]);
    $ins->execute(['games', '红宝游戏', '', 'logo.png', 'filter', '', 90, 'normal', $now, $now]);
    $ins->execute(['notice', '红宝公告', 'home/lobby/66.png', '', 'notice', '', 80, 'normal', $now, $now]);
    $ins->execute(['commission', '红宝佣金', 'home/lobby/commission.png', '', 'commission', '', 70, 'normal', $now, $now]);
    echo "OK seed categories\n";
}

$cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}fans_lobby_games`")->fetchColumn();
if ($cnt === 0) {
    $ins = $pdo->prepare("INSERT INTO `{$prefix}fans_lobby_games` (game_key,title,cover,badge,cats,group_match,sum_group_match,coming_soon,weigh,status,createtime,updatetime) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute(['jielong', '红宝接龙', 'home/lobby/01.png', 'hot', 'hot,games', '', '红宝接龙\\s*(20|50|100|500)群', 0, 100, 'normal', $now, $now]);
    $ins->execute(['saolei', '红宝扫雷', 'home/lobby/02.png', '', 'hot,games', '扫雷', '', 0, 90, 'normal', $now, $now]);
    $ins->execute(['niuniu', '红宝牛牛', 'home/lobby/03.png', '', 'hot,games', '牛牛', '', 0, 80, 'normal', $now, $now]);
    $ins->execute(['battle', '红宝对战', 'home/lobby/04.png', '', 'hot,games', '全员自由发宝|全员自动发包', '', 0, 70, 'normal', $now, $now]);
    $ins->execute(['blindbox', '幸运盲盒', 'home/lobby/05.png', 'new', 'hot,event', '', '', 1, 60, 'hidden', $now, $now]);
    $ins->execute(['yxx', '趣味鱼虾蟹', 'home/lobby/06.png', '', 'hot,card', '', '', 1, 50, 'hidden', $now, $now]);
    echo "OK seed games (blindbox/yxx hidden)\n";
}

$cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}fans_lobby_invites`")->fetchColumn();
if ($cnt === 0) {
    $pdo->prepare("INSERT INTO `{$prefix}fans_lobby_invites` (title,image,link_type,link_url,weigh,status,createtime,updatetime) VALUES (?,?,?,?,?,?,?,?)")
        ->execute(['邀请条', 'home/lobby/750x150.png', 'share', '', 100, 'normal', $now, $now]);
    echo "OK seed invite\n";
}

$rule = $prefix . 'auth_rule';
$fansPid = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if (!$fansPid) {
    fwrite(STDERR, "fanshub menu missing\n");
    exit(1);
}
$insert = $pdo->prepare("INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

$parentName = 'fanshub_lobby';
$parentId = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($parentName) . " LIMIT 1")->fetchColumn();
if (!$parentId) {
    $insert->execute(['file', (int)$fansPid, $parentName, '大厅装修', 'fa fa-th-large', '', '', '轮播/分类/游戏格/邀请条', 1, null, $now, $now, 55, 'normal']);
    $parentId = (int)$pdo->lastInsertId();
    echo "OK menu {$parentName} #{$parentId}\n";
} else {
    echo "SKIP menu {$parentName} #{$parentId}\n";
}

$menus = [
    ['fanshub/lobbybanner', '轮播图管理', 'fa fa-picture-o', 40],
    ['fanshub/lobbycategory', '大厅分类管理', 'fa fa-th', 30],
    ['fanshub/lobbygame', '大厅分类游戏管理', 'fa fa-gamepad', 20],
    ['fanshub/lobbyinvite', '邀请条管理', 'fa fa-gift', 10],
];
$allRuleIds = [(int)$parentId];
foreach ($menus as $m) {
    $name = $m[0];
    $pid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
    if (!$pid) {
        $insert->execute(['file', (int)$parentId, $name, $m[1], $m[2], '', '', '', 1, null, $now, $now, $m[3], 'normal']);
        $pid = (int)$pdo->lastInsertId();
        echo "OK menu {$name} #{$pid}\n";
    } else {
        echo "SKIP menu {$name} #{$pid}\n";
    }
    $allRuleIds[] = (int)$pid;
    foreach (['index' => '查看', 'add' => '添加', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新'] as $act => $title) {
        $cname = $name . '/' . $act;
        $exists = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($cname) . " LIMIT 1")->fetchColumn();
        if ($exists) {
            $allRuleIds[] = (int)$exists;
            continue;
        }
        $insert->execute(['file', (int)$pid, $cname, $title, 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
        $allRuleIds[] = (int)$pdo->lastInsertId();
        echo "OK {$cname}\n";
    }
}

$grp = $pdo->query("SELECT id,rules FROM {$prefix}auth_group WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($grp) {
    $rules = array_filter(array_map('intval', explode(',', (string)$grp['rules'])));
    $merged = array_values(array_unique(array_merge($rules, $allRuleIds)));
    $pdo->prepare("UPDATE {$prefix}auth_group SET rules=? WHERE id=1")->execute([implode(',', $merged)]);
    echo "OK granted group#1\n";
}

$cacheDir = $root . '/runtime/cache';
if (is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/*') as $f) {
        if (is_file($f)) @unlink($f);
    }
}
echo "DONE\n";
