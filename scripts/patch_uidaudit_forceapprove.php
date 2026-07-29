<?php
/**
 * 游戏账号审核：增加「强制通过」权限
 * php scripts/patch_uidaudit_forceapprove.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']
);

$rule = 'fa_auth_rule';
$now = time();
$menuId = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/uidaudit' LIMIT 1")->fetchColumn();
if (!$menuId) {
    fwrite(STDERR, "ERR   fanshub/uidaudit menu not found\n");
    exit(1);
}

$name = 'fanshub/uidaudit/forceapprove';
$exists = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
if ($exists) {
    echo "SKIP  {$name} exists (id={$exists})\n";
} else {
    $insert = $pdo->prepare('INSERT INTO fa_auth_rule (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $insert->execute(['file', $menuId, $name, '强制通过', 'fa fa-circle-o', '', '', '跳过 SugarCRM 强制核销', 0, null, $now, $now, 0, 'normal']);
    echo "OK    {$name} (id=" . $pdo->lastInsertId() . ")\n";
}

$groupId = $pdo->query("SELECT id FROM fa_auth_group WHERE id=1 LIMIT 1")->fetchColumn();
if ($groupId) {
    $rules = (string)$pdo->query("SELECT rules FROM fa_auth_group WHERE id=1")->fetchColumn();
    if ($rules === '*') {
        echo "OK    auth_group=1 already *\n";
    } else {
        $ids = array_filter(array_map('intval', explode(',', $rules)));
        $need = $pdo->query("SELECT id FROM {$rule} WHERE name LIKE 'fanshub/uidaudit%'")->fetchAll(PDO::FETCH_COLUMN);
        $changed = false;
        foreach ($need as $rid) {
            $rid = (int)$rid;
            if (!in_array($rid, $ids, true)) {
                $ids[] = $rid;
                $changed = true;
            }
        }
        if ($changed) {
            $pdo->prepare('UPDATE fa_auth_group SET rules=? WHERE id=1')->execute([implode(',', $ids)]);
            echo "OK    auth_group=1 rules updated\n";
        } else {
            echo "SKIP  auth_group=1 already has uidaudit rules\n";
        }
    }
}

echo "DONE  请重新登录后台后使用「强制通过」\n";
