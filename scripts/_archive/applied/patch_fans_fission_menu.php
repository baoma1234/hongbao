<?php
/**
 * 后台菜单：裂变红包
 * php scripts/patch_fans_fission_menu.php
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
$rule = $pre . 'auth_rule';
$name = 'fanshub/fission';
$exists = $pdo->query("SELECT id FROM `{$rule}` WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
if ($exists) {
    echo "menu already exists id={$exists}\n";
    exit(0);
}
$pid = $pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub' LIMIT 1")->fetchColumn();
if (!$pid) {
    fwrite(STDERR, "fanshub parent menu missing\n");
    exit(1);
}
$now = time();
$st = $pdo->prepare(
    "INSERT INTO `{$rule}` (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)
     VALUES ('file',?,?,?,?,?,?,?,?,?,?,?,?,?)"
);
$st->execute([
    (int)$pid,
    $name,
    '裂变红包',
    'fa fa-gift',
    '',
    '',
    '全网裂变红包活动',
    1,
    null,
    $now,
    $now,
    35,
    'normal',
]);
echo "OK menu id=" . $pdo->lastInsertId() . "\n";
