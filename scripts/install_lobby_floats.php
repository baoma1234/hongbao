<?php
/**
 * 大厅左右浮标：表 + 后台菜单
 * php scripts/install_lobby_floats.php
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

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}fans_lobby_floats` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(64) NOT NULL DEFAULT '' COMMENT '备注名',
  `image` varchar(255) NOT NULL DEFAULT '' COMMENT '悬浮图标',
  `side` varchar(8) NOT NULL DEFAULT 'right' COMMENT 'left|right',
  `link_type` varchar(32) NOT NULL DEFAULT 'internal' COMMENT 'none|internal|external',
  `link_url` varchar(255) NOT NULL DEFAULT '' COMMENT '站内 pages/... 或站外 http(s)',
  `weigh` int(11) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'normal' COMMENT 'normal|hidden',
  `createtime` int(10) unsigned NOT NULL DEFAULT 0,
  `updatetime` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status_side_weigh` (`status`,`side`,`weigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大厅左右浮标'");
echo "OK fans_lobby_floats\n";

$rule = "`{$prefix}auth_rule`";
$insert = $pdo->prepare("INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

$parentName = 'fanshub_lobby';
$parentId = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($parentName) . " LIMIT 1")->fetchColumn();
if (!$parentId) {
    $insert->execute(['file', 0, $parentName, '大厅装修', 'fa fa-th-large', '', '', '轮播/分类/游戏格/玩法说明/邀请条/浮标', 1, null, $now, $now, 47, 'normal']);
    $parentId = (int)$pdo->lastInsertId();
    echo "OK menu {$parentName} #{$parentId}\n";
} else {
    $parentId = (int)$parentId;
}

$name = 'fanshub/lobbyfloat';
$pid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
if (!$pid) {
    $insert->execute(['file', $parentId, $name, '左右浮标', 'fa fa-bullseye', '', '', '首页左右悬浮入口', 1, null, $now, $now, 35, 'normal']);
    $pid = (int)$pdo->lastInsertId();
    echo "OK menu {$name} #{$pid}\n";
} else {
    $pdo->prepare("UPDATE {$rule} SET pid=?, ismenu=1, status='normal', title=?, icon=?, weigh=?, updatetime=? WHERE id=?")
        ->execute([$parentId, '左右浮标', 'fa fa-bullseye', 35, $now, (int)$pid]);
    $pid = (int)$pid;
    echo "OK menu {$name} #{$pid} synced\n";
}

$allRuleIds = [$parentId, $pid];
foreach (['index' => '查看', 'add' => '添加', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新'] as $act => $title) {
    $cname = $name . '/' . $act;
    $exists = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($cname) . " LIMIT 1")->fetchColumn();
    if ($exists) {
        $allRuleIds[] = (int)$exists;
        continue;
    }
    $insert->execute(['file', $pid, $cname, $title, 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
    $allRuleIds[] = (int)$pdo->lastInsertId();
    echo "OK {$cname}\n";
}

$grp = $pdo->query("SELECT id,rules FROM {$prefix}auth_group WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($grp) {
    $raw = trim((string)$grp['rules']);
    if ($raw === '*') {
        echo "SKIP grant group#1 (rules=*)\n";
    } else {
        $rules = array_filter(array_map('intval', explode(',', $raw)));
        $merged = array_values(array_unique(array_merge($rules, $allRuleIds)));
        $pdo->prepare("UPDATE {$prefix}auth_group SET rules=? WHERE id=1")->execute([implode(',', $merged)]);
        echo "OK granted group#1\n";
    }
}

$cacheDir = $root . '/runtime/cache';
if (is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/*') as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}
echo "DONE\n";
