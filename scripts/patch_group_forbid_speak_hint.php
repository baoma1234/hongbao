<?php
/**
 * 群禁言输入框自定义提示 forbid_speak_hint
 * 用法: php scripts/patch_group_forbid_speak_hint.php
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
$cols = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'forbid_speak_hint'")->fetchAll();
if (!$cols) {
    $after = 'forbid_modes';
    $hasFm = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'forbid_modes'")->fetchAll();
    if (!$hasFm) {
        $after = 'chat_mode';
    }
    $pdo->exec(
        "ALTER TABLE `{$table}` ADD COLUMN `forbid_speak_hint` varchar(120) NOT NULL DEFAULT '' "
        . "COMMENT '禁止发文字时输入框提示（空=按允许操作自动生成）' AFTER `{$after}`"
    );
    echo "ADD forbid_speak_hint\n";
} else {
    echo "KEEP forbid_speak_hint\n";
}
echo "OK\n";
