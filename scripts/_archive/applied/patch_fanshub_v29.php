<?php
/**
 * 福利大厅 v29：独立「游戏账号审核」菜单
 * php scripts/patch_fanshub_v29.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']
);

$rule = 'fa_auth_rule';
$now = time();
$insert = $pdo->prepare('INSERT INTO fa_auth_rule (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

$rootId = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if (!$rootId) {
    fwrite(STDERR, "WARN  fanshub menu not found\n");
    exit(1);
}

$menuId = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/uidaudit' LIMIT 1")->fetchColumn();
if ($menuId) {
    echo "SKIP  fanshub/uidaudit menu exists (id={$menuId})\n";
} else {
    // 权重大于用户账户，方便点开
    $insert->execute(['file', $rootId, 'fanshub/uidaudit', '游戏账号审核', 'fa fa-id-card', '', '', 'H5提交的游戏账号核销', 1, 'addtabs', $now, $now, 12, 'normal']);
    $menuId = (int)$pdo->lastInsertId();
    echo "OK    fanshub/uidaudit menu (id={$menuId})\n";
}

$perms = [
    ['fanshub/uidaudit/index', '查看'],
    ['fanshub/uidaudit/approve', '通过核销'],
    ['fanshub/uidaudit/reject', '拒绝'],
    ['fanshub/uidaudit/export', '导出'],
];
foreach ($perms as $perm) {
    $name = $perm[0];
    $title = $perm[1];
    if ($pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn()) {
        echo "SKIP  {$name}\n";
        continue;
    }
    $insert->execute(['file', $menuId, $name, $title, 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
    echo "OK    {$name}\n";
}

// 超级管理组补权限（若存在）
$groupId = $pdo->query("SELECT id FROM fa_auth_group WHERE id=1 LIMIT 1")->fetchColumn();
if ($groupId) {
    $rules = (string)$pdo->query("SELECT rules FROM fa_auth_group WHERE id=1")->fetchColumn();
    if ($rules === '*') {
        echo "OK    auth_group=1 already *\n";
    } else {
        $ids = array_filter(array_map('intval', explode(',', $rules)));
        $need = $pdo->query("SELECT id FROM {$rule} WHERE name LIKE 'fanshub/uidaudit%'")->fetchAll(PDO::FETCH_COLUMN);
        $changed = false;
        foreach ($need as $rid) {
            $rid = (int)$rid;
            if (!in_array($rid, $ids, true)) {
                $ids[] = $rid;
                $changed = true;
            }
        }
        if ($changed) {
            $pdo->prepare('UPDATE fa_auth_group SET rules=? WHERE id=1')->execute([implode(',', $ids)]);
            echo "OK    auth_group=1 rules updated\n";
        } else {
            echo "SKIP  auth_group=1 already has uidaudit rules\n";
        }
    }
}

echo "DONE  福利大厅 -> 游戏账号审核；请重新登录后台\n";
