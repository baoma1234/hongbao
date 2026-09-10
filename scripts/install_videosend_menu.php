<?php
/**
 * 安装「视频发送」后台菜单（即时通讯下）
 * php scripts/install_videosend_menu.php
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
$rule = $prefix . 'auth_rule';
$now = time();

$imPid = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_im' LIMIT 1")->fetchColumn();
if (!$imPid) {
    $imPid = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
}
if (!$imPid) {
    fwrite(STDERR, "fanshub / fanshub_im menu missing\n");
    exit(1);
}

$insert = $pdo->prepare(
    "INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
);

$name = 'fanshub/videosend';
$pid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
$allRuleIds = [];
if (!$pid) {
    $insert->execute(['file', (int)$imPid, $name, '视频发送', 'fa fa-film', '', '', '', 1, null, $now, $now, 0, 'normal']);
    $pid = (int)$pdo->lastInsertId();
    echo "OK  {$name}\n";
} else {
    $pid = (int)$pid;
    echo "SKIP {$name}\n";
}
$allRuleIds[] = $pid;

$children = [
    ['fanshub/videosend/index', '查看'],
    ['fanshub/videosend/send', '发送'],
];
foreach ($children as $c) {
    $cid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($c[0]) . " LIMIT 1")->fetchColumn();
    if ($cid) {
        echo "SKIP {$c[0]}\n";
        $allRuleIds[] = (int)$cid;
        continue;
    }
    $insert->execute(['file', $pid, $c[0], $c[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
    $allRuleIds[] = (int)$pdo->lastInsertId();
    echo "OK   {$c[0]}\n";
}

$g = $pdo->query('SELECT rules FROM ' . $prefix . 'auth_group WHERE id=1')->fetch(PDO::FETCH_ASSOC);
if ($g && $allRuleIds) {
    $have = array_flip(array_filter(explode(',', (string)$g['rules'])));
    $missing = [];
    foreach ($allRuleIds as $rid) {
        if ($rid > 0 && !isset($have[$rid])) {
            $missing[] = $rid;
        }
    }
    if ($missing) {
        $new = trim((string)$g['rules'] . ',' . implode(',', $missing), ',');
        $pdo->prepare('UPDATE ' . $prefix . 'auth_group SET rules=? WHERE id=1')->execute([$new]);
        echo 'GRANTED group#1 +' . count($missing) . " rules\n";
    } else {
        echo "group#1 already has rules\n";
    }
}

echo "DONE — 请执行 php scripts/clear_admin_menu_cache.php 或后台清缓存后刷新侧栏\n";
