<?php
/**
 * 即时通讯菜单：补「查看聊天记录」（可删除）
 * php scripts/install_imhistory_menu.php
 */
$root = dirname(__DIR__);
$e = parse_ini_file($root . '/.env', true);
$d = $e['database'] ?? [];
$pdo = new PDO(
    'mysql:host=' . ($d['hostname'] ?? '127.0.0.1') . ';dbname=' . ($d['database'] ?? '') . ';charset=utf8mb4',
    $d['username'] ?? 'root',
    $d['password'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$rule = ($d['prefix'] ?? 'fa_') . 'auth_rule';
$group = ($d['prefix'] ?? 'fa_') . 'auth_group';
$now = time();

function ensureRule(PDO $pdo, $table, $pid, $name, $title, $icon, $ismenu, $weigh, $now)
{
    $stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE name=? LIMIT 1");
    $stmt->execute([$name]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) {
        $pdo->prepare("UPDATE `{$table}` SET pid=?, title=?, icon=?, ismenu=?, weigh=?, status='normal', updatetime=? WHERE id=?")
            ->execute([$pid, $title, $icon, $ismenu, $weigh, $now, $id]);
        echo "update {$name} (#{$id})\n";
        return $id;
    }
    $pdo->prepare("INSERT INTO `{$table}` (type,pid,name,title,icon,condition,remark,ismenu,menutype,extend,py,pinyin,createtime,updatetime,weigh,status)
        VALUES ('file',?,?,?,?, '', '', ?, NULL, '', '', '', ?, ?, ?, 'normal')")
        ->execute([$pid, $name, $title, $icon, $ismenu, $now, $now, $weigh]);
    $id = (int)$pdo->lastInsertId();
    echo "insert {$name} (#{$id})\n";
    return $id;
}

$imPid = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub_im' LIMIT 1")->fetchColumn();
if ($imPid <= 0) {
    $imPid = ensureRule($pdo, $rule, 0, 'fanshub_im', '即时通讯', 'fa fa-comments', 1, 88, $now);
}

$imagentId = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub/imagent' LIMIT 1")->fetchColumn();
if ($imagentId <= 0) {
    $imagentId = ensureRule($pdo, $rule, $imPid, 'fanshub/imagent', 'IM代聊', 'fa fa-headset', 1, 90, $now);
}

$historyMenuId = ensureRule(
    $pdo,
    $rule,
    $imPid,
    'fanshub/imagent/messages',
    '查看聊天记录',
    'fa fa-history',
    1,
    85,
    $now
);

ensureRule($pdo, $rule, $imagentId, 'fanshub/imagent/del', '删除消息', 'fa fa-circle-o', 0, 0, $now);
ensureRule($pdo, $rule, $imagentId, 'fanshub/imagent/restore', '恢复消息', 'fa fa-circle-o', 0, 0, $now);
ensureRule($pdo, $rule, $imagentId, 'fanshub/imagent/edit', '编辑消息', 'fa fa-circle-o', 0, 0, $now);
ensureRule($pdo, $rule, $imagentId, 'fanshub/imagent/history', '会话历史', 'fa fa-circle-o', 0, 0, $now);

$groups = $pdo->query("SELECT id, rules FROM `{$group}`")->fetchAll(PDO::FETCH_ASSOC);
$needNames = [
    'fanshub_im',
    'fanshub/imagent',
    'fanshub/imagent/messages',
    'fanshub/imagent/del',
    'fanshub/imagent/restore',
    'fanshub/imagent/edit',
];
$needIds = [];
foreach ($needNames as $n) {
    $rid = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name=" . $pdo->quote($n) . " LIMIT 1")->fetchColumn();
    if ($rid > 0) {
        $needIds[] = $rid;
    }
}
foreach ($groups as $g) {
    $rules = trim((string)$g['rules']);
    if ($rules === '' || $rules === '*') {
        continue;
    }
    $ids = array_filter(array_map('intval', explode(',', $rules)));
    if (!in_array($imagentId, $ids, true) && !in_array($imPid, $ids, true)) {
        continue;
    }
    $changed = false;
    foreach ($needIds as $rid) {
        if (!in_array($rid, $ids, true)) {
            $ids[] = $rid;
            $changed = true;
        }
    }
    if ($changed) {
        sort($ids);
        $pdo->prepare("UPDATE `{$group}` SET rules=? WHERE id=?")->execute([implode(',', $ids), $g['id']]);
        echo "group {$g['id']} granted chat history\n";
    }
}

echo "OK menu id={$historyMenuId} under im pid={$imPid}\n";
