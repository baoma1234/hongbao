<?php
/**
 * 延迟邀请归因表：网页点邀请 → 下载 App → 注册时按 IP/指纹找回邀请码
 * php scripts/patch_fans_invite_pending.php
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
$t = $pre . 'fans_invite_pending';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `{$t}` (
      `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      `invite_code` varchar(32) NOT NULL DEFAULT '' COMMENT '公开邀请码',
      `inviter_user_id` int(10) unsigned NOT NULL DEFAULT 0,
      `ip` varchar(64) NOT NULL DEFAULT '',
      `device_fp` varchar(96) NOT NULL DEFAULT '',
      `ua_hash` varchar(64) NOT NULL DEFAULT '',
      `hit_count` int(10) unsigned NOT NULL DEFAULT 1,
      `consumed` tinyint(3) unsigned NOT NULL DEFAULT 0,
      `consumed_at` int(10) unsigned NOT NULL DEFAULT 0,
      `createtime` int(10) unsigned NOT NULL DEFAULT 0,
      `updatetime` int(10) unsigned NOT NULL DEFAULT 0,
      `expiretime` int(10) unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      KEY `idx_fp_open` (`device_fp`,`consumed`,`expiretime`),
      KEY `idx_ip_open` (`ip`,`consumed`,`expiretime`),
      KEY `idx_expire` (`expiretime`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='延迟邀请点击'"
);
echo "OK table {$t}\n";
