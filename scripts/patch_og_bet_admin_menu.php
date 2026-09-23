<?php
/**
 * 后台菜单：三方游戏 → OG投注记录
 * php scripts/patch_og_bet_admin_menu.php
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
        $pdo->prepare("UPDATE {$rule} SET title=?, icon=?, pid=?, ismenu=?, weigh=?, updatetime=?, remark=? WHERE id=?")
            ->execute([$title, $icon, (int)$pid, (int)$ismenu, (int)$weigh, $now, $remark, (int)$id]);
        echo "OK   update menu {$name}\n";
        return (int)$id;
    }
    $insert->execute([
        'file', (int)$pid, $name, $title, $icon, '', '', $remark,
        (int)$ismenu, null, $now, $now, (int)$weigh, 'normal',
    ]);
    echo "OK   menu {$name}\n";
    return (int)$pdo->lastInsertId();
}

$topId = ensureMenu(
    $pdo, $insert, $rule, 0,
    'fanshub_thirdgame', '三方游戏', 'fa fa-globe', 1, 35, $now, '第三方游戏商户与对接'
);

$betId = ensureMenu(
    $pdo, $insert, $rule, $topId,
    'fanshub/ogbet', 'OG投注记录', 'fa fa-list-alt', 1, 20, $now, 'OG视讯投注记录查询'
);
ensureMenu($pdo, $insert, $rule, $betId, 'fanshub/ogbet/index', '查看', 'fa fa-circle-o', 0, 0, $now);
ensureMenu($pdo, $insert, $rule, $betId, 'fanshub/ogbet/detail', '详情', 'fa fa-circle-o', 0, 0, $now);
ensureMenu($pdo, $insert, $rule, $betId, 'fanshub/ogbet/syncnow', '立即同步', 'fa fa-circle-o', 0, 0, $now);

$group = $pdo->query("SELECT id,rules FROM {$prefix}auth_group WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($group) {
    $rules = array_filter(explode(',', (string)$group['rules']));
    $need = $pdo->query(
        "SELECT id FROM {$rule} WHERE name IN (
            'fanshub_thirdgame',
            'fanshub/ogbet','fanshub/ogbet/index','fanshub/ogbet/detail','fanshub/ogbet/syncnow'
        )"
    )->fetchAll(PDO::FETCH_COLUMN);
    $changed = false;
    foreach ($need as $rid) {
        $rid = (string)$rid;
        if ($group['rules'] === '*') {
            break;
        }
        if (!in_array($rid, $rules, true)) {
            $rules[] = $rid;
            $changed = true;
        }
    }
    if ($changed) {
        $pdo->prepare("UPDATE {$prefix}auth_group SET rules=? WHERE id=1")
            ->execute([implode(',', $rules)]);
        echo "OK   auth_group#1 rules updated\n";
    } else {
        echo "OK   auth_group#1 already has rules\n";
    }
}
echo "DONE OG bet admin menu\n";
