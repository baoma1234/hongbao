<?php
/**
 * 后台菜单：用户收款绑定（银行卡/支付宝/微信/钱包地址）
 *   php scripts/install_wallet_bind_menu.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};port=" . ($d['hostport'] ?? 3306) . ";dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$rule = $prefix . 'auth_rule';
$group = $prefix . 'auth_group';
$now = time();

function ruleId(PDO $pdo, $table, $name)
{
    $st = $pdo->prepare("SELECT id FROM `{$table}` WHERE name=? LIMIT 1");
    $st->execute([$name]);
    return (int)$st->fetchColumn();
}

$financeId = ruleId($pdo, $rule, 'finance');
if ($financeId <= 0) {
    $financeId = ruleId($pdo, $rule, 'fanshub');
}
if ($financeId <= 0) {
    fwrite(STDERR, "ERR: finance/fanshub menu missing\n");
    exit(1);
}

$menuName = 'fanshub/walletbind';
$mid = ruleId($pdo, $rule, $menuName);
$insert = $pdo->prepare(
    "INSERT INTO `{$rule}` (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)
     VALUES ('file',?,?,?,?,?,?,?,?,?,?,?,?,?)"
);

if ($mid <= 0) {
    $insert->execute([
        $financeId, $menuName, '用户收款绑定', 'fa fa-credit-card', '', '',
        '查看/增删改用户绑定的银行卡、支付宝、微信、钱包地址',
        1, 'addtabs', $now, $now, 58, 'normal',
    ]);
    $mid = (int)$pdo->lastInsertId();
    echo "OK menu {$menuName} #{$mid}\n";
} else {
    $pdo->prepare("UPDATE `{$rule}` SET pid=?, title='用户收款绑定', icon='fa fa-credit-card', ismenu=1, menutype='addtabs', status='normal', weigh=58, remark=?, updatetime=? WHERE id=?")
        ->execute([$financeId, '查看/增删改用户绑定的银行卡、支付宝、微信、钱包地址', $now, $mid]);
    echo "FIX menu #{$mid}\n";
}

$children = [
    [$menuName . '/index', '查看'],
    [$menuName . '/add', '添加'],
    [$menuName . '/edit', '编辑'],
    [$menuName . '/del', '删除'],
    [$menuName . '/multi', '批量'],
];
$allIds = [$mid];
foreach ($children as $c) {
    $cid = ruleId($pdo, $rule, $c[0]);
    if ($cid > 0) {
        $allIds[] = $cid;
        continue;
    }
    $insert->execute([
        $mid, $c[0], $c[1], 'fa fa-circle-o', '', '', '',
        0, null, $now, $now, 0, 'normal',
    ]);
    $cid = (int)$pdo->lastInsertId();
    $allIds[] = $cid;
    echo "OK child {$c[0]} #{$cid}\n";
}

$g = $pdo->query("SELECT rules FROM `{$group}` WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if ($g) {
    $have = array_flip(array_filter(explode(',', (string)$g['rules'])));
    $missing = [];
    foreach ($allIds as $id) {
        if (!isset($have[(string)$id]) && !isset($have[$id])) {
            $missing[] = $id;
        }
    }
    if ($missing) {
        $new = trim((string)$g['rules'] . ',' . implode(',', $missing), ',');
        $pdo->prepare("UPDATE `{$group}` SET rules=? WHERE id=1")->execute([$new]);
        echo 'GRANTED group#1 +' . count($missing) . " rules\n";
    } else {
        echo "SKIP grant (already)\n";
    }
}

// clear cache
$cacheDir = $root . '/runtime/cache';
if (is_dir($cacheDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    echo "CLEARED runtime/cache\n";
}

echo "DONE\n";
