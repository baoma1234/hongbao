<?php
/**
 * 安装「机器人账户」菜单（会员运营下）
 * php scripts/install_robotaccount_menu.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $d['hostname'] ?? '127.0.0.1',
        (int)($d['hostport'] ?? 3306),
        $d['database'] ?? ''
    ),
    $d['username'] ?? 'root',
    $d['password'] ?? '',
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$rule = ($d['prefix'] ?? 'fa_') . 'auth_rule';
$group = ($d['prefix'] ?? 'fa_') . 'auth_group';
$now = time();

$parentId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_member' LIMIT 1")->fetchColumn();
if ($parentId <= 0) {
    $parentId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
}
if ($parentId <= 0) {
    fwrite(STDERR, "fanshub_member / fanshub menu missing\n");
    exit(1);
}
echo "parent id={$parentId}\n";

$insert = $pdo->prepare(
    "INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)"
    . " VALUES ('file',?,?,?,?,?,?,?,?,?,?,?,?,?)"
);

$menu = [
    'name'  => 'fanshub/robotaccount',
    'title' => '机器人账户',
    'icon'  => 'fa fa-android',
    'weigh' => 76,
    'children' => [
        ['fanshub/robotaccount/index', '查看'],
        ['fanshub/robotaccount/adjust', '调账'],
        ['fanshub/robotaccount/batchadjust', '批量加余额'],
        ['fanshub/robotaccount/seed', '批量注册'],
    ],
];

$menuId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($menu['name']) . " LIMIT 1")->fetchColumn();
if ($menuId <= 0) {
    $insert->execute([
        $parentId, $menu['name'], $menu['title'], $menu['icon'], '', '', $menu['title'],
        1, 'addtabs', $now, $now, $menu['weigh'], 'normal',
    ]);
    $menuId = (int)$pdo->lastInsertId();
    echo "OK  {$menu['name']} id={$menuId}\n";
} else {
    $pdo->prepare("UPDATE {$rule} SET pid=?, title=?, icon=?, ismenu=1, menutype='addtabs', status='normal', weigh=?, updatetime=? WHERE id=?")
        ->execute([$parentId, $menu['title'], $menu['icon'], $menu['weigh'], $now, $menuId]);
    echo "FIX {$menu['name']} id={$menuId}\n";
}

$allRuleIds = [$menuId];
foreach ($menu['children'] as $c) {
    $exists = (int)$pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($c[0]) . " LIMIT 1")->fetchColumn();
    if ($exists > 0) {
        $allRuleIds[] = $exists;
        echo "SKIP {$c[0]}\n";
        continue;
    }
    $insert->execute([$menuId, $c[0], $c[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
    $cid = (int)$pdo->lastInsertId();
    $allRuleIds[] = $cid;
    echo "OK   {$c[0]} id={$cid}\n";
}

$extra = $pdo->query("SELECT id FROM {$rule} WHERE name LIKE 'fanshub/robotaccount%'")->fetchAll(PDO::FETCH_COLUMN);
$allRuleIds = array_values(array_unique(array_merge($allRuleIds, array_map('intval', $extra))));

foreach ([1] as $gid) {
    $g = $pdo->query("SELECT rules FROM {$group} WHERE id=" . (int)$gid)->fetch(PDO::FETCH_ASSOC);
    if (!$g || !$allRuleIds) {
        continue;
    }
    $have = array_flip(array_filter(explode(',', (string)$g['rules'])));
    $missing = [];
    foreach ($allRuleIds as $rid) {
        if ($rid > 0 && !isset($have[$rid])) {
            $missing[] = $rid;
        }
    }
    if ($missing) {
        $new = trim((string)$g['rules'] . ',' . implode(',', $missing), ',');
        $pdo->prepare("UPDATE {$group} SET rules=? WHERE id=?")->execute([$new, $gid]);
        echo "GRANTED group#{$gid} +" . count($missing) . " rules\n";
    }
}

$cacheDir = $root . '/runtime/cache';
if (is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/*/*.php') ?: [] as $f) {
        @unlink($f);
    }
}
echo "DONE 请刷新后台（Ctrl+F5）。菜单：会员运营 → 机器人账户\n";
