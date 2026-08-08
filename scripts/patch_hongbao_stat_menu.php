<?php
/**
 * 后台菜单：红宝统计
 * php scripts/patch_hongbao_stat_menu.php
 */
$root = dirname(__DIR__);
$ini = parse_ini_file($root . '/.env', true);
if (empty($ini['database'])) {
    fwrite(STDERR, "missing .env database\n");
    exit(1);
}
$d = $ini['database'];
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $d['hostname'],
        (int)(isset($d['hostport']) ? $d['hostport'] : 3306),
        $d['database']
    ),
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pre = isset($d['prefix']) ? $d['prefix'] : 'fa_';
$rule = $pre . 'auth_rule';
$name = 'fanshub/hongbaostat';
$exists = $pdo->query("SELECT id FROM `{$rule}` WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
if ($exists) {
    echo "menu already exists id={$exists}\n";
    exit(0);
}
$imId = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub_im' LIMIT 1")->fetchColumn();
$parentId = $imId ?: (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub' LIMIT 1")->fetchColumn();
if ($parentId <= 0) {
    fwrite(STDERR, "parent menu missing\n");
    exit(1);
}
$now = time();
$st = $pdo->prepare(
    "INSERT INTO `{$rule}` (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)
     VALUES ('file',?,?,?,?,?,?,?,?,?,?,?,?,?)"
);
$st->execute([
    $parentId,
    $name,
    '红宝统计',
    'fa fa-bar-chart',
    '',
    '',
    '真人/机器人红包流水与充值加款',
    1,
    null,
    $now,
    $now,
    58,
    'normal',
]);
$id = (int)$pdo->lastInsertId();
echo "OK menu id={$id}\n";

// 不再自动改 auth_group.rules：避免把超级管理员从 * / 完整列表收窄成少量 ID。
// 新菜单对 rules='*' 的组自动可见；其它组请在后台「权限管理」勾选。
echo "OK menu id={$id} (group rules untouched)\n";

