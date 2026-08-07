<?php
/**
 * 后台菜单：红宝统计
 * php scripts/patch_hongbao_stat_menu.php
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
        (int)(isset($d['hostport']) ? $d['hostport'] : 3306),
        $d['database']
    ),
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pre = isset($d['prefix']) ? $d['prefix'] : 'fa_';
$rule = $pre . 'auth_rule';
$name = 'fanshub/hongbaostat';
$exists = $pdo->query("SELECT id FROM `{$rule}` WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
if ($exists) {
    echo "menu already exists id={$exists}\n";
    exit(0);
}
$imId = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub_im' LIMIT 1")->fetchColumn();
$parentId = $imId ?: (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub' LIMIT 1")->fetchColumn();
if ($parentId <= 0) {
    fwrite(STDERR, "parent menu missing\n");
    exit(1);
}
$now = time();
$st = $pdo->prepare(
    "INSERT INTO `{$rule}` (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)
     VALUES ('file',?,?,?,?,?,?,?,?,?,?,?,?,?)"
);
$st->execute([
    $parentId,
    $name,
    '红宝统计',
    'fa fa-bar-chart',
    '',
    '',
    '真人/机器人红包流水与充值加款',
    1,
    null,
    $now,
    $now,
    58,
    'normal',
]);
$id = (int)$pdo->lastInsertId();
echo "OK menu id={$id}\n";

// 默认管理员组赋权（若存在 rules 字段）
$group = $pre . 'auth_group';
try {
    $g = $pdo->query("SELECT id,rules FROM `{$group}` WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($g && isset($g['rules']) && $g['rules'] !== '*') {
        $rules = array_filter(array_map('intval', explode(',', (string)$g['rules'])));
        if (!in_array($id, $rules, true)) {
            $rules[] = $id;
            $pdo->prepare("UPDATE `{$group}` SET rules=? WHERE id=1")->execute([implode(',', $rules)]);
            echo "OK granted to group 1\n";
        }
    }
} catch (Throwable $e) {
    echo "skip group grant: " . $e->getMessage() . "\n";
}
