<?php
/**
 * 红包自动任务：增加 continuous_send（持续发，有未领完包也可继续发）
 * 接龙(type=5)任务忽略此开关，保持「有忙包/待续发则不发」
 * 用法: php scripts/patch_rp_auto_continuous_send.php
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
$table = $pre . 'chat_rp_auto_task';
$cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('continuous_send', $cols, true)) {
    echo "SKIP continuous_send already exists\n";
    exit(0);
}
$pdo->exec(
    "ALTER TABLE `{$table}` ADD COLUMN `continuous_send` tinyint(1) unsigned NOT NULL DEFAULT 0"
    . " COMMENT '1=持续发(有未领完也可发);接龙任务忽略' AFTER `auto_send`"
);
echo "OK added continuous_send to {$table}\n";
