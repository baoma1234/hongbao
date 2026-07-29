<?php
/**
 * 公告二级分类 + 多语言字段
 * 分类：latest / promote / ads / rules
 */
$pdo = new PDO('mysql:host=127.0.0.1;dbname=caijin_com_7111;charset=utf8mb4', 'caijin_com_7111', 'zJ3EkWE47y');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cols = [];
foreach ($pdo->query('SHOW COLUMNS FROM fa_fans_notice') as $r) {
    $cols[$r['Field']] = true;
}

if (empty($cols['content_i18n'])) {
    $pdo->exec("ALTER TABLE `fa_fans_notice` ADD COLUMN `content_i18n` text NULL COMMENT '多语言正文 JSON' AFTER `content`");
    echo "added content_i18n\n";
}
if (empty($cols['action_label_i18n'])) {
    $pdo->exec("ALTER TABLE `fa_fans_notice` ADD COLUMN `action_label_i18n` text NULL COMMENT '多语言按钮文案 JSON' AFTER `action_label`");
    echo "added action_label_i18n\n";
}
if (empty($cols['author_name_i18n'])) {
    $pdo->exec("ALTER TABLE `fa_fans_notice` ADD COLUMN `author_name_i18n` text NULL COMMENT '多语言昵称 JSON' AFTER `author_name`");
    echo "added author_name_i18n\n";
}

// 旧中文分类 → 新 code
$map = [
    '规则' => 'rules',
    '玩法' => 'rules',
    '推广' => 'promote',
    '广告' => 'ads',
    '最新' => 'latest',
    '最新发布' => 'latest',
    '推广赚钱' => 'promote',
    '广告发布' => 'ads',
    '游戏规则' => 'rules',
    '游戏规划' => 'rules',
];
foreach ($map as $from => $to) {
    $st = $pdo->prepare('UPDATE fa_fans_notice SET category = ? WHERE category = ?');
    $st->execute([$to, $from]);
    if ($st->rowCount()) {
        echo "mapped {$from} -> {$to}: {$st->rowCount()}\n";
    }
}
$pdo->exec("UPDATE fa_fans_notice SET category = 'latest' WHERE category NOT IN ('latest','promote','ads','rules') OR category IS NULL OR category = ''");

$now = time();
$exist = (int)$pdo->query("SELECT COUNT(*) FROM fa_fans_notice WHERE category IN ('latest','promote','ads','rules')")->fetchColumn();

$seeds = [
    [
        'latest',
        '红宝官方公告',
        "【最新发布】欢迎来到红宝公告中心。\n这里会持续更新平台动态、活动上新与重要通知，请留意本栏目最新内容。",
        '查看动态',
        200,
        [
            'en-PH' => "[Latest] Welcome to Hongbao Notices.\nPlatform updates, new events and important announcements will be posted here.",
        ],
    ],
    [
        'promote',
        '红宝官方公告',
        "【推广赚钱】邀请好友、分享裂变与建群拉人，可持续获得推广收益与红包反佣。\n虚拟演示与真实结算数据都会在此展示：推广了多少、赚了多少，一目了然。",
        '去推广',
        190,
        [
            'en-PH' => "[Promote & Earn] Invite friends, share growth and create groups to earn promo rewards and red-packet rebates.\nDemo and real settlement stats will be shown here.",
        ],
    ],
    [
        'ads',
        '红宝官方公告',
        "【广告发布】平台优惠、合作商活动与限时福利将在本栏目推送。\n关注广告发布，不错过专属优惠与合作信息。",
        '了解优惠',
        180,
        [
            'en-PH' => "[Ads] Platform offers, partner campaigns and limited-time deals will be published here.",
        ],
    ],
    [
        'rules',
        '红宝官方公告',
        "【游戏规则】拼手气、埋雷等红包玩法说明与平台规则集中在此。\n请先阅读规则再参与，保障公平公正。",
        '阅读规则',
        170,
        [
            'en-PH' => "[Game Rules] Lucky red packets, mine mode and related rules are collected here. Please read before playing.",
        ],
    ],
];

// 每个分类至少保证有一条引导文案（若该分类已有内容则跳过插入）
$chk = $pdo->prepare('SELECT COUNT(*) FROM fa_fans_notice WHERE category = ?');
$ins = $pdo->prepare('INSERT INTO fa_fans_notice
    (author_name,author_name_i18n,category,content,content_i18n,images,video,action_type,action_label,action_label_i18n,action_url,action_buttons,status,publishtime,weigh,createtime,updatetime)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

foreach ($seeds as $s) {
    $chk->execute([$s[0]]);
    if ((int)$chk->fetchColumn() > 0) {
        echo "skip seed {$s[0]} (exists)\n";
        continue;
    }
    $i18n = json_encode($s[5], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ins->execute([
        $s[1], '{}', $s[0], $s[2], $i18n, '[]', '', 'none', $s[3], '{}', '', '[]',
        'published', $now, $s[4], $now, $now,
    ]);
    echo "seeded {$s[0]}\n";
}

echo "ok categories=" . $exist . "\n";
