<?php
/**
 * fa_fans_notice 增加 video_cover 封面图字段
 * Usage: php scripts/migrate_notice_video_cover.php
 */
$pdo = new PDO('mysql:host=127.0.0.1;dbname=caijin_com_7111;charset=utf8mb4', 'caijin_com_7111', 'zJ3EkWE47y');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$table = 'fa_fans_notice';
$cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('video_cover', $cols, true)) {
    echo "OK already has video_cover\n";
    exit(0);
}
$pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `video_cover` varchar(512) NOT NULL DEFAULT '' COMMENT '视频封面图URL' AFTER `video`");
echo "OK added video_cover\n";
