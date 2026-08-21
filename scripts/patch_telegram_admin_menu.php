<?php
/**
 * Telegram 后台菜单：绑定用户列表 + 配置页权限
 * php scripts/patch_telegram_admin_menu.php
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

$parentId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if ($parentId <= 0) {
    fwrite(STDERR, "fanshub menu missing\n");
    exit(1);
}

$configPid = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/config' LIMIT 1")->fetchColumn();
if ($configPid <= 0) {
    $configPid = $parentId;
}

$listId = ensureMenu($pdo, $insert, $rule, $parentId, 'fanshub/telegramuser', 'Telegram用户', 'fa fa-paper-plane', 1, 48, $now, 'TG绑定用户列表');
ensureMenu($pdo, $insert, $rule, $listId, 'fanshub/telegramuser/index', '查看', 'fa fa-circle-o', 0, 0, $now);
ensureMenu($pdo, $insert, $rule, $listId, 'fanshub/telegramuser/del', '解绑', 'fa fa-circle-o', 0, 0, $now);

ensureMenu($pdo, $insert, $rule, $configPid, 'fanshub/config/telegram', 'Telegram配置', 'fa fa-circle-o', 0, 0, $now);

// 给超级管理员组挂上新节点
$group = $pdo->query("SELECT id,rules FROM {$prefix}auth_group WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($group) {
    $rules = array_filter(explode(',', (string)$group['rules']));
    $need = $pdo->query(
        "SELECT id FROM {$rule} WHERE name IN (
            'fanshub/telegramuser','fanshub/telegramuser/index','fanshub/telegramuser/del','fanshub/config/telegram'
        )"
    )->fetchAll(PDO::FETCH_COLUMN);
    $changed = false;
    foreach ($need as $rid) {
        $rid = (string)$rid;
        if (!in_array($rid, $rules, true) && $group['rules'] !== '*') {
            $rules[] = $rid;
            $changed = true;
        }
    }
    if ($changed) {
        $pdo->prepare("UPDATE {$prefix}auth_group SET rules=? WHERE id=1")->execute([implode(',', $rules)]);
        echo "OK   auth_group#1 rules updated\n";
    } else {
        echo "SKIP auth_group#1 (already * or has rules)\n";
    }
}

echo "DONE telegram admin menu\n";
