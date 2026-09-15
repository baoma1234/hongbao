<?php
/**
 * 安装社区帖子主题后台菜单
 * php scripts/install_notice_theme_menu.php
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
// Prefer sibling under same parent as notice
$noticePid = $pdo->query("SELECT pid FROM {$rule} WHERE name='fanshub/notice' LIMIT 1")->fetchColumn();
$parentPid = $noticePid ?: $fansPid;

$insert = $pdo->prepare("INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$name = 'fanshub/noticetheme';
$pid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
if (!$pid) {
    $insert->execute(['file', $parentPid, $name, '帖子主题', 'fa fa-tags', '', '', '社区发帖主题标签', 1, null, $now, $now, 11, 'normal']);
    $pid = $pdo->lastInsertId();
    echo "OK  {$name}\n";
} else {
    echo "SKIP {$name}\n";
}
$children = [
    ['fanshub/noticetheme/index', '查看'],
    ['fanshub/noticetheme/add', '添加'],
    ['fanshub/noticetheme/edit', '编辑'],
    ['fanshub/noticetheme/del', '删除'],
    ['fanshub/noticetheme/multi', '批量更新'],
];
foreach ($children as $c) {
    if ($pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($c[0]) . " LIMIT 1")->fetchColumn()) {
        echo "SKIP {$c[0]}\n";
        continue;
    }
    $insert->execute(['file', $pid, $c[0], $c[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
    echo "OK   {$c[0]}\n";
}
$ruleIds = $pdo->query("SELECT id FROM {$rule} WHERE name LIKE 'fanshub/noticetheme%'")->fetchAll(PDO::FETCH_COLUMN);
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
echo "DONE\n";
