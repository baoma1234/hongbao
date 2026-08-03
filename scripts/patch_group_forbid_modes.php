<?php
/**
 * 群禁止模式字段 forbid_modes + 旧 status=3 迁移
 * 用法: php scripts/patch_group_forbid_modes.php
 */
$root = dirname(__DIR__);
$db = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'caijin_com_7111',
    'username' => 'root',
    'password' => '',
    'prefix' => 'fa_',
];
$iniFile = $root . DIRECTORY_SEPARATOR . '.env';
if (is_file($iniFile)) {
    $ini = @parse_ini_file($iniFile, true);
    if (is_array($ini) && !empty($ini['database'])) {
        $d = $ini['database'];
        $db['host'] = $d['hostname'] ?? $db['host'];
        $db['port'] = (int)($d['hostport'] ?? $db['port']);
        $db['database'] = $d['database'] ?? $db['database'];
        $db['username'] = $d['username'] ?? $db['username'];
        $db['password'] = $d['password'] ?? $db['password'];
        $db['prefix'] = $d['prefix'] ?? $db['prefix'];
    }
}
$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']};charset=utf8mb4",
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$table = $db['prefix'] . 'chat_groups';
$cols = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'forbid_modes'")->fetchAll();
if (!$cols) {
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `forbid_modes` varchar(64) NOT NULL DEFAULT '' COMMENT '群禁止模式 CSV: text,image,emoji,video,rp' AFTER `chat_mode`");
    echo "ADD forbid_modes\n";
} else {
    echo "KEEP forbid_modes\n";
}
$all = 'text,image,emoji,video,rp';
$st = $pdo->prepare("UPDATE `{$table}` SET forbid_modes=? WHERE status=3 AND (forbid_modes IS NULL OR forbid_modes='')");
$st->execute([$all]);
echo "migrated status=3 rows=" . $st->rowCount() . "\n";
echo "OK\n";
