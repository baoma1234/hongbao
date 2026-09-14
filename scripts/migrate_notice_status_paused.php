<?php
/**
 * fa_fans_notice.status 增加 paused（暂停展示，数据保留，前台不显示）
 */
$pdo = new PDO('mysql:host=127.0.0.1;dbname=caijin_com_7111;charset=utf8mb4', 'caijin_com_7111', 'zJ3EkWE47y');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$table = 'fa_fans_notice';
$col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
if (!$col) {
    fwrite(STDERR, "status column missing\n");
    exit(1);
}
$type = (string)($col['Type'] ?? '');
if (stripos($type, "'paused'") !== false) {
    echo "OK already has paused: {$type}\n";
    exit(0);
}

$sql = "ALTER TABLE `{$table}` MODIFY COLUMN `status` ENUM('draft','published','paused') NOT NULL DEFAULT 'published' COMMENT 'draft草稿 published展示 paused暂停展示'";
$pdo->exec($sql);
$after = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
echo "OK migrated status -> " . ($after['Type'] ?? '') . "\n";
