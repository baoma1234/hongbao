<?php
/**
 * 补回消息中心文案 + 个人中心二级页文案，并重建 i18n
 */
$root = dirname(__DIR__);

$zhExtra = [
    // 消息中心（此前未写入 defaults，被 regen 冲掉）
    'tab_bar_messages' => '消息',
    'home_quick_messages' => '✉️ 消息中心',
    'home_quick_messages_sub' => '私聊客服 · 群聊 · 红包',
    'chat_title' => '消息中心',
    'chat_empty' => '登录后自动连接消息服务',
    'chat_empty_no_conv' => "暂无会话\n客服管理员会自动出现在此列表",
    'chat_new_private' => '私聊',
    'chat_new_group' => '建群',
    'chat_back' => '‹ 返回',
    'chat_send' => '发送',
    'chat_input_placeholder' => '输入消息…',
    'chat_admin_only_hint' => '仅可与平台客服管理员私聊，群聊由官方创建',
    'chat_admin_welcome' => '您好，我是官方客服，有问题随时找我。',
    'chat_conn_ok' => '已连接',
    'chat_conn_fail' => '连接失败',
    'chat_conn_off' => '未连接',
    'chat_my_id' => '我的ID：{id}',
    'chat_my_id_admin' => '我的ID：{id} · 管理员',
    'chat_my_balance' => '余额 {amount}',
    'chat_my_id_with_balance' => '我的ID：{id} · 余额 {amount}',
    'chat_my_id_admin_balance' => '我的ID：{id} · 管理员 · 余额 {amount}',
    'chat_search_placeholder' => '搜索会话 / 昵称 / 内容',
    'chat_search_empty' => '没有匹配的会话',
    'chat_rp_balance_hint' => '可用红利余额：',
    // 个人中心菜单 / 二级页
    'page_hero_profile_sub' => '资料管理 · 安全设置 · 退出登录',
    'profile_menu_info' => '头像与昵称',
    'profile_menu_info_sub' => '修改头像、昵称',
    'profile_menu_password' => '修改密码',
    'profile_menu_password_sub' => '旧密码或短信验证',
    'profile_info_title' => '编辑资料',
    'profile_password_page_title' => '修改密码',
    'profile_back' => '‹ 返回',
];

$enExtra = [
    'tab_bar_messages' => 'Messages',
    'home_quick_messages' => '✉️ Messages',
    'home_quick_messages_sub' => 'Support · Groups · Red packets',
    'chat_title' => 'Messages',
    'chat_empty' => 'Sign in to connect messaging',
    'chat_empty_no_conv' => "No chats yet\nSupport agents appear here automatically",
    'chat_new_private' => 'Chat',
    'chat_new_group' => 'Group',
    'chat_back' => '‹ Back',
    'chat_send' => 'Send',
    'chat_input_placeholder' => 'Type a message…',
    'chat_admin_only_hint' => 'You can only message official support. Groups are created by admin.',
    'chat_admin_welcome' => 'Hello, I am official support. Feel free to message me anytime.',
    'chat_conn_ok' => 'Connected',
    'chat_conn_fail' => 'Connection failed',
    'chat_conn_off' => 'Disconnected',
    'chat_my_id' => 'My ID: {id}',
    'chat_my_id_admin' => 'My ID: {id} · Admin',
    'chat_my_balance' => 'Balance {amount}',
    'chat_my_id_with_balance' => 'My ID: {id} · Balance {amount}',
    'chat_my_id_admin_balance' => 'My ID: {id} · Admin · Balance {amount}',
    'chat_search_placeholder' => 'Search chats / name / content',
    'chat_search_empty' => 'No matching chats',
    'chat_rp_balance_hint' => 'Available balance: ',
    'page_hero_profile_sub' => 'Profile · Security · Logout',
    'profile_menu_info' => 'Avatar & nickname',
    'profile_menu_info_sub' => 'Update avatar and nickname',
    'profile_menu_password' => 'Change password',
    'profile_menu_password_sub' => 'Old password or SMS code',
    'profile_info_title' => 'Edit profile',
    'profile_password_page_title' => 'Change password',
    'profile_back' => '‹ Back',
];

function mergePhp($path, array $extra)
{
    $data = is_file($path) ? include $path : [];
    if (!is_array($data)) {
        $data = [];
    }
    foreach ($extra as $k => $v) {
        $data[$k] = $v;
    }
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");
    echo 'OK ' . basename($path) . "\n";
}

mergePhp($root . '/application/extra/fanshub_h5_copy.php', $zhExtra);
foreach (['en-PH.php', 'id-ID.php', 'vi-VN.php', 'ms-MY.php', 'km-KH.php'] as $f) {
    mergePhp($root . '/application/extra/i18n/' . $f, $enExtra);
}

$fanshubPath = $root . '/application/extra/fanshub.php';
if (is_file($fanshubPath)) {
    $cfg = include $fanshubPath;
    if (is_array($cfg)) {
        if (!isset($cfg['h5_copy']) || !is_array($cfg['h5_copy'])) {
            $cfg['h5_copy'] = [];
        }
        foreach ($zhExtra as $k => $v) {
            $cfg['h5_copy'][$k] = $v;
        }
        file_put_contents($fanshubPath, "<?php\n\nreturn " . var_export($cfg, true) . ";\n");
        echo "OK fanshub.php\n";
    }
}

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$zh = include $root . '/application/extra/fanshub_h5_copy.php';
$cfg = include $fanshubPath;
$saved = (isset($cfg['h5_copy']) && is_array($cfg['h5_copy'])) ? $cfg['h5_copy'] : [];
// 以 defaults 为准，saved 覆盖；不再丢掉 defaults 里没有的 saved（避免再丢 key）
$mergedZh = array_merge($zh, $saved);
foreach ($mergedZh as $k => $v) {
    $mergedZh[$k] = (string)$v;
}
// 确保 extras 最终生效
foreach ($zhExtra as $k => $v) {
    $mergedZh[$k] = $v;
}

$locales = ['zh-CN' => $mergedZh];
foreach (['en-PH', 'id-ID', 'vi-VN', 'ms-MY', 'km-KH'] as $code) {
    $path = $root . '/application/extra/i18n/' . $code . '.php';
    $data = is_file($path) ? include $path : [];
    if (!is_array($data)) {
        $data = [];
    }
    $locales[$code] = array_merge($mergedZh, $data);
    foreach ($enExtra as $k => $v) {
        $locales[$code][$k] = $v;
    }
}

$defaultsJs = 'window.FANSHUB_COPY_DEFAULTS=' . json_encode($mergedZh, $flags) . ';';
$ver = date('YmdHis');
$bundleJson = json_encode($locales, $flags);
foreach (['888', 'fanshub', 'fanshubtest'] as $dir) {
    $base = $root . '/public/' . $dir;
    if (!is_dir($base)) {
        continue;
    }
    @mkdir($base . '/i18n/locales', 0755, true);
    file_put_contents($base . '/copy.defaults.js', $defaultsJs);
    file_put_contents($base . '/i18n/version.js', "window.FANSHUB_I18N_VER='{$ver}';\nwindow.FANSHUB_LOCALES=window.FANSHUB_LOCALES||{};\n");
    file_put_contents($base . '/i18n/locales.bundle.js', 'window.FANSHUB_LOCALES = ' . $bundleJson . ";\n");
    foreach ($locales as $code => $one) {
        $js = "window.FANSHUB_LOCALES=window.FANSHUB_LOCALES||{};\n"
            . 'window.FANSHUB_LOCALES[' . json_encode($code) . ']=' . json_encode($one, $flags) . ";\n";
        file_put_contents($base . '/i18n/locales/' . $code . '.js', $js);
    }
    echo "OK public/{$dir}\n";
}
echo "DONE\n";
