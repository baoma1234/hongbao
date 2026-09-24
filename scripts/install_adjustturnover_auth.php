<?php
/**
 * 注册后台「加减流水」权限节点（幂等）
 * php scripts/install_adjustturnover_auth.php
 */
$root = dirname(__DIR__);
$envFile = $root . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, ".env missing\n");
    exit(1);
}
$env = parse_ini_file($envFile, true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$rule = $prefix . 'auth_rule';
$group = $prefix . 'auth_group';
$now = time();
$name = 'fanshub/account/adjustturnover';

$exist = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($exist) {
    $id = (int)$exist['id'];
    echo "OK already exists id={$id}\n";
} else {
    $parent = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/account' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $pid = $parent ? (int)$parent['id'] : 0;
    $adjust = $pdo->query("SELECT id, weigh FROM {$rule} WHERE name='fanshub/account/adjust' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $weigh = $adjust ? max(0, (int)$adjust['weigh'] - 1) : 0;
    $st = $pdo->prepare(
        "INSERT INTO {$rule} (type,pid,name,title,icon,`condition`,remark,ismenu,menutype,extend,py,pinyin,createtime,updatetime,weigh,status)
         VALUES ('file',?,?,?,'fa fa-circle-o','','单独加减待打流水',0,NULL,'','jjls','jianjianliushui',?,?,?,'normal')"
    );
    $st->execute([$pid, $name, '加减流水', $now, $now, $weigh]);
    $id = (int)$pdo->lastInsertId();
    echo "OK inserted id={$id}\n";
}

$adjust = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/account/adjust' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$adjustId = $adjust ? (int)$adjust['id'] : 0;
if ($adjustId > 0 && $id > 0) {
    $n = 0;
    foreach ($pdo->query("SELECT id, rules FROM {$group}") as $g) {
        $rules = trim((string)($g['rules'] ?? ''));
        if ($rules === '' || $rules === '*') {
            continue;
        }
        $ids = array_filter(array_map('intval', explode(',', $rules)));
        if (!in_array($adjustId, $ids, true)) {
            continue;
        }
        if (in_array($id, $ids, true)) {
            continue;
        }
        $ids[] = $id;
        $st = $pdo->prepare("UPDATE {$group} SET rules=? WHERE id=?");
        $st->execute([implode(',', $ids), (int)$g['id']]);
        $n++;
    }
    echo "synced groups={$n}\n";
}
echo "done\n";
