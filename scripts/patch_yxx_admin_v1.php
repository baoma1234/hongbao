<?php
/**
 * 鱼虾蟹后台菜单：奖池熔断 + 局归档
 * php scripts/patch_yxx_admin_v1.php
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

$poolId = ensureMenu($pdo, $insert, $rule, $parentId, 'fanshub/yxxpool', '鱼虾蟹奖池熔断', 'fa fa-shield', 1, 66, $now, '大奖池状态与红包雨');
ensureMenu($pdo, $insert, $rule, $poolId, 'fanshub/yxxpool/index', '查看', 'fa fa-circle-o', 0, 0, $now);
ensureMenu($pdo, $insert, $rule, $poolId, 'fanshub/yxxpool/setstatus', '切换熔断', 'fa fa-circle-o', 0, 0, $now);
ensureMenu($pdo, $insert, $rule, $poolId, 'fanshub/yxxpool/rains', '红包雨列表', 'fa fa-circle-o', 0, 0, $now);
ensureMenu($pdo, $insert, $rule, $poolId, 'fanshub/yxxpool/raindetail', '红包雨明细', 'fa fa-circle-o', 0, 0, $now);

$listId = ensureMenu($pdo, $insert, $rule, $parentId, 'fanshub/yxxround', '鱼虾蟹局归档', 'fa fa-list', 1, 65, $now, '每局结算归档');
ensureMenu($pdo, $insert, $rule, $listId, 'fanshub/yxxround/index', '查看', 'fa fa-circle-o', 0, 0, $now);

echo "DONE yxx admin v1\n";
