<?php
/**
 * 执行通讯模块建表
 * php scripts/install_chat_im.php
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
$sql = file_get_contents($root . '/sql/chat_im.sql');
$pdo->exec($sql);
echo "OK  chat_im tables\n";
foreach ($pdo->query("SHOW TABLES LIKE 'fa_chat_%'") as $row) {
    echo "  " . $row[0] . "\n";
}

// 菜单
$rule = 'fa_auth_rule';
$now = time();
$fansPid = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if (!$fansPid) {
    echo "WARN fanshub menu missing, skip auth_rule\n";
    exit(0);
}
$insert = $pdo->prepare("INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$menus = [
    ['file', $fansPid, 'fanshub/imagent', 'IM代聊', 'fa fa-comments', '', '', '', 1, null, $now, $now, 0, 'normal'],
];
$pid = null;
foreach ($menus as $m) {
    $name = $m[2];
    $exists = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
    if ($exists) {
        echo "SKIP  {$name}\n";
        $pid = $exists;
        continue;
    }
    $insert->execute($m);
    $pid = $pdo->lastInsertId();
    echo "OK    {$name}\n";
}
if ($pid) {
    $children = [
        ['fanshub/imagent/index', '查看'],
        ['fanshub/imagent/add', '添加托管账号'],
        ['fanshub/imagent/sendprivate', '代发私聊'],
        ['fanshub/imagent/sendgroup', '代发群聊'],
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
        if ($pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($c[0]) . " LIMIT 1")->fetchColumn()) {
            echo "SKIP  {$c[0]}\n";
            continue;
        }
        $insert->execute(['file', $pid, $c[0], $c[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
        echo "OK    {$c[0]}\n";
    }
}
echo "DONE  请在角色组勾选 IM代聊 权限并清缓存\n";
