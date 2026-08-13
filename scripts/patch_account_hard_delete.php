<?php
/**
 * 用户账户真删除权限：fanshub/account/del
 * php scripts/patch_account_hard_delete.php
 */
$root = dirname(__DIR__);
$env = is_file($root . '/.env') ? parse_ini_file($root . '/.env', true) : [];
$d = $env['database'] ?? [];
$host = $d['hostname'] ?? '127.0.0.1';
$port = $d['hostport'] ?? '3306';
$db = $d['database'] ?? '';
$user = $d['username'] ?? 'root';
$pass = $d['password'] ?? '';
$prefix = $d['prefix'] ?? 'fa_';

if ($db === '') {
    fwrite(STDERR, "database not configured\n");
    exit(1);
}

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$rule = $prefix . 'auth_rule';
$now = time();
$accountId = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub/account' LIMIT 1")->fetchColumn();
if ($accountId <= 0) {
    fwrite(STDERR, "fanshub/account menu missing\n");
    exit(1);
}

$name = 'fanshub/account/del';
$exists = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
if ($exists > 0) {
    echo "SKIP perm {$name}\n";
    $permId = $exists;
} else {
    $stmt = $pdo->prepare("INSERT INTO `{$rule}`
        (`type`,`pid`,`name`,`title`,`icon`,`url`,`condition`,`remark`,`ismenu`,`menutype`,`createtime`,`updatetime`,`weigh`,`status`)
        VALUES ('file',?,?,?,'fa fa-trash','','','真删除用户账户（不可恢复）',0,NULL,?,?,0,'normal')");
    $stmt->execute([$accountId, $name, '删除', $now, $now]);
    $permId = (int)$pdo->lastInsertId();
    echo "ADD  perm {$name} (#{$permId})\n";
}

$groups = $pdo->query("SELECT id, rules FROM `{$prefix}auth_group`")->fetchAll(PDO::FETCH_ASSOC);
foreach ($groups as $g) {
    $rules = trim((string)$g['rules']);
    if ($rules === '' || $rules === '*') {
        continue;
    }
    $ids = array_filter(array_map('intval', explode(',', $rules)));
    if (!in_array($accountId, $ids, true)) {
        continue;
    }
    if (!in_array($permId, $ids, true)) {
        $ids[] = $permId;
        $pdo->prepare("UPDATE `{$prefix}auth_group` SET rules=? WHERE id=?")->execute([implode(',', $ids), $g['id']]);
        echo "AUTH group #{$g['id']}\n";
    }
}

echo "DONE\n";
