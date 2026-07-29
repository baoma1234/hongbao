<?php
/**
 * 合并个人中心文案并重建 i18n 包
 * php scripts/merge_profile_i18n.php
 */
$root = dirname(__DIR__);

$zhNew = [
    'tab_bar_profile' => '我的',
    'page_hero_profile_title' => '👤 个人中心',
    'page_hero_profile_sub' => '头像昵称 · 安全改密 · 退出登录',
    'home_quick_profile' => '👤 个人中心',
    'home_quick_profile_sub' => '资料 · 密码 · 退出',
    'profile_avatar_hint' => '点击更换头像',
    'profile_nickname_label' => '昵称',
    'profile_nickname_placeholder' => '请输入昵称（最多30字）',
    'profile_mobile_label' => '绑定手机',
    'profile_save_btn' => '保存资料',
    'profile_password_title' => '修改密码',
    'profile_pwd_mode_old' => '旧密码验证',
    'profile_pwd_mode_sms' => '短信验证码',
    'profile_old_password_label' => '旧密码',
    'profile_old_password_ph' => '请输入当前密码',
    'profile_sms_code_label' => '短信验证码',
    'profile_sms_code_ph' => '请输入验证码',
    'profile_sms_send_btn' => '获取验证码',
    'profile_new_password_label' => '新密码',
    'profile_new_password_ph' => '6-32位新密码',
    'profile_confirm_password_label' => '确认新密码',
    'profile_confirm_password_ph' => '再次输入新密码',
    'profile_password_btn' => '确认修改密码',
    'profile_logout_btn' => '退出登录',
    'profile_logout_confirm' => '确定退出当前账号？',
    'profile_user_id_label' => '会员ID',
    'api_profile_ok' => '资料已保存',
    'api_avatar_ok' => '头像已更新',
    'api_password_ok' => '密码已修改，请重新登录',
    'api_logout_ok' => '已退出登录',
    'api_nickname_required' => '请填写昵称',
    'api_nickname_too_long' => '昵称最多30个字',
    'api_avatar_invalid' => '头像地址无效',
    'api_avatar_required' => '请选择头像图片',
    'api_avatar_too_large' => '头像不能超过2MB',
    'api_avatar_type_invalid' => '头像仅支持 jpg/png/gif/webp',
    'api_password_length' => '密码长度需为6-32位',
    'api_password_mismatch' => '两次输入的新密码不一致',
    'api_old_password_required' => '请输入旧密码',
    'api_old_password_wrong' => '旧密码不正确',
    'api_sms_code_wrong' => '短信验证码错误或已过期',
    'alert_profile_saved' => '资料已保存',
    'alert_avatar_ok' => '头像已更新',
    'alert_password_ok' => '密码已修改，请重新登录',
    'alert_logout_ok' => '已退出登录',
    'alert_nickname_empty' => '请输入昵称',
    'alert_password_short' => '新密码至少6位',
    'alert_password_mismatch' => '两次新密码不一致',
];

$en = [
    'tab_bar_profile' => 'Me',
    'page_hero_profile_title' => '👤 Profile',
    'page_hero_profile_sub' => 'Avatar · nickname · password · logout',
    'home_quick_profile' => '👤 Profile',
    'home_quick_profile_sub' => 'Info · password · logout',
    'profile_avatar_hint' => 'Tap to change avatar',
    'profile_nickname_label' => 'Nickname',
    'profile_nickname_placeholder' => 'Enter nickname (max 30)',
    'profile_mobile_label' => 'Mobile',
    'profile_save_btn' => 'Save profile',
    'profile_password_title' => 'Change password',
    'profile_pwd_mode_old' => 'Old password',
    'profile_pwd_mode_sms' => 'SMS code',
    'profile_old_password_label' => 'Current password',
    'profile_old_password_ph' => 'Enter current password',
    'profile_sms_code_label' => 'SMS code',
    'profile_sms_code_ph' => 'Enter verification code',
    'profile_sms_send_btn' => 'Get code',
    'profile_new_password_label' => 'New password',
    'profile_new_password_ph' => '6–32 characters',
    'profile_confirm_password_label' => 'Confirm password',
    'profile_confirm_password_ph' => 'Re-enter new password',
    'profile_password_btn' => 'Update password',
    'profile_logout_btn' => 'Log out',
    'profile_logout_confirm' => 'Log out of this account?',
    'profile_user_id_label' => 'User ID',
    'api_profile_ok' => 'Profile saved',
    'api_avatar_ok' => 'Avatar updated',
    'api_password_ok' => 'Password updated. Please sign in again',
    'api_logout_ok' => 'Logged out',
    'api_nickname_required' => 'Nickname is required',
    'api_nickname_too_long' => 'Nickname max 30 characters',
    'api_avatar_invalid' => 'Invalid avatar URL',
    'api_avatar_required' => 'Please choose an image',
    'api_avatar_too_large' => 'Avatar must be under 2MB',
    'api_avatar_type_invalid' => 'Avatar: jpg/png/gif/webp only',
    'api_password_length' => 'Password must be 6–32 characters',
    'api_password_mismatch' => 'Passwords do not match',
    'api_old_password_required' => 'Enter current password',
    'api_old_password_wrong' => 'Current password is incorrect',
    'api_sms_code_wrong' => 'Invalid or expired SMS code',
    'alert_profile_saved' => 'Profile saved',
    'alert_avatar_ok' => 'Avatar updated',
    'alert_password_ok' => 'Password updated. Please sign in again',
    'alert_logout_ok' => 'Logged out',
    'alert_nickname_empty' => 'Please enter a nickname',
    'alert_password_short' => 'New password needs at least 6 characters',
    'alert_password_mismatch' => 'New passwords do not match',
];

function mergePhpArrayFile($path, array $extra)
{
    $data = is_file($path) ? include $path : [];
    if (!is_array($data)) {
        $data = [];
    }
    foreach ($extra as $k => $v) {
        $data[$k] = $v;
    }
    $export = "<?php\n\nreturn " . var_export($data, true) . ";\n";
    if (file_put_contents($path, $export) === false) {
        throw new RuntimeException('write fail: ' . $path);
    }
    echo 'OK ' . basename($path) . "\n";
}

mergePhpArrayFile($root . '/application/extra/fanshub_h5_copy.php', $zhNew);
foreach (['en-PH.php', 'id-ID.php', 'vi-VN.php', 'ms-MY.php', 'km-KH.php'] as $file) {
    mergePhpArrayFile($root . '/application/extra/i18n/' . $file, $en);
}

$fanshubPath = $root . '/application/extra/fanshub.php';
if (is_file($fanshubPath)) {
    $cfg = include $fanshubPath;
    if (is_array($cfg)) {
        if (!isset($cfg['h5_copy']) || !is_array($cfg['h5_copy'])) {
            $cfg['h5_copy'] = [];
        }
        foreach ($zhNew as $k => $v) {
            if (!isset($cfg['h5_copy'][$k]) || $cfg['h5_copy'][$k] === '') {
                $cfg['h5_copy'][$k] = $v;
            }
        }
        file_put_contents($fanshubPath, "<?php\n\nreturn " . var_export($cfg, true) . ";\n");
        echo "OK fanshub.php\n";
    }
}

// Bootstrap ThinkPHP for regenerate
$_SERVER['HTTP_HOST'] = '127.0.0.1';
define('APP_PATH', $root . '/application/');
require $root . '/thinkphp/base.php';
\think\App::invokeMethod(['\\app\\common\\library\\FansHubService', 'exportH5CopyDefaultsJs']);
$ok = \think\App::invokeMethod(['\\app\\common\\library\\FansHubService', 'regenerateI18nBundle']);
echo $ok ? "REGENERATED\n" : "REGEN FAIL\n";
