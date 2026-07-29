<?php
/**
 * 写入拼手气/埋雷红包游戏规则到公告动态
 * php scripts/seed_notice_game_rules.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$now = time();
$pub = strtotime(date('Y-m-d H:i:00'));

$notices = [
    [
        'author_name' => '红宝官方公告',
        'author_avatar' => '',
        'category' => '规则',
        'content' => "📣 拼手气·扫雷·红包游戏规则说明（必看）\n\n枪红宝平台只抽取 3% 手续费，公平、公正。\n\n代理用户：通过自行设立群组邀请好友在自己设立的群组参与游戏，系统返点 1%，自动派送·无需申请。",
        'action_type' => 'none',
        'action_label' => '规则更新',
        'action_url' => '',
        'action_buttons' => '[]',
        'weigh' => 200,
        'publishtime' => $pub,
    ],
    [
        'author_name' => '红宝官方公告',
        'author_avatar' => '',
        'category' => '玩法',
        'content' => "埋雷红包玩法规则\n\n【发包方式】\n在输入框发送：金额/数量/雷号 或 金额-数量-雷号\n例：1000/5/1 = 总金额 1000，数量 5，雷号 1\n最低金额：10 起\n数量范围：5-10 个\n\n【游戏说明（1:1）】\n发包用户可选择抢 1 个 或 不抢。\n抢到红包尾数为雷号的用户，系统将扣除本局红包金额给发包用户。\n同一个包可能会同时中雷多个，中雷者都需要付给发包人本局红包的金额。",
        'action_type' => 'buttons',
        'action_label' => '',
        'action_url' => '',
        'action_buttons' => json_encode([
            ['label' => '参与接力', 'url' => ''],
            ['label' => '领取红包', 'url' => ''],
            ['label' => '查看收益', 'url' => ''],
        ], JSON_UNESCAPED_UNICODE),
        'weigh' => 190,
        'publishtime' => $pub + 60,
    ],
    [
        'author_name' => '红宝官方公告',
        'author_avatar' => '',
        'category' => '玩法',
        'content' => "拼手气红包可发包玩法规则\n\n【发包方式】\n系统自动发送首包：金额/数量 或 金额-数量\n例：10/5 = 总金额 10，数量 5\n最低金额：10 起\n数量范围：5-10 个\n\n【赔率说明（1:1）】\n发包用户可选择抢 1 个 或 不抢。\n每局抢到最低金额的用户，系统将扣除本局红包金额给发包用户。",
        'action_type' => 'none',
        'action_label' => '玩法说明',
        'action_url' => '',
        'action_buttons' => '[]',
        'weigh' => 180,
        'publishtime' => $pub + 120,
    ],
    [
        'author_name' => '红宝官方公告',
        'author_avatar' => '',
        'category' => '规则',
        'content' => "时限规则\n每局红包如在 1 分钟内未抢完则视为无效，资金原路退回。\n\n手续费说明\n平台娱乐会员，平台抽取 3% 作为手续费。\n\n代理模式\n自主开通群组娱乐，邀请好友在此群组参与游戏；群组可设定玩法，系统自动返点 1%。\n须知：系统自动到账·无需进行申请。",
        'action_type' => 'share',
        'action_label' => '邀请好友 赚推广佣金',
        'action_url' => '',
        'action_buttons' => '[]',
        'weigh' => 170,
        'publishtime' => $pub + 180,
    ],
    [
        'author_name' => '红宝官方公告',
        'author_avatar' => '',
        'category' => '玩法',
        'content' => "拼手气红包游戏规则说明（必看）\n\n本游戏为系统随机分配机制，公平、公正、公开，结果完全由概率决定。\n\n【费用说明】\n每局抽取发包金额 3% 服务费。\n\n【玩法说明（示例）】\n例如：发出 100 红包，共 5 个包，手续费 3，实际抢包金额为 97（抽取发包人 3）。\n\n抢到 20.57\n抢到 30.62\n抢到 15.56\n抢到 25.78\n抢到 4.47（最低金额）→ 判定为中雷，需承担 -100\n\n每局抢到最低金额的用户，系统将会在该用户的账户扣除本局金额，并由机器人发送下一轮红包！\n\n【中雷规则】\n抢到最低金额即为中雷。\n\n【发包说明】\n发包金额（如 100/5）抽取发包人 3% 手续费；抢包人数如未达到，资金原路退回，重新发包！",
        'action_type' => 'none',
        'action_label' => '规则更新',
        'action_url' => '',
        'action_buttons' => '[]',
        'weigh' => 160,
        'publishtime' => $pub + 240,
    ],
];

// 清除旧演示公告（可选：只删早期 seed 文案），再插入规则公告
$pdo->exec("DELETE FROM fa_fans_notice WHERE content LIKE '%亲爱的红宝用户%' OR content LIKE '%今日玩法速览%' OR content LIKE '%邀请好友加入红宝社群%' OR content LIKE '%拼手气·扫雷%' OR content LIKE '%埋雷红包玩法规则%' OR content LIKE '%拼手气红包可发包玩法规则%' OR content LIKE '%时限规则%' OR content LIKE '%拼手气红包游戏规则说明（必看）%'");

$st = $pdo->prepare('INSERT INTO fa_fans_notice
    (author_name,author_avatar,category,content,images,video,action_type,action_label,action_url,action_buttons,status,publishtime,weigh,createtime,updatetime)
    VALUES (?,?,?,?,\'[]\',\'\',?,?,?,?,\'published\',?,?,?,?)');

foreach ($notices as $n) {
    $st->execute([
        $n['author_name'],
        $n['author_avatar'],
        $n['category'],
        $n['content'],
        $n['action_type'],
        $n['action_label'],
        $n['action_url'],
        $n['action_buttons'],
        $n['publishtime'],
        $n['weigh'],
        $now,
        $now,
    ]);
    echo 'OK id=' . $pdo->lastInsertId() . ' [' . $n['category'] . '] weigh=' . $n['weigh'] . "\n";
}

$cnt = (int)$pdo->query('SELECT COUNT(*) FROM fa_fans_notice WHERE status=\'published\'')->fetchColumn();
echo "published_total={$cnt}\n";
echo "DONE 后台路径：粉丝大厅 → 公告动态（可增删改查）\n";
