<?php
/**
 * 福利大厅 v8：运营总览/排行榜侧栏 + 密令导出 + 登录日志模块
 * php scripts/patch_fanshub_v8.php
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

$updates = [
    ['fanshub/index', '运营总览', 'fa fa-line-chart', 11],
    ['fanshub/invite/leaderboard', '邀请排行榜', 'fa fa-trophy', 1],
];
foreach ($updates as $item) {
    list($name, $title, $icon, $weigh) = $item;
    $stmt = $pdo->prepare("UPDATE {$rule} SET ismenu=1, title=?, icon=?, menutype='addtabs', weigh=? WHERE name=?");
    $stmt->execute([$title, $icon, $weigh, $name]);
    echo ($stmt->rowCount() ? 'OK' : 'SKIP') . "    menu {$name}\n";
}

$perms = [
    ['fanshub/secret', 'fanshub/secret/export', '导出'],
    ['fanshub/loginlog', 'fanshub/loginlog', '登录日志', 1, 'fa fa-sign-in', 3],
    ['fanshub/loginlog', 'fanshub/loginlog/index', '查看'],
    ['fanshub/loginlog', 'fanshub/loginlog/export', '导出'],
];

foreach ($perms as $item) {
    $parentName = $item[0];
    $permName = $item[1];
    $title = $item[2];
    $isMenu = $item[3] ?? 0;
    $icon = $item[4] ?? 'fa fa-circle-o';
    $weigh = $item[5] ?? 0;

    $exists = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($permName) . " LIMIT 1")->fetchColumn();
    if ($exists) {
        echo "SKIP  {$permName}\n";
        continue;
    }

    $pid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($parentName) . " LIMIT 1")->fetchColumn();
    if (!$pid && $permName !== 'fanshub/loginlog') {
        echo "WARN  parent {$parentName} missing for {$permName}\n";
        continue;
    }
    if ($permName === 'fanshub/loginlog') {
        $rootId = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
        if (!$rootId) {
            echo "WARN  fanshub root missing\n";
            continue;
        }
        $pid = $rootId;
    }

    $insert->execute([
        'file', $pid, $permName, $title, $icon, '', '', '',
        $isMenu ? 1 : 0, $isMenu ? 'addtabs' : null, $now, $now, $weigh, 'normal',
    ]);
    echo "OK    {$permName}\n";

    if ($isMenu) {
        $menuId = (int)$pdo->lastInsertId();
        if ($permName === 'fanshub/loginlog') {
            $pid = $menuId;
        }
    }
}

echo "Done.\n";
