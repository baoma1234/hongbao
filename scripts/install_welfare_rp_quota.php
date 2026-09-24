<?php
/**
 * 安装福利群红包每日配额：表 + 菜单
 * php scripts/install_welfare_rp_quota.php
 */
$root = dirname(__DIR__);
$envFile = $root . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, ".env missing\n");
    exit(1);
}
$env = parse_ini_file($envFile, true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};port=" . ($d['hostport'] ?? 3306) . ";dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$sql = file_get_contents($root . '/sql/fans_welfare_rp_daily.sql');
$pdo->exec($sql);
echo "OK table fa_fans_welfare_rp_daily\n";

$rule = 'fa_auth_rule';
$now = time();

$rain = $pdo->query("SELECT id, pid, weigh FROM {$rule} WHERE name='fanshub/redpacketrain' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$pid = $rain ? (int)$rain['pid'] : 0;
$weigh = $rain ? max(1, (int)$rain['weigh'] - 1) : 56;
if ($pid <= 0) {
    $pid = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_play' LIMIT 1")->fetchColumn();
}
if ($pid <= 0) {
    $pid = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_im' LIMIT 1")->fetchColumn();
}
if ($pid <= 0) {
    fwrite(STDERR, "parent menu missing\n");
    exit(1);
}

$insert = $pdo->prepare(
    "INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)
     VALUES ('file',?,?,?,?, '', '', ?, ?, 'addtabs', ?, ?, ?, 'normal')"
);

function ensureMenu(PDO $pdo, $insert, $rule, $pid, $name, $title, $icon, $ismenu, $weigh, $now, $remark = '')
{
    $id = (int)$pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
    if ($id > 0) {
        $pdo->prepare("UPDATE {$rule} SET pid=?, title=?, icon=?, ismenu=?, weigh=?, status='normal', updatetime=?, remark=? WHERE id=?")
            ->execute([(int)$pid, $title, $icon, (int)$ismenu, (int)$weigh, $now, $remark, $id]);
        echo "UPD  {$name} (#{$id})\n";
        return $id;
    }
    $insert->execute([(int)$pid, $name, $title, $icon, $remark, (int)$ismenu, $now, $now, (int)$weigh]);
    $id = (int)$pdo->lastInsertId();
    echo "ADD  {$name} (#{$id})\n";
    return $id;
}

$menuId = ensureMenu(
    $pdo, $insert, $rule, $pid,
    'fanshub/welfareclaim', '福利群领红包', 'fa fa-gift', 1, $weigh, $now,
    '群80每日领取上限与娱乐加成；独立计数表'
);
foreach ([
    'index' => '查看',
    'add' => '添加',
    'edit' => '编辑',
    'del' => '删除',
    'multi' => '批量',
] as $act => $title) {
    ensureMenu($pdo, $insert, $rule, $menuId, 'fanshub/welfareclaim/' . $act, $title, 'fa fa-circle-o', 0, 0, $now);
}

$ids = $pdo->query("SELECT id FROM {$rule} WHERE name LIKE 'fanshub/welfareclaim%'")->fetchAll(PDO::FETCH_COLUMN);
$seed = $rain ? [(string)$rain['id']] : [];
$groups = $pdo->query("SELECT id, rules FROM fa_auth_group")->fetchAll(PDO::FETCH_ASSOC);
foreach ($groups as $g) {
    $rules = trim((string)$g['rules']);
    if ($rules === '' || $rules === '*') {
        continue;
    }
    $have = array_filter(explode(',', $rules));
    if ($seed && !array_intersect($seed, $have)) {
        continue;
    }
    $changed = false;
    foreach ($ids as $rid) {
        if (!in_array((string)$rid, $have, true)) {
            $have[] = (string)$rid;
            $changed = true;
        }
    }
    if ($changed) {
        $pdo->prepare("UPDATE fa_auth_group SET rules=? WHERE id=?")->execute([implode(',', $have), $g['id']]);
        echo "OK sync group {$g['id']}\n";
    }
}

echo "DONE. Restart IM after deploy.\n";
