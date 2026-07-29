<?php
/**
 * 安装「充提通道」后台菜单并授权超级管理组
 * php scripts/install_paychannel_menu.php
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
$rule = 'fa_auth_rule';
$now = time();

$parentName = 'fanshub';
$parentId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if ($parentId <= 0) {
    $parentName = 'fanshub_member';
    $parentId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($parentName) . " LIMIT 1")->fetchColumn();
}
if ($parentId <= 0) {
    fwrite(STDERR, "fanshub menu missing\n");
    exit(1);
}
echo "parent={$parentName} id={$parentId}\n";

$insert = $pdo->prepare(
    "INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)"
    . " VALUES ('file',?,?,?,?,?,?,?,?,?,?,?,?,?)"
);

$name = 'fanshub/paychannel';
$menuId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
if ($menuId <= 0) {
    $insert->execute([
        $parentId, $name, '充提通道', 'fa fa-credit-card', '', '', '充值/提现通道配置',
        1, 'addtabs', $now, $now, 75, 'normal',
    ]);
    $menuId = (int)$pdo->lastInsertId();
    echo "OK  {$name} id={$menuId}\n";
} else {
    $pdo->prepare("UPDATE {$rule} SET pid=?, title='充提通道', icon='fa fa-credit-card', ismenu=1, menutype='addtabs', status='normal', updatetime=? WHERE id=?")
        ->execute([$parentId, $now, $menuId]);
    echo "FIX {$name} id={$menuId}\n";
}

$children = [
    ['fanshub/paychannel/index', '查看'],
    ['fanshub/paychannel/add', '添加'],
    ['fanshub/paychannel/edit', '编辑'],
    ['fanshub/paychannel/del', '删除'],
    ['fanshub/paychannel/multi', '批量'],
];
foreach ($children as $c) {
    $exists = (int)$pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($c[0]) . " LIMIT 1")->fetchColumn();
    if ($exists > 0) {
        echo "SKIP {$c[0]}\n";
        continue;
    }
    $insert->execute([$menuId, $c[0], $c[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
    echo "OK   {$c[0]}\n";
}

$ruleIds = $pdo->query("SELECT id FROM {$rule} WHERE name LIKE 'fanshub/paychannel%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ([1] as $gid) {
    $g = $pdo->query("SELECT rules FROM fa_auth_group WHERE id=" . (int)$gid)->fetch(PDO::FETCH_ASSOC);
    if (!$g || !$ruleIds) {
        continue;
    }
    $have = array_flip(array_filter(explode(',', (string)$g['rules'])));
    $missing = [];
    foreach ($ruleIds as $rid) {
        if (!isset($have[$rid])) {
            $missing[] = $rid;
        }
    }
    if ($missing) {
        $new = trim((string)$g['rules'] . ',' . implode(',', $missing), ',');
        $pdo->prepare('UPDATE fa_auth_group SET rules=? WHERE id=?')->execute([$new, $gid]);
        echo "GRANTED group#{$gid} +" . count($missing) . " rules\n";
    }
}

// 清后台菜单缓存
$cacheDir = $root . '/runtime/cache';
if (is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/*/*.php') ?: [] as $f) {
        if (strpos(basename($f), 'menu') !== false || @filesize($f) > 50000) {
            @unlink($f);
        }
    }
}
echo "DONE 请刷新后台（Ctrl+F5）。菜单在：福利大厅 → 充提通道\n";
