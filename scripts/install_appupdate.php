<?php
/**
 * 安装 APP 升级表 + 菜单（会员运营 → APP升级）
 * php scripts/install_appupdate.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $d['hostname'] ?? '127.0.0.1',
        (int)($d['hostport'] ?? 3306),
        $d['database'] ?? ''
    ),
    $d['username'] ?? 'root',
    $d['password'] ?? '',
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$rule = $prefix . 'auth_rule';
$group = $prefix . 'auth_group';
$table = $prefix . 'fanshub_app_update';
$now = time();

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `platform` varchar(16) NOT NULL DEFAULT 'android' COMMENT 'android|ios',
  `version_name` varchar(32) NOT NULL DEFAULT '',
  `version_code` int(10) unsigned NOT NULL DEFAULT '0',
  `download_url` varchar(500) NOT NULL DEFAULT '',
  `force_update` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `update_note` varchar(1000) NOT NULL DEFAULT '',
  `is_current` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '当前推送版本',
  `status` enum('normal','hidden') NOT NULL DEFAULT 'normal',
  `admin_id` int(10) unsigned NOT NULL DEFAULT '0',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_plat_current` (`platform`,`is_current`,`status`),
  KEY `idx_plat_code` (`platform`,`version_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='APP升级记录'");
echo "OK table {$table}\n";

// 从现有 fanshub 配置灌入首条（若表空）
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
if ($cnt === 0) {
    $cfgFile = $root . '/application/extra/fanshub.php';
    $cfg = is_file($cfgFile) ? (include $cfgFile) : [];
    if (!is_array($cfg)) {
        $cfg = [];
    }
    $ins = $pdo->prepare(
        "INSERT INTO {$table} (platform,version_name,version_code,download_url,force_update,update_note,is_current,status,admin_id,remark,createtime,updatetime)
         VALUES (?,?,?,?,?,?,1,'normal',0,'从配置导入',?,?)"
    );
    foreach (['android', 'ios'] as $plat) {
        $name = trim((string)($cfg["app_{$plat}_version_name"] ?? ''));
        $code = (int)($cfg["app_{$plat}_version_code"] ?? 0);
        $url = trim((string)($cfg["app_{$plat}_download_url"] ?? ''));
        if ($url === '') {
            $url = trim((string)($cfg['app_download_url'] ?? ''));
        }
        $force = !empty($cfg["app_{$plat}_force_update"]) ? 1 : 0;
        $note = trim((string)($cfg["app_{$plat}_update_note"] ?? ''));
        if ($code <= 0 && $name === '') {
            continue;
        }
        $ins->execute([$plat, $name, $code, $url, $force, $note, $now, $now]);
        echo "SEED {$plat} code={$code}\n";
    }
}

$parentId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_member' LIMIT 1")->fetchColumn();
if ($parentId <= 0) {
    $parentId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
}
if ($parentId <= 0) {
    fwrite(STDERR, "fanshub_member menu missing\n");
    exit(1);
}

$insert = $pdo->prepare(
    "INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)"
    . " VALUES ('file',?,?,?,?,?,?,?,?,?,?,?,?,?)"
);

$menu = [
    'name'  => 'fanshub/appupdate',
    'title' => 'APP升级',
    'icon'  => 'fa fa-cloud-upload',
    'weigh' => 72,
    'children' => [
        ['fanshub/appupdate/index', '查看'],
        ['fanshub/appupdate/add', '添加'],
        ['fanshub/appupdate/edit', '编辑'],
        ['fanshub/appupdate/del', '删除'],
        ['fanshub/appupdate/publish', '设为当前'],
        ['fanshub/appupdate/toggle', '开关'],
    ],
];

$menuId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($menu['name']) . " LIMIT 1")->fetchColumn();
if ($menuId <= 0) {
    $insert->execute([
        $parentId, $menu['name'], $menu['title'], $menu['icon'], '', '', $menu['title'],
        1, 'addtabs', $now, $now, $menu['weigh'], 'normal',
    ]);
    $menuId = (int)$pdo->lastInsertId();
    echo "OK  {$menu['name']} id={$menuId}\n";
} else {
    $pdo->prepare("UPDATE {$rule} SET pid=?, title=?, icon=?, ismenu=1, menutype='addtabs', status='normal', weigh=?, updatetime=? WHERE id=?")
        ->execute([$parentId, $menu['title'], $menu['icon'], $menu['weigh'], $now, $menuId]);
    echo "FIX {$menu['name']} id={$menuId}\n";
}

$allRuleIds = [$menuId];
foreach ($menu['children'] as $c) {
    $exists = (int)$pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($c[0]) . " LIMIT 1")->fetchColumn();
    if ($exists > 0) {
        $allRuleIds[] = $exists;
        echo "SKIP {$c[0]}\n";
        continue;
    }
    $insert->execute([$menuId, $c[0], $c[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
    $cid = (int)$pdo->lastInsertId();
    $allRuleIds[] = $cid;
    echo "OK   {$c[0]} id={$cid}\n";
}

$g = $pdo->query("SELECT rules FROM {$group} WHERE id=1")->fetch(PDO::FETCH_ASSOC);
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
        $pdo->prepare("UPDATE {$group} SET rules=? WHERE id=1")->execute([$new]);
        echo 'GRANTED group#1 +' . count($missing) . " rules\n";
    }
}

echo "DONE — 会员运营 → APP升级；请清后台菜单缓存\n";
