<?php
/**
 * 注册 fanshub/account/ban 权限节点，并授权给已有「用户账户」权限的角色组
 * 用法: php scripts/patch_account_ban_rule.php
 */
$root = dirname(__DIR__);
$ini = parse_ini_file($root . '/.env', true);
if (empty($ini['database'])) {
    fwrite(STDERR, "missing .env database\n");
    exit(1);
}
$d = $ini['database'];
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $d['hostname'],
        (int)($d['hostport'] ?? 3306),
        $d['database']
    ),
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pre = $d['prefix'] ?? 'fa_';
$name = 'fanshub/account/ban';
$row = $pdo->query("SELECT id,pid FROM `{$pre}auth_rule` WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$parent = $pdo->query("SELECT id FROM `{$pre}auth_rule` WHERE name='fanshub/account' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$parent) {
    fwrite(STDERR, "parent fanshub/account missing\n");
    exit(1);
}
$pid = (int)$parent['id'];
$now = time();
if ($row) {
    $ruleId = (int)$row['id'];
    echo "SKIP rule exists id={$ruleId}\n";
} else {
    $stmt = $pdo->prepare(
        "INSERT INTO `{$pre}auth_rule`
        (`type`,`pid`,`name`,`title`,`icon`,`url`,`condition`,`remark`,`ismenu`,`menutype`,`extend`,`py`,`pinyin`,`createtime`,`updatetime`,`weigh`,`status`)
        VALUES ('file',?,?,?,'fa fa-lock','','','封禁/解封登录并踢下线',0,NULL,'','','',?,?,0,'normal')"
    );
    $stmt->execute([$pid, $name, '封禁登录', $now, $now]);
    $ruleId = (int)$pdo->lastInsertId();
    echo "OK inserted rule id={$ruleId}\n";
}

// 给拥有 fanshub/account 或 chatforbid 的角色组补上 ban
$groups = $pdo->query("SELECT id,rules FROM `{$pre}auth_group`")->fetchAll(PDO::FETCH_ASSOC);
$chatforbidId = 0;
$cf = $pdo->query("SELECT id FROM `{$pre}auth_rule` WHERE name='fanshub/account/chatforbid' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($cf) {
    $chatforbidId = (int)$cf['id'];
}
$updated = 0;
foreach ($groups as $g) {
    $rules = trim((string)$g['rules']);
    if ($rules === '' || $rules === '*') {
        continue;
    }
    $ids = array_filter(array_map('intval', explode(',', $rules)));
    if (!$ids) {
        continue;
    }
    $hasAccount = in_array($pid, $ids, true) || ($chatforbidId && in_array($chatforbidId, $ids, true));
    if (!$hasAccount) {
        continue;
    }
    if (in_array($ruleId, $ids, true)) {
        continue;
    }
    $ids[] = $ruleId;
    $ids = array_values(array_unique($ids));
    sort($ids);
    $pdo->prepare("UPDATE `{$pre}auth_group` SET rules=? WHERE id=?")->execute([implode(',', $ids), (int)$g['id']]);
    $updated++;
}
echo "OK groups updated={$updated}\n";
