<?php
/**
 * 注入消息/社群相关多语言文案
 * php scripts/patch_chat_community_i18n.php
 */
$keysZh = [
    'chat_community_title' => '消息与社群',
    'chat_search_placeholder' => '搜索好友、群聊、内容',
    'chat_add_friend_btn' => '+添加好友',
    'chat_tab_chat' => '聊天',
    'chat_tab_community' => '社群',
    'chat_create_group_btn' => '建群',
    'chat_recommend_groups' => '推荐社群',
    'chat_recommend_empty' => '暂无推荐社群',
    'chat_recommend_tag' => '推荐',
    'chat_my_groups' => '我的群组',
    'chat_my_groups_empty' => '暂无群组',
    'chat_friend_feed' => '好友动态',
    'chat_friend_feed_empty' => '暂无好友',
    'chat_friend_online' => '在线',
    'chat_friend_offline' => '离线',
    'chat_group_online' => '{count}人在线',
    'chat_group_members' => '{count}人',
    'chat_join_group_ok' => '已加入社群',
    'chat_join_group_fail' => '加入失败',
    'chat_add_friend_title' => '添加好友',
    'chat_add_friend_phone_label' => '对方手机号',
    'chat_add_friend_phone_placeholder' => '请输入手机号',
    'chat_add_friend_hint' => '仅支持通过手机号精确查找并添加好友',
    'chat_add_friend_submit' => '查找并添加',
    'chat_add_friend_phone_invalid' => '请输入正确的手机号',
    'chat_add_friend_not_found' => '未找到该用户',
    'chat_add_friend_confirm' => '添加好友：{name}？',
    'chat_add_friend_ok' => '已添加好友',
    'chat_add_friend_fail' => '添加失败',
    'chat_friend_title' => '好友 {id}',
];

$keysEn = [
    'chat_community_title' => 'Chats & Groups',
    'chat_search_placeholder' => 'Search friends, groups, messages',
    'chat_add_friend_btn' => '+Add friend',
    'chat_tab_chat' => 'Chats',
    'chat_tab_community' => 'Community',
    'chat_create_group_btn' => 'New group',
    'chat_recommend_groups' => 'Recommended',
    'chat_recommend_empty' => 'No recommended groups',
    'chat_recommend_tag' => 'Hot',
    'chat_my_groups' => 'My groups',
    'chat_my_groups_empty' => 'No groups yet',
    'chat_friend_feed' => 'Friends',
    'chat_friend_feed_empty' => 'No friends yet',
    'chat_friend_online' => 'Online',
    'chat_friend_offline' => 'Offline',
    'chat_group_online' => '{count} online',
    'chat_group_members' => '{count} members',
    'chat_join_group_ok' => 'Joined group',
    'chat_join_group_fail' => 'Failed to join',
    'chat_add_friend_title' => 'Add friend',
    'chat_add_friend_phone_label' => 'Phone number',
    'chat_add_friend_phone_placeholder' => 'Enter phone number',
    'chat_add_friend_hint' => 'Friends can only be added by exact phone number',
    'chat_add_friend_submit' => 'Find & add',
    'chat_add_friend_phone_invalid' => 'Invalid phone number',
    'chat_add_friend_not_found' => 'User not found',
    'chat_add_friend_confirm' => 'Add friend: {name}?',
    'chat_add_friend_ok' => 'Friend added',
    'chat_add_friend_fail' => 'Failed to add',
    'chat_friend_title' => 'Friend {id}',
];

function patchFile($file, array $keys)
{
    if (!is_file($file)) {
        echo "skip missing $file\n";
        return;
    }
    $s = file_get_contents($file);
    $changed = false;
    foreach ($keys as $k => $v) {
        if (strpos($s, '"' . $k . '"') !== false) {
            continue;
        }
        $inject = ',"' . $k . '":' . json_encode($v, JSON_UNESCAPED_UNICODE);
        // copy.defaults.js ends with };
        // locale: ...};
        $pos = strrpos($s, '}');
        if ($pos === false) {
            echo "bad format $file\n";
            return;
        }
        // insert before last }
        $s = substr($s, 0, $pos) . $inject . substr($s, $pos);
        $changed = true;
    }
    if ($changed) {
        file_put_contents($file, $s);
        echo "patched $file\n";
    } else {
        echo "ok $file\n";
    }
}

$root = dirname(__DIR__) . '/public/888';
patchFile($root . '/copy.defaults.js', $keysZh);
patchFile($root . '/i18n/locales/zh-CN.js', $keysZh);

$enLocales = ['en-PH.js', 'km-KH.js', 'id-ID.js', 'vi-VN.js', 'ms-MY.js'];
foreach ($enLocales as $f) {
    // non-zh use English fallback for now (can be refined later)
    patchFile($root . '/i18n/locales/' . $f, $keysEn);
}

echo "done\n";
