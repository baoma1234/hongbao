<?php
/**
 * 安装 IM 群组管理菜单
 * php scripts/install_imgroup_menu.php
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
$fansPid = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if (!$fansPid) {
    fwrite(STDERR, "fanshub menu missing\n");
    exit(1);
}
$insert = $pdo->prepare("INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$name = 'fanshub/imgroup';
$pid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
if (!$pid) {
    $insert->execute(['file', $fansPid, $name, 'IM群组', 'fa fa-users', '', '', '后台创建群、配置群主/管理员', 1, null, $now, $now, 0, 'normal']);
    $pid = $pdo->lastInsertId();
    echo "OK  {$name}\n";
} else {
    echo "SKIP {$name}\n";
}
$children = [
    ['fanshub/imgroup/index', '查看'],
    ['fanshub/imgroup/add', '创建'],
    ['fanshub/imgroup/edit', '编辑'],
    ['fanshub/imgroup/del', '解散'],
    ['fanshub/imgroup/members', '群成员'],
    ['fanshub/imgroup/kick', '踢人'],
    ['fanshub/imgroup/mute', '禁言'],
    ['fanshub/imgroup/muteall', '全员禁言'],
    ['fanshub/imgroup/invite', '添加成员页'],
    ['fanshub/imgroup/candidates', '候选用户'],
    ['fanshub/imgroup/addmembers', '批量拉人'],
];
foreach ($children as $c) {
    if ($pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($c[0]) . " LIMIT 1")->fetchColumn()) {
        echo "SKIP {$c[0]}\n";
        continue;
    }
    $insert->execute(['file', $pid, $c[0], $c[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
    echo "OK   {$c[0]}\n";
}
// 把 IM 群组相关节点授权给超级管理组
$ruleIds = $pdo->query("SELECT id FROM {$rule} WHERE name LIKE 'fanshub/imgroup%'")->fetchAll(PDO::FETCH_COLUMN);
$g = $pdo->query('SELECT rules FROM fa_auth_group WHERE id=1')->fetch(PDO::FETCH_ASSOC);
if ($g && $ruleIds) {
    $have = array_flip(array_filter(explode(',', (string)$g['rules'])));
    $missing = [];
    foreach ($ruleIds as $rid) {
        if (!isset($have[$rid])) {
            $missing[] = $rid;
        }
    }
    if ($missing) {
        $new = trim((string)$g['rules'] . ',' . implode(',', $missing), ',');
        $pdo->prepare('UPDATE fa_auth_group SET rules=? WHERE id=1')->execute([$new]);
        echo 'GRANTED group#1 +' . count($missing) . " rules\n";
    }
}
// 顺带把 imagent 菜单标题改为更清晰
$pdo->exec("UPDATE {$rule} SET title='IM管理员/代聊' WHERE name='fanshub/imagent' LIMIT 1");
echo "DONE 请在角色组勾选 IM群组 权限并清缓存\n";
