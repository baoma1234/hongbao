<?php
/**
 * 注册 fanshub/account/ogbalance 权限节点，并授权给已有「用户账户」权限的角色组
 * 用法: php scripts/patch_account_ogbalance_rule.php
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
$name = 'fanshub/account/ogbalance';
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
        VALUES ('file',?,?,?,'fa fa-money','','','查询用户 OG 视讯筹码余额',0,NULL,'','','',?,?,0,'normal')"
    );
    $stmt->execute([$pid, $name, 'OG余额', $now, $now]);
    $ruleId = (int)$pdo->lastInsertId();
    echo "OK inserted rule id={$ruleId}\n";
}

$groups = $pdo->query("SELECT id,rules FROM `{$pre}auth_group`")->fetchAll(PDO::FETCH_ASSOC);
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
    if (!in_array($pid, $ids, true)) {
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
