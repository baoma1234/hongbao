<?php
/**
 * 为 IM 聊天台补权限节点
 * php scripts/patch_imagent_chat_perms.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$rule = 'fa_auth_rule';
$now = time();
$pid = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/imagent' LIMIT 1")->fetchColumn();
if (!$pid) {
    fwrite(STDERR, "fanshub/imagent menu missing\n");
    exit(1);
}
$insert = $pdo->prepare("INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$children = [
    ['fanshub/imagent/conversations', '会话列表'],
    ['fanshub/imagent/history', '会话历史'],
    ['fanshub/imagent/messages', '全部消息'],
    ['fanshub/imagent/send', '发送消息'],
    ['fanshub/imagent/edit', '编辑消息'],
    ['fanshub/imagent/del', '删除消息'],
    ['fanshub/imagent/restore', '恢复消息'],
    ['fanshub/imagent/agents', '托管账号列表'],
];
foreach ($children as $c) {
    $exists = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($c[0]) . " LIMIT 1")->fetchColumn();
    if ($exists) {
        echo "SKIP  {$c[0]}\n";
        continue;
    }
    $insert->execute(['file', $pid, $c[0], $c[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
    echo "OK    {$c[0]}\n";
}

// 附到超级组（非 * 时）
$names = array_column($children, 0);
$ids = [];
foreach ($names as $n) {
    $id = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($n) . " LIMIT 1")->fetchColumn();
    if ($id) {
        $ids[] = (int)$id;
    }
}
$rules = (string)$pdo->query("SELECT rules FROM fa_auth_group WHERE id=1")->fetchColumn();
if ($rules === '*') {
    echo "OK    auth_group=1 already *\n";
} else {
    $have = array_filter(array_map('intval', explode(',', $rules)));
    $add = 0;
    foreach ($ids as $id) {
        if (!in_array($id, $have, true)) {
            $have[] = $id;
            $add++;
        }
    }
    if ($add) {
        sort($have);
        $pdo->prepare('UPDATE fa_auth_group SET rules=? WHERE id=1')->execute([implode(',', $have)]);
        echo "OK    auth_group=1 +{$add} rules\n";
    } else {
        echo "SKIP  auth_group=1 already has rules\n";
    }
}
echo "DONE  请清后台缓存后打开 IM代聊\n";
