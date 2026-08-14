<?php
$e = parse_ini_file(dirname(__DIR__) . '/.env', true);
$d = $e['database'];
$pdo = new PDO(
    'mysql:host=' . $d['hostname'] . ';dbname=' . $d['database'] . ';charset=utf8mb4',
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$p = $d['prefix'] ?? 'fa_';
$name = 'fanshub/robotaccount/edit';
$st = $pdo->prepare("SELECT id FROM `{$p}auth_rule` WHERE `name`=? LIMIT 1");
$st->execute([$name]);
if ($st->fetch()) {
    echo "SKIP auth rule exists\n";
    exit(0);
}
$now = time();
$sql = "INSERT INTO `{$p}auth_rule`
    (`type`,`pid`,`name`,`title`,`icon`,`condition`,`remark`,`ismenu`,`menutype`,`extend`,`py`,`pinyin`,`createtime`,`updatetime`,`weigh`,`status`)
    VALUES (1,349,?,?, '', '', '',0,NULL,'','bj','bianji',?,?,0,'normal')";
$pdo->prepare($sql)->execute([$name, '编辑', $now, $now]);
$id = (int)$pdo->lastInsertId();
echo "OK auth_rule id=$id\n";
$g = $pdo->query("SELECT id,rules FROM `{$p}auth_group` WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if ($g) {
    $rules = trim((string)$g['rules']);
    if ($rules !== '*') {
        $arr = array_filter(array_map('intval', explode(',', $rules)));
        if (!in_array($id, $arr, true)) {
            $arr[] = $id;
            $pdo->prepare("UPDATE `{$p}auth_group` SET rules=? WHERE id=1")->execute([implode(',', $arr)]);
            echo "OK appended to group 1\n";
        }
    } else {
        echo "SKIP group1 has *\n";
    }
}
echo "DONE\n";
