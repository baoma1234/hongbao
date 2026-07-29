<?php
$path = dirname(__DIR__) . '/application/extra/fanshub.php';
$cfg = include $path;
$keys = [
  'chat_tab_chat' => '聊天',
  'chat_tab_community' => '社群',
  'chat_recommend_groups' => '推荐社群',
  'chat_recommend_empty' => '暂无推荐社群',
  'chat_recommend_tag' => '新建社群',
  'chat_my_groups' => '我的群组',
  'chat_my_groups_empty' => '暂无群组',
  'chat_friend_feed' => '好友动态',
  'chat_friend_feed_empty' => '暂无好友动态',
  'chat_group_online' => '{count}人在线',
  'chat_group_members' => '{count}人',
  'chat_friend_online' => '刚刚在线',
  'chat_friend_offline' => '暂时离开',
  'chat_friend_status_update' => '已发布新动态',
];
if (!isset($cfg['h5_copy']) || !is_array($cfg['h5_copy'])) {
    $cfg['h5_copy'] = [];
}
foreach ($keys as $k => $v) {
    if (empty($cfg['h5_copy'][$k]) || $cfg['h5_copy'][$k] === $k) {
        $cfg['h5_copy'][$k] = $v;
    }
}
file_put_contents($path, "<?php\n\nreturn " . var_export($cfg, true) . ";\n");
echo "fanshub.php updated\n";

$defaults = include dirname(__DIR__) . '/application/extra/fanshub_h5_copy.php';
$js = 'window.FANSHUB_COPY_DEFAULTS=' . json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
file_put_contents(dirname(__DIR__) . '/public/888/copy.defaults.js', $js);
echo "copy.defaults.js updated\n";

$zhPath = dirname(__DIR__) . '/public/888/i18n/locales/zh-CN.js';
if (is_file($zhPath)) {
    $txt = file_get_contents($zhPath);
    if (preg_match('/FANSHUB_LOCALES\["zh-CN"\]=(\{.*\});/s', $txt, $m)) {
        $arr = json_decode($m[1], true);
        if (!is_array($arr)) {
            $arr = [];
        }
        foreach ($keys as $k => $v) {
            $arr[$k] = $v;
        }
        foreach ($defaults as $k => $v) {
            if (!isset($arr[$k]) || $arr[$k] === '' || $arr[$k] === $k) {
                $arr[$k] = $v;
            }
        }
        $out = 'window.FANSHUB_LOCALES=window.FANSHUB_LOCALES||{};' . "\n"
            . 'window.FANSHUB_LOCALES["zh-CN"]=' . json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
        file_put_contents($zhPath, $out);
        echo "zh-CN.js updated keys=" . count($arr) . "\n";
    } else {
        echo "zh-CN pattern miss\n";
    }
}

$bundle = dirname(__DIR__) . '/public/888/i18n/locales.bundle.js';
if (is_file($bundle)) {
    // light touch: ensure copy.defaults is enough; bundle often loaded after
    echo "bundle left as-is (defaults + zh-CN patched)\n";
}

file_put_contents(dirname(__DIR__) . '/public/888/i18n/version.js',
    "window.FANSHUB_I18N_VER='" . date('YmdHis') . "';\nwindow.FANSHUB_LOCALES=window.FANSHUB_LOCALES||{};\n");
echo "i18n version bumped\n";
