<?php
/**
 * 用户账户限制：禁止返佣 / 禁止领红包雨
 * 用法: php scripts/patch_account_restrict_v1.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$table = $prefix . 'fans_account';
$rule = $prefix . 'auth_rule';
$group = $prefix . 'auth_group';
$now = time();

$cols = [
    'deny_rebate' => "ADD COLUMN `deny_rebate` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '1=禁止收取返佣' AFTER `admin_remark`",
    'deny_rp_rain' => "ADD COLUMN `deny_rp_rain` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '1=禁止领取任何红包雨' AFTER `deny_rebate`",
];
$existCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`") as $c) {
    $existCols[$c['Field']] = true;
}
foreach ($cols as $name => $ddl) {
    if (!empty($existCols[$name])) {
        echo "SKIP column {$name}\n";
        continue;
    }
    $pdo->exec("ALTER TABLE `{$table}` {$ddl}");
    echo "OK   add column {$name}\n";
}

$parent = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/account' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$pid = $parent ? (int)$parent['id'] : 0;
$ban = $pdo->query("SELECT id, weigh FROM {$rule} WHERE name='fanshub/account/ban' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$weighBase = $ban ? (int)$ban['weigh'] : 0;

$nodes = [
    'fanshub/account/denyrebate' => ['title' => '禁止返佣', 'remark' => '切换禁止收取红包返佣', 'py' => 'jzfy', 'pinyin' => 'jinzhifanyong', 'weigh' => max(0, $weighBase - 1)],
    'fanshub/account/denyrprain' => ['title' => '禁止红包雨', 'remark' => '切换禁止领取任何红包雨', 'py' => 'jzhby', 'pinyin' => 'jinzhihongbaoyu', 'weigh' => max(0, $weighBase - 2)],
];

$newIds = [];
foreach ($nodes as $name => $meta) {
    $exist = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($exist) {
        $newIds[] = (int)$exist['id'];
        echo "OK already auth {$name} id={$exist['id']}\n";
        continue;
    }
    $st = $pdo->prepare(
        "INSERT INTO {$rule} (type,pid,name,title,icon,`condition`,remark,ismenu,menutype,extend,py,pinyin,createtime,updatetime,weigh,status)
         VALUES ('file',?,?,?,'fa fa-circle-o','',?,0,NULL,'',?,?,?,?,?,'normal')"
    );
    $st->execute([
        $pid,
        $name,
        $meta['title'],
        $meta['remark'],
        $meta['py'],
        $meta['pinyin'],
        $now,
        $now,
        $meta['weigh'],
    ]);
    $id = (int)$pdo->lastInsertId();
    $newIds[] = $id;
    echo "OK inserted auth {$name} id={$id}\n";
}

$banId = $ban ? (int)$ban['id'] : 0;
$attachIds = array_values(array_filter(array_merge($newIds, $banId > 0 ? [$banId] : [])));
if ($attachIds) {
    $n = 0;
    foreach ($pdo->query("SELECT id, rules FROM {$group}") as $g) {
        $rules = trim((string)($g['rules'] ?? ''));
        if ($rules === '' || $rules === '*') {
            continue;
        }
        $parts = array_filter(explode(',', $rules), 'strlen');
        $set = [];
        foreach ($parts as $p) {
            $set[(string)(int)$p] = true;
        }
        $changed = false;
        foreach ($attachIds as $rid) {
            $k = (string)$rid;
            if (empty($set[$k])) {
                $set[$k] = true;
                $changed = true;
            }
        }
        if (!$changed) {
            continue;
        }
        $newRules = implode(',', array_keys($set));
        $pdo->prepare("UPDATE {$group} SET rules=? WHERE id=?")->execute([$newRules, (int)$g['id']]);
        $n++;
    }
    echo "OK synced auth_group rows={$n}\n";
}

echo "DONE\n";
