<?php
/**
 * Rewrite sticker JSON urls to ASCII filenames (files already renamed).
 */
$root = dirname(__DIR__);
$map = [
    '微笑' => 'weixiao', '撇嘴' => 'piezui', '色' => 'se', '发呆' => 'fadai', '得意' => 'deyi',
    '流泪' => 'liulei', '害羞' => 'haixiu', '闭嘴' => 'bizui', '睡' => 'shui', '大哭' => 'daku',
    '尴尬' => 'ganga', '发怒' => 'fanu', '调皮' => 'tiaopi', '呲牙' => 'ciya', '惊讶' => 'jingya',
    '难过' => 'nanguo', '囧' => 'jiong', '抓狂' => 'zhuakuang', '吐' => 'tu', '偷笑' => 'touxiao',
    '愉快' => 'yukuai', '白眼' => 'baiyan', '傲慢' => 'aoman', '困' => 'kun', '惊恐' => 'jingkong',
    '憨笑' => 'hanxiao', '悠闲' => 'youxian', '咒骂' => 'zhouma', '疑问' => 'yiwen', '嘘' => 'xu',
    '晕' => 'yun', '衰' => 'shuai', '骷髅' => 'kulou', '敲打' => 'qiaoda', '再见' => 'zaijian',
    '擦汗' => 'cahan', '抠鼻' => 'koubi', '鼓掌' => 'guzhang', '坏笑' => 'huaixiao', '右哼哼' => 'youhengheng',
    '鄙视' => 'bishi', '委屈' => 'weiqu', '快哭了' => 'kuaikule', '阴险' => 'yinxian', '亲亲' => 'qinqin',
    '可怜' => 'kelian', '笑脸' => 'xiaolian', '生病' => 'shengbing', '脸红' => 'lianhong',
    '破涕为笑' => 'potiweixiao', '恐惧' => 'kongju', '失望' => 'shiwang', '无语' => 'wuyu',
    '嘿哈' => 'heiha', '捂脸' => 'wulian', '机智' => 'jizhi', '皱眉' => 'zhoumei', '耶' => 'ye',
    '吃瓜' => 'chigua', '加油' => 'jiayou', '汗' => 'han', '天啊' => 'tiana',
    '社会社会' => 'shehuishenhui', '旺柴' => 'wangchai', '好的' => 'haode', '打脸' => 'dalian',
    '哇' => 'wa', '翻白眼' => 'fanbaiyan', '让我看看' => 'rangwokanikan', '叹气' => 'tanqi',
    '苦涩' => 'kuse', '裂开' => 'liekai', '奸笑' => 'jianxiao',
    '握手' => 'woshou', '胜利' => 'shengli', '抱拳' => 'baoquan', '勾引' => 'gouyin',
    '拳头' => 'quantou', '合十' => 'heshi', '强' => 'qiang', '拥抱' => 'yongbao', '弱' => 'ruo',
    '猪头' => 'zhutou', '跳跳' => 'tiaotiao', '发抖' => 'fadou', '转圈' => 'zhuanquan',
    '庆祝' => 'qingzhu', '礼物' => 'liwu', '红包' => 'hongbao', '發' => 'fa', '福' => 'fu',
    '烟花' => 'yanhua', '爆竹' => 'baozhu',
    '嘴唇' => 'zuichun', '爱心' => 'aixin', '心碎' => 'xinsui', '啤酒' => 'pijiu',
    '咖啡' => 'kafei', '蛋糕' => 'dangao', '凋谢' => 'diaoxie', '菜刀' => 'caidao',
    '炸弹' => 'zhadan', '便便' => 'bianbian', '太阳' => 'taiyang', '月亮' => 'yueliang',
    '玫瑰' => 'meigui',
];

$jsonFiles = [
    $root . '/uni-999/src/static/data/stickers.json',
    $root . '/public/999/static/data/stickers.json',
    $root . '/public/888/data/stickers.json',
];

foreach ($jsonFiles as $jf) {
    if (!is_file($jf)) {
        echo "skip {$jf}\n";
        continue;
    }
    $data = json_decode(file_get_contents($jf), true);
    if (!is_array($data)) {
        throw new RuntimeException('bad json ' . $jf);
    }
    $changed = 0;
    if (!empty($data['packs']) && is_array($data['packs'])) {
        for ($pi = 0; $pi < count($data['packs']); $pi++) {
            if (empty($data['packs'][$pi]['categories']) || !is_array($data['packs'][$pi]['categories'])) {
                continue;
            }
            for ($ci = 0; $ci < count($data['packs'][$pi]['categories']); $ci++) {
                if (empty($data['packs'][$pi]['categories'][$ci]['items']) || !is_array($data['packs'][$pi]['categories'][$ci]['items'])) {
                    continue;
                }
                for ($ii = 0; $ii < count($data['packs'][$pi]['categories'][$ci]['items']); $ii++) {
                    $url = (string)($data['packs'][$pi]['categories'][$ci]['items'][$ii]['url'] ?? '');
                    if (!preg_match('#^(.*)/([^/]+)\.(png|jpg|jpeg|gif|webp)$#iu', $url, $m)) {
                        continue;
                    }
                    $stem = $m[2];
                    if (!preg_match('/\p{Han}/u', $stem)) {
                        continue;
                    }
                    if (!isset($map[$stem])) {
                        throw new RuntimeException("unknown {$stem} in {$jf}");
                    }
                    $data['packs'][$pi]['categories'][$ci]['items'][$ii]['url'] = $m[1] . '/' . $map[$stem] . '.' . strtolower($m[3]);
                    $changed++;
                }
            }
        }
    }
    $out = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    file_put_contents($jf, $out);
    echo "updated {$jf} changes={$changed}\n";
}

$alias = [];
foreach ($map as $cn => $en) {
    $alias[$cn . '.png'] = $en . '.png';
}
$aliasPath = $root . '/uni-999/src/static/data/sticker-ascii-alias.json';
file_put_contents($aliasPath, json_encode($alias, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
@copy($aliasPath, $root . '/public/999/static/data/sticker-ascii-alias.json');
@copy($aliasPath, $root . '/public/888/data/sticker-ascii-alias.json');
echo "alias ok\n";
