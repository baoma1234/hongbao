<?php
/**
 * 修复后台 Admin 组权限：恢复为全部权限 *
 * php scripts/fix_admin_group_rules.php
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
        (int)($d['hostport'] ?: 3306),
        $d['database']
    ),
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pre = $d['prefix'] ?? 'fa_';
$table = $pre . 'auth_group';

$row = $pdo->query("SELECT id,name,rules FROM `{$table}` WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    fwrite(STDERR, "auth_group id=1 missing\n");
    exit(1);
}

$before = (string)$row['rules'];
$beforeLen = strlen($before);
$beforeCount = $before === '*' ? 'ALL' : count(array_filter(explode(',', $before)));

if ($before === '*') {
    echo "OK already rules=*\n";
    exit(0);
}

// 备份原值到备注，便于回滚核对
$bak = $pre . 'auth_group_rules_bak';
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `{$bak}` (
      id int unsigned NOT NULL AUTO_INCREMENT,
      group_id int unsigned NOT NULL,
      rules_old mediumtext,
      createtime int unsigned NOT NULL DEFAULT 0,
      remark varchar(255) NOT NULL DEFAULT '',
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$st = $pdo->prepare("INSERT INTO `{$bak}` (group_id, rules_old, createtime, remark) VALUES (1, ?, ?, ?)");
$st->execute([$before, time(), 'fix_admin_group_rules before restore *']);

$pdo->prepare("UPDATE `{$table}` SET rules='*' WHERE id=1")->execute();
$after = $pdo->query("SELECT rules FROM `{$table}` WHERE id=1")->fetchColumn();

echo "BEFORE name={$row['name']} len={$beforeLen} count={$beforeCount}\n";
echo "AFTER  rules={$after}\n";
echo "OK Admin group restored to full permissions (*)\n";
echo "Note: re-login admin backend if menus still empty (clear session).\n";
