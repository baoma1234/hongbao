<?php
/**
 * 帖子主题按大模块分类：latest / promote / ads / rules
 * php scripts/migrate_notice_theme_category.php
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

$theme = $prefix . 'fans_notice_theme';

function colExists(PDO $pdo, $table, $col)
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
}

if (!colExists($pdo, $theme, 'category')) {
    $pdo->exec("ALTER TABLE `{$theme}` ADD COLUMN `category` varchar(32) NOT NULL DEFAULT 'ads' COMMENT '所属大模块 latest|promote|ads|rules' AFTER `title`");
    echo "OK added {$theme}.category\n";
} else {
    echo "OK {$theme}.category exists\n";
}

try {
    $pdo->exec("ALTER TABLE `{$theme}` ADD KEY `idx_category_status_weigh` (`category`,`status`,`weigh`)");
    echo "OK idx_category_status_weigh\n";
} catch (Throwable $e) {
    echo "SKIP idx_category_status_weigh\n";
}

$now = time();

// 旧主题归到彩金白嫖；广告启用；交流隐藏（新方案未保留）
$pdo->exec("UPDATE `{$theme}` SET `category`='ads' WHERE `code` IN ('general','freebie','ad','expose','discuss') AND (`category`='' OR `category` IS NULL OR `category`='ads')");
$pdo->exec("UPDATE `{$theme}` SET `status`='normal', `title`='广告', `weigh`=20, `category`='ads', `updatetime`={$now} WHERE `code`='ad'");
$pdo->exec("UPDATE `{$theme}` SET `status`='hidden', `updatetime`={$now} WHERE `code`='discuss'");
$pdo->exec("UPDATE `{$theme}` SET `title`='综合', `weigh`=50, `category`='ads', `status`='normal', `updatetime`={$now} WHERE `code`='general'");
$pdo->exec("UPDATE `{$theme}` SET `title`='白嫖', `weigh`=40, `category`='ads', `status`='normal', `updatetime`={$now} WHERE `code`='freebie'");
$pdo->exec("UPDATE `{$theme}` SET `title`='曝光', `weigh`=10, `category`='ads', `status`='normal', `updatetime`={$now} WHERE `code`='expose'");
echo "OK remapped ads themes\n";

$seeds = [
    // 最新发布
    ['latest_new', '最新', 'latest', 50],
    ['latest_rules', '规则', 'latest', 40],
    ['latest_notice', '通知', 'latest', 30],
    // 推广赚钱
    ['promote_promo', '推广', 'promote', 50],
    ['promote_earn', '赚钱', 'promote', 40],
    // 彩金白嫖（已有 general/freebie/ad/expose，兜底再插）
    ['general', '综合', 'ads', 50],
    ['freebie', '白嫖', 'ads', 40],
    ['ad', '广告', 'ads', 20],
    ['expose', '曝光', 'ads', 10],
    // 红宝•海外圈内事
    ['overseas_news', '海外快讯', 'rules', 50],
    ['life_story', '生活故事', 'rules', 40],
    ['overseas_help', '海外求助', 'rules', 30],
    ['chat_rant', '闲聊吐槽', 'rules', 20],
];

foreach ($seeds as $s) {
    [$code, $title, $cat, $weigh] = $s;
    $st = $pdo->prepare("SELECT id FROM `{$theme}` WHERE code=? LIMIT 1");
    $st->execute([$code]);
    $id = $st->fetchColumn();
    if ($id) {
        $upd = $pdo->prepare("UPDATE `{$theme}` SET title=?, category=?, weigh=?, status='normal', updatetime=? WHERE id=?");
        $upd->execute([$title, $cat, $weigh, $now, $id]);
        echo "OK update theme {$code} => {$title} ({$cat})\n";
    } else {
        $ins = $pdo->prepare("INSERT INTO `{$theme}` (code,title,category,weigh,status,createtime,updatetime) VALUES (?,?,?,?,'normal',?,?)");
        $ins->execute([$code, $title, $cat, $weigh, $now, $now]);
        echo "OK seed theme {$code}={$title} ({$cat})\n";
    }
}

$rows = $pdo->query("SELECT id,code,title,category,weigh,status FROM `{$theme}` ORDER BY category, weigh DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo sprintf(
        "  #%d %s | %s | %s | w=%d | %s\n",
        $r['id'],
        $r['category'],
        $r['code'],
        $r['title'],
        $r['weigh'],
        $r['status']
    );
}
echo "DONE\n";
