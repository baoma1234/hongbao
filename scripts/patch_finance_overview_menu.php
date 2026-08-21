<?php
/**
 * 财务管理 → 财务总览 菜单
 * php scripts/patch_finance_overview_menu.php
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
        $pdo->prepare("UPDATE {$rule} SET pid=?, title=?, icon=?, ismenu=?, weigh=?, status='normal', updatetime=? WHERE id=?")
            ->execute([(int)$pid, $title, $icon, (int)$ismenu, (int)$weigh, $now, (int)$id]);
        echo "UPD  menu {$name}\n";
        return (int)$id;
    }
    $insert->execute([
        'file', (int)$pid, $name, $title, $icon, '', '', $remark,
        (int)$ismenu, null, $now, $now, (int)$weigh, 'normal',
    ]);
    echo "OK   menu {$name}\n";
    return (int)$pdo->lastInsertId();
}

$parentId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='finance' LIMIT 1")->fetchColumn();
if ($parentId <= 0) {
    fwrite(STDERR, "finance menu missing\n");
    exit(1);
}

$listId = ensureMenu(
    $pdo, $insert, $rule, $parentId,
    'fanshub/financeoverview', '财务总览', 'fa fa-dashboard',
    1, 95, $now, '日/周/月充提订单、成功率与人数'
);
ensureMenu($pdo, $insert, $rule, $listId, 'fanshub/financeoverview/index', '查看', 'fa fa-circle-o', 0, 0, $now);

$group = $pdo->query("SELECT id,rules FROM {$prefix}auth_group WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($group) {
    $rules = array_filter(explode(',', (string)$group['rules']));
    $need = $pdo->query(
        "SELECT id FROM {$rule} WHERE name IN ('fanshub/financeoverview','fanshub/financeoverview/index')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $changed = false;
    foreach ($need as $rid) {
        $rid = (string)$rid;
        if ($group['rules'] !== '*' && !in_array($rid, $rules, true)) {
            $rules[] = $rid;
            $changed = true;
        }
    }
    if ($changed) {
        $pdo->prepare("UPDATE {$prefix}auth_group SET rules=? WHERE id=1")->execute([implode(',', $rules)]);
        echo "OK   auth_group#1 rules updated\n";
    } else {
        echo "SKIP auth_group#1\n";
    }
}

echo "DONE finance overview menu\n";
