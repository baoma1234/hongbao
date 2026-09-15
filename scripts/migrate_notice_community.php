<?php
/**
 * 社区帖子扩展：浏览量 / 用户发帖 / 主题
 * php scripts/migrate_notice_community.php
 */
$root = dirname(__DIR__);
$envFile = $root . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, "ERROR: .env not found\n");
    exit(1);
}
$env = parse_ini_file($envFile, true);
$db = $env['database'] ?? [];
$host = (string)($db['hostname'] ?? '127.0.0.1');
$port = (string)($db['hostport'] ?? '3306');
$name = (string)($db['database'] ?? '');
$user = (string)($db['username'] ?? '');
$pass = (string)($db['password'] ?? '');
$prefix = (string)($db['prefix'] ?? 'fa_');
if ($name === '' || $user === '') {
    fwrite(STDERR, "ERROR: database credentials missing\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$notice = $prefix . 'fans_notice';
$theme = $prefix . 'fans_notice_theme';

function colExists(PDO $pdo, $table, $col)
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
}

function tableExists(PDO $pdo, $table)
{
    $st = $pdo->prepare('SHOW TABLES LIKE ?');
    $st->execute([$table]);
    return (bool)$st->fetch(PDO::FETCH_NUM);
}

// themes table
if (!tableExists($pdo, $theme)) {
    $pdo->exec("CREATE TABLE `{$theme}` (
      `id` int unsigned NOT NULL AUTO_INCREMENT,
      `code` varchar(32) NOT NULL DEFAULT '',
      `title` varchar(64) NOT NULL DEFAULT '',
      `weigh` int NOT NULL DEFAULT 0,
      `status` enum('normal','hidden') NOT NULL DEFAULT 'normal',
      `createtime` int unsigned DEFAULT NULL,
      `updatetime` int unsigned DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_code` (`code`),
      KEY `idx_status_weigh` (`status`,`weigh`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='社区帖子主题'");
    echo "OK created {$theme}\n";
} else {
    echo "OK {$theme} exists\n";
}

$now = time();
$seeds = [
    ['general', '综合', 50],
    ['freebie', '白嫖', 40],
    ['discuss', '交流', 30],
    ['ad', '广告', 20],
    ['expose', '曝光', 10],
];
foreach ($seeds as $s) {
    $st = $pdo->prepare("SELECT id FROM `{$theme}` WHERE code=? LIMIT 1");
    $st->execute([$s[0]]);
    if ($st->fetch()) {
        continue;
    }
    $ins = $pdo->prepare("INSERT INTO `{$theme}` (code,title,weigh,status,createtime,updatetime) VALUES (?,?,?,'normal',?,?)");
    $ins->execute([$s[0], $s[1], $s[2], $now, $now]);
    echo "OK seed theme {$s[0]}={$s[1]}\n";
}

// notice columns
$alters = [
    'views_count' => "ADD COLUMN `views_count` int unsigned NOT NULL DEFAULT 0 COMMENT '浏览量' AFTER `weigh`",
    'user_id'     => "ADD COLUMN `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '发帖用户0=官方' AFTER `views_count`",
    'theme_id'    => "ADD COLUMN `theme_id` int unsigned NOT NULL DEFAULT 0 COMMENT '主题ID' AFTER `user_id`",
    'theme_title' => "ADD COLUMN `theme_title` varchar(64) NOT NULL DEFAULT '' COMMENT '主题展示名' AFTER `theme_id`",
    'source'      => "ADD COLUMN `source` enum('admin','user') NOT NULL DEFAULT 'admin' COMMENT '来源' AFTER `theme_title`",
];
foreach ($alters as $col => $ddl) {
    if (colExists($pdo, $notice, $col)) {
        echo "OK {$notice}.{$col} exists\n";
        continue;
    }
    $pdo->exec("ALTER TABLE `{$notice}` {$ddl}");
    echo "OK added {$notice}.{$col}\n";
}

if (!colExists($pdo, $notice, 'user_id') || true) {
    // indexes (ignore if exist)
    try {
        $pdo->exec("ALTER TABLE `{$notice}` ADD KEY `idx_user_status` (`user_id`,`status`)");
        echo "OK idx_user_status\n";
    } catch (Throwable $e) {
        echo "SKIP idx_user_status\n";
    }
    try {
        $pdo->exec("ALTER TABLE `{$notice}` ADD KEY `idx_theme` (`theme_id`)");
        echo "OK idx_theme\n";
    } catch (Throwable $e) {
        echo "SKIP idx_theme\n";
    }
}

$col = $pdo->query("SHOW COLUMNS FROM `{$notice}` LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
$type = (string)($col['Type'] ?? '');
if (stripos($type, "'pending'") === false || stripos($type, "'rejected'") === false) {
    $pdo->exec("ALTER TABLE `{$notice}` MODIFY COLUMN `status` ENUM('draft','published','paused','pending','rejected') NOT NULL DEFAULT 'published' COMMENT 'draft草稿 published展示 paused暂停 pending待审 rejected拒绝'");
    echo "OK status enum extended\n";
} else {
    echo "OK status enum already has pending/rejected\n";
}

echo "DONE\n";
