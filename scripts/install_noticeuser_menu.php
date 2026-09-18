<?php
/**
 * 安装「用户发帖」后台菜单（彩金白嫖 / 红宝•海外圈内事）
 * php scripts/install_noticeuser_menu.php
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

// 公告动态备注说明拆分
$pdo->exec("UPDATE {$rule} SET remark='仅最新发布/推广赚钱', title='公告动态', updatetime={$now} WHERE name='fanshub/notice'");

$insert = $pdo->prepare("INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$name = 'fanshub/noticeuser';
$pid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
if (!$pid) {
    // weigh 略低于公告动态(12)，紧挨其下
    $insert->execute(['file', $fansPid, $name, '用户发帖', 'fa fa-comments', '', '', '彩金白嫖/海外圈内事/用户发帖；待审优先', 1, null, $now, $now, 11, 'normal']);
    $pid = $pdo->lastInsertId();
    echo "OK  {$name}\n";
} else {
    $pdo->prepare("UPDATE {$rule} SET title=?, remark=?, icon=?, updatetime=? WHERE id=?")
        ->execute(['用户发帖', '彩金白嫖/海外圈内事/用户发帖；待审优先', 'fa fa-comments', $now, $pid]);
    echo "SKIP {$name} (updated title)\n";
}
$children = [
    ['fanshub/noticeuser/index', '查看'],
    ['fanshub/noticeuser/add', '添加'],
    ['fanshub/noticeuser/edit', '编辑'],
    ['fanshub/noticeuser/del', '删除'],
    ['fanshub/noticeuser/multi', '批量更新'],
];
foreach ($children as $c) {
    if ($pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($c[0]) . " LIMIT 1")->fetchColumn()) {
        echo "SKIP {$c[0]}\n";
        continue;
    }
    $insert->execute(['file', $pid, $c[0], $c[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
    echo "OK   {$c[0]}\n";
}
$ruleIds = $pdo->query("SELECT id FROM {$rule} WHERE name LIKE 'fanshub/noticeuser%'")->fetchAll(PDO::FETCH_COLUMN);
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
