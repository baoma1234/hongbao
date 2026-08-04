<?php
/**
 * 私聊备注表 + 后台菜单
 * php scripts/install_chat_user_remark.php
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

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}chat_user_remarks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '备注设置者',
  `peer_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '被备注用户',
  `remark` varchar(32) NOT NULL DEFAULT '' COMMENT '备注名',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_peer` (`user_id`,`peer_user_id`),
  KEY `idx_peer` (`peer_user_id`),
  KEY `idx_updatetime` (`updatetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM私聊用户备注'");
echo "OK table chat_user_remarks\n";

$rule = $prefix . 'auth_rule';
$now = time();
$imPid = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_im' LIMIT 1")->fetchColumn();
if (!$imPid) {
    $imPid = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
}
if (!$imPid) {
    fwrite(STDERR, "fanshub menu missing\n");
    exit(1);
}
$insert = $pdo->prepare("INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

$menus = [
    ['fanshub/contactremark', '用户备注', 'fa fa-pencil-square-o', 1, [
        ['fanshub/contactremark/index', '查看'],
        ['fanshub/contactremark/del', '删除'],
    ]],
];

$allRuleIds = [];
foreach ($menus as $m) {
    $name = $m[0];
    $pid = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
    if (!$pid) {
        $insert->execute(['file', $imPid, $name, $m[1], $m[2], '', '', '', $m[3], null, $now, $now, 0, 'normal']);
        $pid = (int)$pdo->lastInsertId();
        echo "OK  {$name}\n";
    } else {
        echo "SKIP {$name}\n";
    }
    $allRuleIds[] = (int)$pid;
    foreach ($m[4] as $c) {
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
    }
}

echo "DONE 请清后台缓存；并重启 IM（含 HTTP API）\n";
