<?php
/**
 * 鱼虾蟹后台 v2：补齐权限 + 群桌/日下注菜单
 * php scripts/patch_yxx_admin_v2.php
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

$parentId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_play' LIMIT 1")->fetchColumn();
if ($parentId <= 0) {
    $parentId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
}
if ($parentId <= 0) {
    fwrite(STDERR, "fanshub menu missing\n");
    exit(1);
}

$poolId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/yxxpool' LIMIT 1")->fetchColumn();
if ($poolId <= 0) {
    $poolId = ensureMenu($pdo, $insert, $rule, $parentId, 'fanshub/yxxpool', '鱼虾蟹奖池熔断', 'fa fa-shield', 1, 66, $now, '大奖池状态与红包雨');
}
ensureMenu($pdo, $insert, $rule, $poolId, 'fanshub/yxxpool/savesettings', '保存大厅参数', 'fa fa-circle-o', 0, 0, $now);
ensureMenu($pdo, $insert, $rule, $poolId, 'fanshub/yxxpool/saveglobal', '保存总开关', 'fa fa-circle-o', 0, 0, $now);

$groupId = ensureMenu($pdo, $insert, $rule, $parentId, 'fanshub/yxxgroup', '鱼虾蟹群桌', 'fa fa-users', 1, 64, $now, '私域群开桌与爆点池');
ensureMenu($pdo, $insert, $rule, $groupId, 'fanshub/yxxgroup/index', '查看', 'fa fa-circle-o', 0, 0, $now);
ensureMenu($pdo, $insert, $rule, $groupId, 'fanshub/yxxgroup/close', '强制关桌', 'fa fa-circle-o', 0, 0, $now);

$dailyId = ensureMenu($pdo, $insert, $rule, $parentId, 'fanshub/yxxdailybet', '鱼虾蟹日下注', 'fa fa-bar-chart', 1, 63, $now, '红包雨资格权重');
ensureMenu($pdo, $insert, $rule, $dailyId, 'fanshub/yxxdailybet/index', '查看', 'fa fa-circle-o', 0, 0, $now);

echo "DONE yxx admin v2\n";
