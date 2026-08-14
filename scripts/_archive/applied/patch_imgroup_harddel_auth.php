<?php
$root = dirname(__DIR__);
$e = parse_ini_file($root . '/.env', true);
$d = $e['database'];
$pdo = new PDO(
    'mysql:host=' . $d['hostname'] . ';dbname=' . $d['database'] . ';charset=utf8mb4',
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$p = $d['prefix'] ?? 'fa_';
$name = 'fanshub/imgroup/harddel';
$st = $pdo->prepare("SELECT id FROM {$p}auth_rule WHERE name=? LIMIT 1");
$st->execute([$name]);
$exist = $st->fetchColumn();
if ($exist) {
    echo "auth rule exists id={$exist}\n";
    $ruleId = (int)$exist;
} else {
    $now = time();
    $sql = "INSERT INTO {$p}auth_rule
        (`type`,`pid`,`name`,`title`,`icon`,`url`,`condition`,`remark`,`ismenu`,`menutype`,`extend`,`py`,`pinyin`,`createtime`,`updatetime`,`weigh`,`status`)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $pdo->prepare($sql)->execute([
        'file', 215, $name, '硬删除', 'fa fa-circle-o', '', '', '物理删除群及关联数据', 0, null, '', '', '', $now, $now, 0, 'normal'
    ]);
    $ruleId = (int)$pdo->lastInsertId();
    echo "inserted auth rule id={$ruleId}\n";
}

$groups = $pdo->query("SELECT id, rules FROM {$p}auth_group")->fetchAll(PDO::FETCH_ASSOC);
foreach ($groups as $g) {
    $rules = trim((string)$g['rules']);
    if ($rules === '' || $rules === '*') {
        continue;
    }
    $arr = array_filter(array_map('intval', explode(',', $rules)));
    if (!in_array(219, $arr, true)) {
        continue;
    }
    if (in_array($ruleId, $arr, true)) {
        echo "group {$g['id']} already has harddel\n";
        continue;
    }
    $arr[] = $ruleId;
    sort($arr);
    $pdo->prepare("UPDATE {$p}auth_group SET rules=? WHERE id=?")->execute([implode(',', $arr), $g['id']]);
    echo "group {$g['id']} granted harddel\n";
}
echo "done\n";
