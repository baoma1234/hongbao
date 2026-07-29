<?php
/**
 * 红宝公告动态表 + 初始演示数据
 */
$pdo = new PDO('mysql:host=127.0.0.1;dbname=caijin_com_7111;charset=utf8mb4', 'caijin_com_7111', 'zJ3EkWE47y');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE TABLE IF NOT EXISTS `fa_fans_notice` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `author_name` varchar(64) NOT NULL DEFAULT '红宝官方公告' COMMENT '昵称',
  `author_avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '头像URL',
  `category` varchar(32) NOT NULL DEFAULT '规则' COMMENT '分类：规则/玩法/推广/广告',
  `content` text NOT NULL COMMENT '正文（完整展开）',
  `images` text COMMENT '图片JSON数组',
  `video` varchar(512) NOT NULL DEFAULT '' COMMENT '视频URL',
  `action_type` varchar(32) NOT NULL DEFAULT '' COMMENT 'cta类型：link/share/none/buttons',
  `action_label` varchar(64) NOT NULL DEFAULT '' COMMENT '主按钮文案',
  `action_url` varchar(512) NOT NULL DEFAULT '' COMMENT '主按钮链接',
  `action_buttons` text COMMENT '多按钮JSON [{label,url}]',
  `status` enum('draft','published') NOT NULL DEFAULT 'published',
  `publishtime` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '发布时间',
  `weigh` int(11) NOT NULL DEFAULT '0' COMMENT '排序权重',
  `createtime` int(10) DEFAULT NULL,
  `updatetime` int(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_pub` (`status`,`publishtime`),
  KEY `idx_weigh` (`weigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='红宝公告动态（朋友圈风格）'");

$cnt = (int)$pdo->query('SELECT COUNT(*) FROM fa_fans_notice')->fetchColumn();
if ($cnt === 0) {
    $now = time();
    $today = strtotime(date('Y-m-d 10:30:00'));
    $rows = [
        [
            '红宝官方公告',
            '',
            '规则',
            "亲爱的红宝用户：\n为保障社区公平与安全，平台规则已更新。请仔细阅读最新条款，共同维护良好互动环境。违规内容将被限制展示或封禁账号。",
            '[]',
            '',
            'none',
            '规则更新',
            '',
            '[]',
            $today,
            100,
        ],
        [
            '红宝官方公告',
            '',
            '玩法',
            "今日玩法速览：参与接力、领取红包、查看收益，一站完成。\n下滑即可看到最新活动说明，内容每日更新，记得回来看看。",
            '[]',
            '',
            'buttons',
            '',
            '',
            json_encode([
                ['label' => '参与接力', 'url' => ''],
                ['label' => '领取红包', 'url' => ''],
                ['label' => '查看收益', 'url' => ''],
            ], JSON_UNESCAPED_UNICODE),
            $today + 3600,
            90,
        ],
        [
            '红宝官方公告',
            '',
            '推广',
            "邀请好友加入红宝社群，双方均可获得推广佣金奖励。复制专属链接分享到社群，邀请越多收益越高。",
            '[]',
            '',
            'share',
            '邀请好友 赚推广佣金',
            '',
            '[]',
            $today + 7200,
            80,
        ],
    ];
    $st = $pdo->prepare('INSERT INTO fa_fans_notice
        (author_name,author_avatar,category,content,images,video,action_type,action_label,action_url,action_buttons,status,publishtime,weigh,createtime,updatetime)
        VALUES (?,?,?,?,?,?,?,?,?,?,\'published\',?,?,?,?)');
    foreach ($rows as $r) {
        $st->execute([
            $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8], $r[9],
            $r[10], $r[11], $now, $now,
        ]);
    }
    echo "seeded " . count($rows) . " notices\n";
} else {
    echo "notices exist: $cnt\n";
}
echo "ok\n";
