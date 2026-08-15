<?php
/**
 * 后台管理员谷歌验证器：fa_admin.google_secret + 为无密钥账号生成独立密钥
 *
 * php scripts/patch_admin_google_auth.php
 * php scripts/patch_admin_google_auth.php --force-reset   # 全部重新生成（慎用）
 */
$root = dirname(__DIR__);
require_once $root . '/application/common/library/GoogleAuthenticator.php';

$env = parse_ini_file($root . '/.env', true);
$d = $env['database'] ?? [];
$prefix = $d['prefix'] ?? 'fa_';
$table = $prefix . 'admin';
$forceReset = in_array('--force-reset', $argv ?? [], true);

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $d['hostname'] ?? '127.0.0.1',
        (int)($d['hostport'] ?? 3306),
        $d['database'] ?? ''
    ),
    $d['username'] ?? 'root',
    $d['password'] ?? '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
    ]
);

$col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'google_secret'")->fetch(PDO::FETCH_ASSOC);
if ($col) {
    echo "SKIP  {$table}.google_secret already exists ({$col['Type']})\n";
} else {
    $pdo->exec(
        "ALTER TABLE `{$table}`
         ADD COLUMN `google_secret` varchar(64) NOT NULL DEFAULT '' COMMENT '谷歌验证器密钥(Base32)' AFTER `status`"
    );
    echo "OK    {$table}.google_secret added\n";
}

$ga = new PHPGangsta_GoogleAuthenticator();
$issuer = 'FansHub-Admin';
$cfgFile = $root . '/application/extra/fanshub.php';
if (is_file($cfgFile)) {
    $cfg = include $cfgFile;
    if (is_array($cfg) && !empty($cfg['google_auth_issuer'])) {
        $issuer = trim((string)$cfg['google_auth_issuer']) . '-Admin';
    }
}

$rows = $pdo->query("SELECT id, username, google_secret FROM `{$table}` ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$upd = $pdo->prepare("UPDATE `{$table}` SET `google_secret` = ? WHERE `id` = ?");

echo "\n=== Admin Google Authenticator secrets (scan once, keep offline) ===\n";
printf("%-6s %-16s %-22s %s\n", 'ID', 'USERNAME', 'SECRET', 'otpauth');
echo str_repeat('-', 100) . "\n";

foreach ($rows as $row) {
    $id = (int)$row['id'];
    $username = (string)$row['username'];
    $secret = strtoupper(preg_replace('/\s+/', '', (string)$row['google_secret']));
    $need = $forceReset || $secret === '' || !preg_match('/^[A-Z2-7]+$/', $secret);
    if ($need) {
        $secret = $ga->createSecret(16);
        $upd->execute([$secret, $id]);
        echo "NEW   ";
    } else {
        echo "KEEP  ";
    }
    $label = rawurlencode($username);
    $iss = rawurlencode($issuer);
    $otpauth = "otpauth://totp/{$label}?secret={$secret}&issuer={$iss}";
    printf("#%-4d %-16s %-22s %s\n", $id, $username, $secret, $otpauth);
}

echo "\n提示：用 Google Authenticator / 微软 Authenticator 扫描各账号密钥。\n";
echo "后台登录需填写 6 位动态码（每个管理员密钥独立）。\n";
echo "done\n";
