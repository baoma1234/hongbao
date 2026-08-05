<?php

namespace app\common\library;

use app\common\model\fanshub\Comment;
use app\common\model\fanshub\Account;
use app\common\model\fanshub\Idempotent;
use app\common\model\fanshub\Invite;
use app\common\model\fanshub\Ledger;
use app\common\model\fanshub\LoginLog;
use app\common\model\fanshub\Notice;
use app\common\model\fanshub\Secret;
use app\common\model\fanshub\Task;
use app\common\model\User;
use app\common\exception\UploadException;
use fast\Random;
use think\Config;
use think\Db;
use think\Exception;

class FansHubService
{
    public static function config($key = null, $default = null)
    {
        $cfg = Config::get('fanshub');
        if ($key === null) {
            return $cfg ?: [];
        }
        return $cfg[$key] ?? $default;
    }

    public static function utf8Safe($value)
    {
        $value = (string)$value;
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        if (function_exists('iconv')) {
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($clean !== false) {
                return $clean;
            }
        }
        return $value;
    }

    public static function defaultH5Copy()
    {
        $file = APP_PATH . 'extra' . DS . 'fanshub_h5_copy.php';
        if (is_file($file)) {
            $defaults = include $file;
            if (is_array($defaults)) {
                return $defaults;
            }
        }
        return [];
    }

    public static function getH5Copy()
    {
        return self::getH5CopyForLocale(self::requestLocale());
    }

    /**
     * H5 / API 显式传入的语言（仅识别 query/header，避免后台误用浏览器语言）
     */
    public static function requestLocale()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $req = request();
        $raw = '';
        if ($req) {
            $raw = trim((string)$req->param('locale', ''));
            if ($raw === '') {
                $raw = trim((string)$req->header('x-fanshub-locale', ''));
            }
        }
        $codes = self::i18nLocaleCodes();
        if ($raw !== '' && !isset($codes[$raw])) {
            $short = strtolower(substr($raw, 0, 2));
            $map = [
                'zh' => 'zh-CN',
                'en' => 'en-PH',
                'id' => 'id-ID',
                'vi' => 'vi-VN',
                'ms' => 'ms-MY',
                'km' => 'km-KH',
            ];
            $raw = $map[$short] ?? '';
        }
        if ($raw === '' || !isset($codes[$raw])) {
            $raw = 'zh-CN';
        }
        $cached = $raw;
        return $cached;
    }

    public static function getH5CopyForLocale($locale = 'zh-CN')
    {
        $codes = self::i18nLocaleCodes();
        if (!isset($codes[$locale])) {
            $locale = 'zh-CN';
        }
        $cfg = self::config();
        $saved = isset($cfg['h5_copy']) && is_array($cfg['h5_copy']) ? $cfg['h5_copy'] : [];
        $zh = self::mergeH5CopyDefaults($saved);
        if ($locale === 'zh-CN') {
            return $zh;
        }
        $fileMap = self::i18nFileMap();
        $path = APP_PATH . 'extra' . DS . 'i18n' . DS . ($fileMap[$locale] ?? '');
        $data = (is_file($path)) ? include $path : [];
        if (!is_array($data)) {
            $data = [];
        }
        $merged = array_merge($zh, $data);
        foreach ($merged as $key => $value) {
            if (!array_key_exists($key, $zh)) {
                unset($merged[$key]);
                continue;
            }
            $merged[$key] = self::utf8Safe((string)$value);
        }
        return $merged;
    }

    public static function h5CopyLongKeys()
    {
        return [
            'login_subtitle', 'login_submit_btn', 'share_promo_btn', 'open_account_btn',
            'exchange_title', 'exchange_profit_hint', 'exchange_cta_hint', 'uid_label',
            'exchange_btn_template', 'footer_line1', 'footer_line2',
            'threshold_modal_desc', 'threshold_modal_btn_open',
            'master_lock_desc', 'phase2_checkin_ledger', 'phase2_honor_capped',
            'phase2_honor_progress', 'phase2_confirm_violent_msg', 'phase2_checkin_violent_btn',
            'phase2_checkin_normal_btn', 'jackpot_partners', 'jackpot_price_line',
            'marquee_text', 'chat_rp_type_lucky_desc', 'chat_rp_type_relay_desc', 'chat_rp_mine_hint',
        ];
    }

    public static function h5CopyHtmlKeys()
    {
        return [
            'exchange_profit_hint', 'exchange_cta_hint', 'threshold_modal_desc',
            'footer_line2', 'withdraw_step1', 'withdraw_step2', 'withdraw_step3',
            'marquee_text',
        ];
    }

    public static function h5CopyAdminGroups()
    {
        $copy = self::getH5Copy();
        $htmlKeys = array_flip(self::h5CopyHtmlKeys());
        $longKeys = array_flip(self::h5CopyLongKeys());
        $groups = [];
        foreach (self::h5CopyFieldGroups() as $name => $fields) {
            $items = [];
            foreach ($fields as $key => $label) {
                $items[] = [
                    'key'     => $key,
                    'label'   => $label,
                    'value'   => $copy[$key] ?? '',
                    'is_html' => isset($htmlKeys[$key]),
                    'is_long' => isset($htmlKeys[$key]) || isset($longKeys[$key]) || strpos($key, 'alert_') === 0,
                ];
            }
            $groups[] = ['name' => $name, 'items' => $items];
        }
        return $groups;
    }

    public static function mergeH5CopyDefaults(array $saved = [])
    {
        $defaults = self::defaultH5Copy();
        $merged = array_merge($defaults, $saved);
        foreach ($merged as $key => $value) {
            if (!array_key_exists($key, $defaults)) {
                unset($merged[$key]);
                continue;
            }
            $merged[$key] = self::utf8Safe((string)$value);
        }
        return $merged;
    }

    public static function h5CopyText($key, array $vars = [])
    {
        $copy = self::getH5Copy();
        $tpl = $copy[$key] ?? '';
        foreach ($vars as $name => $value) {
            $tpl = str_replace('{' . $name . '}', (string)$value, $tpl);
        }
        return self::utf8Safe($tpl);
    }

    public static function throwCopy($key, array $vars = [])
    {
        throw new Exception(self::h5CopyText($key, $vars));
    }

    public static function saveFanshubConfig(array $data)
    {
        $path = APP_PATH . 'extra' . DS . 'fanshub.php';
        $export = "<?php\n\nreturn " . var_export($data, true) . ";\n";
        if (file_put_contents($path, $export) === false) {
            return false;
        }
        Config::set('fanshub', $data);
        return true;
    }

    public static function exportH5CopyDefaultsJs($targetPath = '')
    {
        $copy = self::defaultH5Copy();
        $js = 'window.FANSHUB_COPY_DEFAULTS=' . json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
        if ($targetPath !== '') {
            return file_put_contents($targetPath, $js) !== false;
        }
        $ok = true;
        foreach (['888', 'fanshub', 'fanshubtest'] as $dir) {
            $path = ROOT_PATH . 'public' . DS . $dir . DS . 'copy.defaults.js';
            $folder = dirname($path);
            if (!is_dir($folder)) {
                @mkdir($folder, 0755, true);
            }
            if (file_put_contents($path, $js) === false) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function i18nLocaleCodes()
    {
        // 顺序：中文、英文、越南、马来、柬埔寨、印尼
        return [
            'zh-CN' => '中文',
            'en-PH' => 'English',
            'vi-VN' => 'Tiếng Việt',
            'ms-MY' => 'Melayu',
            'km-KH' => 'ខ្មែរ',
            'id-ID' => 'Indonesia',
        ];
    }

    public static function i18nFileMap()
    {
        return [
            'en-PH' => 'en-PH.php',
            'id-ID' => 'id-ID.php',
            'vi-VN' => 'vi-VN.php',
            'ms-MY' => 'ms-MY.php',
            'km-KH' => 'km-KH.php',
        ];
    }

    public static function h5CopyKeyLabels()
    {
        $labels = [];
        foreach (self::h5CopyFieldGroups() as $fields) {
            foreach ($fields as $key => $label) {
                $labels[$key] = $label;
            }
        }
        return $labels;
    }

    public static function loadI18nLocales()
    {
        $locales = ['zh-CN' => self::getH5Copy()];
        $dir = APP_PATH . 'extra' . DS . 'i18n' . DS;
        foreach (self::i18nFileMap() as $code => $file) {
            $path = $dir . $file;
            $data = is_file($path) ? include $path : [];
            $locales[$code] = is_array($data) ? $data : [];
        }
        return $locales;
    }

    public static function i18nAdminGroups()
    {
        $locales = self::loadI18nLocales();
        $codes = self::i18nLocaleCodes();
        $htmlKeys = array_flip(self::h5CopyHtmlKeys());
        $longKeys = array_flip(self::h5CopyLongKeys());
        $validKeys = array_flip(array_keys(self::defaultH5Copy()));
        $groups = [];
        foreach (self::h5CopyFieldGroups() as $groupName => $fields) {
            $items = [];
            foreach ($fields as $key => $label) {
                if (!isset($validKeys[$key])) {
                    continue;
                }
                $row = [
                    'key'     => $key,
                    'label'   => $label,
                    'is_html' => isset($htmlKeys[$key]),
                    'is_long' => isset($htmlKeys[$key]) || isset($longKeys[$key]) || strpos($key, 'alert_') === 0,
                    'cells'   => [],
                ];
                foreach ($codes as $code => $codeLabel) {
                    if ($code === 'zh-CN') {
                        $val = $locales['zh-CN'][$key] ?? '';
                    } else {
                        $val = $locales[$code][$key] ?? ($locales['zh-CN'][$key] ?? '');
                    }
                    $row['cells'][] = ['code' => $code, 'value' => $val];
                }
                $items[] = $row;
            }
            if ($items) {
                $groups[] = ['name' => $groupName, 'items' => $items];
            }
        }
        return ['groups' => $groups, 'locales' => $codes];
    }

    public static function saveI18nLocales(array $posted)
    {
        $validKeys = array_flip(array_keys(self::defaultH5Copy()));
        $cfg = self::config();
        $zhPosted = isset($posted['zh-CN']) && is_array($posted['zh-CN']) ? $posted['zh-CN'] : [];
        $zhSaved = isset($cfg['h5_copy']) && is_array($cfg['h5_copy']) ? $cfg['h5_copy'] : [];
        foreach ($zhPosted as $key => $val) {
            if (!isset($validKeys[$key])) {
                continue;
            }
            $zhSaved[$key] = self::utf8Safe((string)$val);
        }
        $cfg['h5_copy'] = self::mergeH5CopyDefaults($zhSaved);
        if (!self::saveFanshubConfig($cfg)) {
            return false;
        }

        $dir = APP_PATH . 'extra' . DS . 'i18n' . DS;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        foreach (self::i18nFileMap() as $code => $file) {
            if (!isset($posted[$code]) || !is_array($posted[$code])) {
                continue;
            }
            $data = [];
            foreach ($posted[$code] as $key => $val) {
                if (!isset($validKeys[$key])) {
                    continue;
                }
                $data[$key] = self::utf8Safe((string)$val);
            }
            $path = $dir . $file;
            $export = "<?php\n\n/**\n * FansHub H5 copy — {$code}\n */\nreturn " . var_export($data, true) . ";\n";
            if (file_put_contents($path, $export) === false) {
                return false;
            }
        }
        self::exportH5CopyDefaultsJs();
        return self::regenerateI18nBundle();
    }

    public static function regenerateI18nBundle()
    {
        $zh = self::getH5Copy();
        $locales = ['zh-CN' => $zh];
        foreach (self::i18nFileMap() as $code => $file) {
            $path = APP_PATH . 'extra' . DS . 'i18n' . DS . $file;
            if (!is_file($path)) {
                continue;
            }
            $data = include $path;
            if (!is_array($data)) {
                continue;
            }
            $locales[$code] = array_merge($zh, $data);
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $bundleJson = json_encode($locales, $flags);
        if ($bundleJson === false) {
            return false;
        }
        $ver = date('YmdHis');
        $ok = true;
        foreach (['888', 'fanshub', 'fanshubtest'] as $dir) {
            $i18nDir = ROOT_PATH . 'public' . DS . $dir . DS . 'i18n';
            $locDir = $i18nDir . DS . 'locales';
            if (!is_dir($locDir)) {
                @mkdir($locDir, 0755, true);
            }
            if (file_put_contents($i18nDir . DS . 'version.js', "window.FANSHUB_I18N_VER='{$ver}';\nwindow.FANSHUB_LOCALES=window.FANSHUB_LOCALES||{};\n") === false) {
                $ok = false;
            }
            foreach ($locales as $code => $map) {
                $safe = preg_replace('/[^A-Za-z0-9\\-]/', '', (string)$code);
                $one = json_encode($map, $flags);
                if ($one === false) {
                    $ok = false;
                    continue;
                }
                $js = "window.FANSHUB_LOCALES=window.FANSHUB_LOCALES||{};\n"
                    . 'window.FANSHUB_LOCALES[' . json_encode($safe) . ']=' . $one . ";\n";
                if (file_put_contents($locDir . DS . $safe . '.js', $js) === false) {
                    $ok = false;
                }
            }
            // 旧入口兼容全量包
            if (file_put_contents($i18nDir . DS . 'locales.bundle.js', 'window.FANSHUB_LOCALES = ' . $bundleJson . ";\n") === false) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function h5CopyFieldGroups()
    {
        return [
            '页面与顶栏' => [
                'page_title'          => '浏览器标题',
                'brand_name'          => '品牌名称',
                'skin_label'          => '换肤标签',
                'skin_option_default' => '皮肤-默认',
                'skin_option_a'       => '皮肤-激情中国红',
                'skin_option_b'       => '皮肤-皇家高级蓝',
                'skin_option_d'       => '皮肤-科技冷银灰',
                'aria_top_bar'        => '顶栏无障碍标签',
                'aria_skin_wrap'      => '换肤区无障碍标签',
                'aria_skin_select'    => '皮肤选择无障碍标签',
            ],
            '登录页' => [
                'login_subtitle'            => '登录副标题',
                'login_phone_label'         => '手机号标签',
                'login_phone_placeholder'   => '手机号占位符',
                'login_captcha_label'       => '验证码标签',
                'login_captcha_placeholder' => '验证码占位符',
                'login_captcha_btn'         => '获取验证码按钮',
                'login_captcha_resend'      => '重发倒计时（{count}）',
                'slider_modal_title'        => '滑块验证标题',
                'slider_modal_hint'         => '滑块验证说明',
                'slider_track_hint'         => '滑块轨道提示',
                'slider_refresh_btn'        => '重试',
                'slider_verifying'          => '滑块校验中',
                'alert_slider_fail'         => '滑块验证失败',
                'login_submit_btn'          => '登录按钮（{register_rights}）',
            ],
            '福利大盘' => [
                'jackpot_label'      => '大盘标题',
                'jackpot_partners'   => '股份人数（{partner_count}{partner_today_up}）',
                'jackpot_pool_label' => '累计价值标签',
                'jackpot_price_line' => '行权价行（{current_share_price}{price_up_pct}）',
                'jackpot_meta'       => '大盘摘要（备用）',
                'jackpot_hint'       => '大盘提示（{threshold}）',
            ],
            '资产与任务' => [
                'asset_rights_label'        => '持有资产标签',
                'asset_balance_label'       => '红宝标签(兼容)',
                'asset_rights_unit'         => '股份单位',
                'asset_balance_unit'        => '金额单位',
                'asset_valuation_hint'      => '估值提示（{amount}）',
                'balance_progress_ready'    => '余额进度-已达标',
                'balance_progress_pct'      => '余额进度百分比（{pct}）',
                'user_phone_prefix'         => '手机号前缀',
                'user_account_status'       => '资产账户状态前缀',
                'user_status_shield'        => '密匙防护状态',
                'user_rank_default'         => '默认身份标签',
                'user_rank_template'        => '排行标签（{rank}{count}）',
                'user_rank_master'          => '荣誉团长标签（{count}）',
                'share_promo_btn'           => '分享裂变按钮',
                'share_promo_action_btn'    => '立即分享按钮',
                'open_account_btn'          => '开户按钮（{open_account_rights}）',
                'open_account_badge_fallback' => '开户角标兜底（{open_account_rights}）',
                'stepper_1'                 => '进度条-入厅',
                'stepper_2'                 => '进度条-开户',
                'stepper_3'                 => '进度条-闪兑',
                'stepper_4'                 => '进度条-账号',
                'stepper_5'                 => '进度条-领取',
                'aria_flow_stepper'         => '进度条无障碍',
                'loading_generic'           => '通用加载中',
            ],
            '底栏与分页导航' => [
                'tab_bar_home'              => '底栏-大厅',
                'tab_bar_exchange'          => '底栏-闪兑',
                'tab_bar_claim'             => '底栏-领取',
                'tab_bar_master'            => '底栏-团长',
                'tab_bar_messages'          => '底栏-消息',
                'tab_bar_profile'           => '底栏-我的',
                'tab_bar_social'            => '底栏-互动(已并入大厅)',
                'page_hero_exchange_title'  => '闪兑页标题',
                'page_hero_exchange_sub'    => '闪兑页副标题',
                'page_hero_claim_title'     => '领取页标题',
                'page_hero_claim_sub'       => '领取页副标题',
                'page_hero_master_title'    => '团长页标题',
                'page_hero_master_sub'      => '团长页副标题',
                'page_hero_social_title'    => '互动区标题(大厅下部)',
                'page_hero_social_sub'      => '互动区副标题',
                'page_hero_profile_title'   => '我的页标题',
                'page_hero_profile_sub'     => '我的页副标题',
                'home_quick_exchange'       => '首页快链-闪兑',
                'home_quick_exchange_sub'   => '首页快链-闪兑副文',
                'home_quick_claim'          => '首页快链-领取',
                'home_quick_claim_sub'      => '首页快链-领取副文',
                'home_quick_master'         => '首页快链-团长',
                'home_quick_master_sub'     => '首页快链-团长副文',
                'home_quick_social'         => '首页快链-互动(弃用)',
                'home_quick_social_sub'     => '首页快链-互动副文(弃用)',
                'home_quick_messages'       => '首页快链-消息中心',
                'home_quick_messages_sub'   => '首页快链-消息副文',
                'home_quick_profile'        => '首页快链-我的',
                'home_quick_profile_sub'    => '首页快链-我的副文',
                'bottom_bar_share'          => '旧底栏-分享',
                'bottom_bar_exchange'       => '旧底栏-闪兑',
                'bottom_bar_claim'           => '旧底栏-领取',
            ],
            '个人中心' => [
                'profile_menu_info'              => '菜单-头像昵称',
                'profile_menu_info_sub'          => '菜单-头像昵称副文',
                'profile_menu_password'          => '菜单-修改密码',
                'profile_menu_password_sub'      => '菜单-修改密码副文',
                'profile_info_title'             => '二级页-编辑资料标题',
                'profile_password_page_title'    => '二级页-修改密码标题',
                'profile_back'                   => '二级页返回',
                'profile_avatar_hint'         => '头像点击更换提示',
                'profile_nickname_label'      => '昵称标签',
                'profile_nickname_placeholder'=> '昵称占位',
                'profile_mobile_label'        => '手机号标签',
                'profile_save_btn'            => '保存资料按钮',
                'profile_password_title'      => '修改密码区块标题',
                'profile_pwd_mode_old'        => '验证方式-旧密码',
                'profile_pwd_mode_sms'        => '验证方式-短信',
                'profile_old_password_label'  => '旧密码标签',
                'profile_old_password_ph'     => '旧密码占位',
                'profile_sms_code_label'      => '短信验证码标签',
                'profile_sms_code_ph'         => '短信验证码占位',
                'profile_sms_send_btn'        => '发送验证码按钮',
                'profile_new_password_label'  => '新密码标签',
                'profile_new_password_ph'     => '新密码占位',
                'profile_confirm_password_label' => '确认密码标签',
                'profile_confirm_password_ph'=> '确认密码占位',
                'profile_password_btn'        => '提交改密按钮',
                'profile_logout_btn'          => '退出登录按钮',
                'profile_logout_confirm'      => '退出确认文案',
                'profile_user_id_label'       => '会员ID标签',
                'profile_uid_copy_btn'        => '复制会员ID按钮',
                'profile_uid_copied'          => '会员ID已复制提示',
                'profile_uid_copy_empty'      => '暂无会员ID提示',
                'api_profile_ok'              => '资料保存成功',
                'api_avatar_ok'               => '头像上传成功',
                'api_password_ok'             => '密码修改成功',
                'api_logout_ok'               => '已退出登录',
                'api_nickname_required'       => '昵称必填',
                'api_nickname_too_long'       => '昵称过长',
                'api_avatar_invalid'          => '头像地址无效',
                'api_avatar_required'         => '请选择头像',
                'api_avatar_too_large'        => '头像过大',
                'api_avatar_type_invalid'     => '头像格式不支持',
                'api_password_length'         => '密码长度限制',
                'api_password_mismatch'       => '两次密码不一致',
                'api_old_password_required'   => '请输入旧密码',
                'api_old_password_wrong'      => '旧密码错误',
                'api_sms_code_wrong'          => '短信验证码错误',
                'alert_profile_saved'         => '资料已保存提示',
                'alert_avatar_ok'             => '头像已更新提示',
                'alert_password_ok'           => '改密成功需重登提示',
                'alert_logout_ok'             => '退出成功提示',
                'alert_nickname_empty'        => '前端昵称为空提示',
                'alert_password_short'        => '前端密码过短提示',
                'alert_password_mismatch'     => '前端两次密码不一致',
            ],
            '资产闪兑互兑' => [
                'swap_title' => '互兑标题',
                'swap_avail' => '可用余额行',
                'swap_from_label' => '转出标签',
                'swap_unit_share' => '股份单位缩写',
                'swap_asset_rights' => '资产-股份',
                'swap_asset_balance' => '资产-红宝(兼容)',
                'swap_asset_hongbao' => '资产-红宝',
                'swap_all_btn' => '全部按钮',
                'swap_min_hint' => '最低额度提示',
                'swap_to_label' => '兑换目标标签',
                'swap_rate_label' => '兑换比例',
                'swap_est_label' => '预计到账',
                'swap_submit' => '确认兑换',
                'swap_aria_from' => '转出无障碍',
                'swap_aria_flip' => '互换无障碍',
                'swap_aria_to' => '目标无障碍',
            ],
            '个人中心扩展' => [
                'profile_vip_badge' => '官方会员角标',
                'profile_quick_qr' => '快捷-二维码',
                'profile_quick_scan' => '快捷-扫一扫',
                'profile_quick_recharge' => '快捷-充值',
                'profile_quick_withdraw' => '快捷-提现',
                'profile_section_asset' => '分区-资产服务',
                'profile_section_security' => '分区-账号与安全',
                'profile_foot_note' => '页脚说明',
                'aria_profile_vip' => '会员资料无障碍',
                'aria_profile_quick' => '常用功能无障碍',
                'profile_menu_ledger' => '菜单-资金流水',
                'profile_menu_ledger_sub' => '菜单-资金流水副文',
                'profile_ledger_title' => '流水页标题',
                'profile_ledger_loading' => '流水加载中',
                'profile_ledger_more' => '加载更多',
                'profile_recharge_title' => '充值页标题',
                'profile_recharge_amount_label' => '充值金额标签',
                'profile_recharge_submit' => '确认充值',
                'profile_withdraw_title' => '提现页标题',
                'profile_withdraw_avail_prefix' => '可提现前缀',
                'profile_turnover_prefix' => '累计流水前缀',
                'profile_withdraw_amount_label' => '提现金额标签',
                'profile_withdraw_method' => '收款方式',
                'profile_withdraw_opt_bank' => '银行卡选项',
                'profile_withdraw_opt_alipay' => '支付宝选项',
                'profile_withdraw_name_label' => '收款人',
                'profile_withdraw_account_label' => '收款账号',
                'profile_withdraw_bank_label' => '银行名称',
                'profile_withdraw_branch_label' => '支行',
                'profile_withdraw_region_label' => '省市',
                'profile_withdraw_submit' => '确认提现',
                'profile_amount_ph' => '金额占位',
                'profile_withdraw_name_ph' => '姓名占位',
                'profile_withdraw_account_ph' => '账号占位',
                'profile_withdraw_bank_ph' => '银行占位',
                'profile_withdraw_branch_ph' => '支行占位',
                'profile_withdraw_province_ph' => '省占位',
                'profile_withdraw_city_ph' => '市占位',
                'profile_qr_title' => '我的二维码',
                'profile_qr_uid_prefix' => '二维码页会员ID前缀',
                'profile_qr_tip' => '二维码提示',
                'profile_qr_copy_btn' => '复制会员ID',
            ],
            '钱包提示' => [
                'wallet_not_login' => '未登录',
                'wallet_unit_share' => '单位-股',
                'wallet_unit_hongbao' => '单位-红宝',
                'wallet_unit_balance' => '单位-红宝(兼容)',
                'wallet_ledger_empty' => '流水空态',
                'wallet_ledger_other' => '其他类型',
                'wallet_ledger_type_register' => '流水类型-注册赠送',
                'wallet_ledger_type_register_bonus' => '流水类型-拉新股份',
                'wallet_ledger_type_share' => '流水类型-分享奖励',
                'wallet_ledger_type_invite' => '流水类型-邀请奖励',
                'wallet_ledger_type_open_account' => '流水类型-开户奖励',
                'wallet_ledger_type_exchange' => '流水类型-闪兑',
                'wallet_ledger_type_admin_adjust' => '流水类型-人工调整',
                'wallet_ledger_type_checkin' => '流水类型-星火签到',
                'wallet_ledger_type_checkin_bonus' => '流水类型-暴力对账',
                'wallet_ledger_type_checkin_day7' => '流水类型-7天暴击',
                'wallet_ledger_type_honor_tier' => '流水类型-荣誉晋升',
                'wallet_ledger_type_recharge' => '流水类型-充值入账',
                'wallet_ledger_type_withdraw' => '流水类型-提现扣款',
                'wallet_ledger_type_withdraw_refund' => '流水类型-提现退回',
                'wallet_ledger_type_red_packet_send' => '流水类型-发红包',
                'wallet_ledger_type_red_packet_grab' => '流水类型-红宝',
                'wallet_ledger_type_red_packet_refund' => '流水类型-红包退回',
                'wallet_ledger_type_red_packet_fee' => '流水类型-红包手续费',
                'wallet_ledger_type_red_packet_fee_in' => '流水类型-手续费收入',
                'wallet_ledger_type_red_packet_rebate' => '流水类型-红包返点',
                'wallet_ledger_type_red_packet_agent_rebate' => '流水类型-返佣支出',
                'wallet_ledger_type_red_packet_agent_rebate_in' => '流水类型-红包返佣',
                'wallet_ledger_type_red_packet_mine_pay' => '流水类型-中雷赔付',
                'wallet_ledger_type_red_packet_worst_pay' => '流水类型-手气最差赔付',
                'wallet_ledger_type_red_packet_compensate_in' => '流水类型-赔付收入',
                'wallet_load_fail' => '加载失败',
                'wallet_channel_empty' => '无通道',
                'wallet_channel_fallback' => '通道兜底（{id}）',
                'wallet_channel_more' => '更多钱包',
                'wallet_channel_less' => '收起',
                'wallet_turnover_need' => '流水不足（{need}）',
                'wallet_turnover_ratio_suffix' => '流水倍数后缀（{ratio}）',
                'wallet_turnover_line' => '累计流水行（{amount}）',
                'wallet_loading' => '加载中',
                'wallet_module_fail' => '模块失败',
                'wallet_need_channel_amount' => '需通道与金额',
                'wallet_recharge_ok' => '充值已提交',
                'wallet_need_payee' => '需收款人',
                'wallet_need_bank' => '需银行名',
                'wallet_withdraw_ok' => '提现已提交',
                'wallet_fail' => '失败兜底',
            ],
            '消息中心扩展' => [
                'chat_community_title' => '社区标题',
                'chat_tab_notice' => '公告 Tab',
                'chat_tab_commission' => '佣金 Tab',
                'chat_scan' => '扫一扫',
                'chat_cancel' => '取消',
                'chat_community_official' => '官方社群',
                'chat_friend_list' => '好友列表',
                'chat_notice_empty' => '公告空态',
                'chat_notice_latest' => '最新发布',
                'chat_notice_promote' => '推广赚钱',
                'chat_notice_ads' => '广告发布',
                'chat_notice_rules' => '游戏规则',
                'promote_earn_title' => '推广收益表标题',
                'promote_earn_live' => '实时更新按钮',
                'promote_earn_col_uid' => '列-用户ID',
                'promote_earn_col_type' => '列-收益类型',
                'promote_earn_col_detail' => '列-广细记录',
                'promote_earn_col_amount' => '列-到手佣金',
                'promote_earn_type_share' => '类型-分享推广',
                'promote_earn_type_group' => '类型-群主红包返佣',
                'promote_earn_detail_share_n' => '明细-分享引流（{n}）',
                'promote_earn_detail_groups_n' => '明细-自建群返利（{n}）',
                'promote_earn_detail_group_fee' => '明细-红包抽成返佣',
                'promote_earn_detail_multi' => '明细-多群互动返现',
                'promote_earn_detail_exposure' => '明细-推广曝光成交',
                'promote_earn_refreshed' => '刷新收益数据提示',
                'chat_add_friend_btn' => '添加好友',
                'chat_add_friend_title' => '添加好友页标题',
                'chat_add_friend_phone_label' => '对方手机号',
                'chat_add_friend_phone_placeholder' => '手机号占位',
                'chat_add_friend_submit' => '查找并申请',
                'chat_create_group_btn' => '建群',
                'chat_friend_req_entry' => '好友申请入口',
                'chat_friend_req_entry_sub' => '添加好友页-申请入口副标题',
                'chat_friend_req_title' => '好友申请标题',
                'chat_friend_req_incoming' => '收到的',
                'chat_friend_req_outgoing' => '发出的',
                'chat_friend_req_empty' => '申请空态',
                'chat_friend_req_sent' => '申请已发送提示',
                'chat_friend_req_accept' => '通过按钮',
                'chat_friend_req_reject' => '拒绝按钮',
                'chat_friend_req_cancel' => '取消申请按钮',
                'chat_friend_req_accepted' => '已通过提示',
                'chat_friend_req_fail' => '申请操作失败',
                'chat_friend_req_status_pending' => '状态-待处理',
                'chat_friend_req_status_accepted' => '状态-已通过',
                'chat_friend_req_status_rejected' => '状态-已拒绝',
                'chat_friend_req_status_cancelled' => '状态-已取消',
                'chat_friend_req_incoming_toast' => '收到申请 Toast',
                'chat_friend_need_accept' => '需对方通过提示',
                'chat_friend_title' => '私聊标题',
                'chat_commission_total' => '累计佣金',
                'chat_commission_withdraw_btn' => '佣金提现按钮',
                'chat_commission_withdrawable' => '可提现',
                'chat_commission_today' => '今日收益',
                'chat_commission_rebate' => '红包返佣',
                'chat_commission_nav_promo' => '推广结算',
                'chat_commission_nav_rebate' => '红包返佣导航',
                'chat_commission_nav_ledger' => '收益明细',
                'chat_commission_nav_withdraw' => '提现记录',
                'chat_commission_recent' => '最近结算',
                'chat_commission_login_hint' => '佣金未登录提示',
                'chat_qr_scan_hint' => '扫码提示',
                'chat_qr_pick_album' => '相册选图',
                'chat_session' => '会话默认标题',
                'chat_group_notice_pin' => '置顶群公告',
                'chat_group_popup_ok' => '群弹窗确认',
                'chat_group_popup_forever' => '群弹窗永久关闭',
                'chat_attach_image' => '附件-图片',
                'chat_attach_video' => '附件-视频',
                'chat_attach_file' => '附件-文件',
                'chat_attach_rp' => '附件-红包',
                'chat_group_settings' => '群设置',
                'chat_group_avatar_change' => '更换头像',
                'chat_group_avatar_fb' => '群头像兜底字',
                'chat_group_default_name' => '默认群名',
                'chat_group_members_count' => '成员数（{count}）',
                'chat_group_name_label' => '群名称',
                'chat_group_notice_label' => '群公告标签',
                'chat_group_save' => '保存修改',
                'chat_view_members' => '查看群成员',
                'chat_mute_all' => '全员禁言',
                'chat_group_members_title' => '群成员标题',
                'chat_add_members_btn' => '添加群成员按钮',
                'chat_add_members_title' => '添加群成员标题',
                'chat_confirm_add' => '确认添加（{count}）',
                'chat_member_actions' => '成员操作',
                'chat_mute_one' => '单人禁言',
                'chat_unmute' => '取消禁言',
                'chat_set_admin' => '设为管理员',
                'chat_unset_admin' => '取消管理员',
                'chat_kick' => '踢出群组',
                'chat_mute_duration' => '禁言时长标题',
                'chat_mute_10m' => '10分钟',
                'chat_mute_1h' => '1小时',
                'chat_mute_24h' => '24小时',
                'chat_close' => '关闭',
                'chat_rp_title' => '发红包',
                'chat_rp_blessing_default' => '默认祝福语',
                'chat_rp_lucky_sub' => '拼手气副标题',
                'chat_rp_amount_label' => '金额标签',
                'chat_rp_count_label' => '个数标签',
                'chat_rp_count_hint' => '个数说明',
                'chat_rp_type_label' => '红包类型',
                'chat_rp_type_lucky' => '拼手气',
                'chat_rp_type_lucky_desc' => '拼手气：发包人可自领；领完后金额最少者赔付该包总额（同额取最晚）；发包人最少不赔。',
                'chat_rp_type_relay' => '接龙',
                'chat_rp_type_relay_desc' => '接龙：抢到金额直接进可用余额；抢光后最少者扣整包金额自动发下一包；30分钟未抢完则已领保留、未领退回。',
                'chat_rp_type_avg' => '人均',
                'chat_rp_type_mine' => '埋雷',
                'chat_rp_type_random' => '随机红包',
                'chat_rp_mine_digit' => '雷号',
                'chat_rp_mine_hint' => '埋雷说明',
                'chat_rp_blessing_label' => '封面语',
                'chat_rp_submit' => '塞钱进红包',
                'chat_create_group_title' => '创建新群聊',
                'chat_next' => '下一步',
                'chat_group_type_title' => '群类型',
                'chat_group_type_open' => '开放群',
                'chat_group_type_open_desc' => '开放群说明',
                'chat_group_type_private' => '隐私群',
                'chat_group_type_private_desc' => '隐私群说明',
                'chat_run_mode_title' => '运行模式',
                'chat_run_mode_chat' => '聊天模式',
                'chat_run_mode_chat_desc' => '聊天模式说明',
                'chat_run_mode_grab' => '红包对战模式',
                'chat_run_mode_grab_desc' => '对战模式说明',
                'chat_create_group_hint' => '建群底部提示',
                'chat_group_name_ph' => '群名占位',
                'chat_group_notice_ph' => '群公告占位',
                'chat_member_search_ph' => '成员搜索占位',
                'chat_invite_search_ph' => '邀请搜索占位',
                'chat_rp_amount_ph' => '红包金额占位',
                'chat_rp_blessing_ph' => '祝福语占位',
                'chat_create_group_name_ph' => '建群群名占位',
                'aria_search' => '搜索无障碍',
                'aria_more' => '更多无障碍',
                'aria_community_cats' => '社群分类无障碍',
                'aria_notice_cats' => '公告分类无障碍',
                'aria_back' => '返回无障碍',
                'aria_dial' => '区号无障碍',
                'aria_collapse' => '收起无障碍',
                'aria_emoji' => '表情无障碍',
                'aria_close' => '关闭无障碍',
                'title_change_group_avatar' => '更换群头像',
                'title_toggle_avatar' => '切换头像',
            ],
            '消息中心' => [
                'chat_title'                 => '消息中心标题',
                'chat_empty'                 => '消息空态-未登录',
                'chat_empty_no_conv'         => '消息空态-无会话',
                'chat_new_private'           => '管理员发起私聊按钮',
                'chat_new_group'             => '建群按钮(已隐藏)',
                'chat_back'                  => '返回会话列表',
                'chat_send'                  => '发送按钮',
                'chat_input_placeholder'     => '输入框占位',
                'chat_admin_only_hint'       => '仅可私聊管理员提示',
                'chat_admin_welcome'         => '管理员欢迎私聊文案',
                'chat_conn_ok'               => '连接成功',
                'chat_conn_fail'             => '连接失败',
            ],
            '新手宝箱' => [
                'lottery_eyebrow'       => '宝箱眉标',
                'lottery_title'         => '宝箱标题',
                'lottery_subtitle'      => '宝箱副标题',
                'lottery_shares_locked' => '锁定份数（{shares}）',
                'lottery_aria_chest'    => '宝箱无障碍',
                'lottery_chest_hint'    => '开启提示',
                'lottery_opening'       => '开箱中',
                'lottery_result_shares' => '开箱结果（{shares}）',
                'lottery_close_btn'     => '关闭/收下按钮',
                'lottery_close_wait'    => '未开启时按钮文案',
            ],
            '闪兑模块' => [
                'exchange_tag'           => '模块标签',
                'exchange_title'         => '模块标题',
                'channel_a_name'         => '通道一名称',
                'channel_a_desc'         => '通道一说明',
                'channel_b_name'         => '通道二名称',
                'channel_b_desc'         => '通道二说明',
                'exchange_chip_label'    => '股数选择标签',
                'exchange_profit_hint'   => '收益提示HTML（{ticket_value}{count}{profit}）',
                'exchange_btn_template'  => '闪兑按钮（{count}）',
                'exchange_cta_hint'      => '闪兑说明HTML（{count}{threshold}）',
                'profile_menu_exchange'  => '我的-兑换菜单',
                'profile_menu_exchange_sub' => '我的-兑换副标题',
                'profile_exchange_title' => '兑换页标题',
                'profile_ex_r2b_title'   => '股份兑余额标题',
                'profile_ex_r2b_desc'    => '股份兑余额说明',
                'profile_ex_r2b_label'   => '股份兑余额输入标签',
                'profile_ex_r2b_btn'     => '股份兑余额按钮',
                'profile_ex_b2r_title'   => '余额兑股份标题',
                'profile_ex_b2r_desc'    => '余额兑股份说明',
                'profile_ex_b2r_label'   => '余额兑股份输入标签',
                'profile_ex_b2r_btn'     => '余额兑股份按钮',
                'profile_ex_min_hint'    => '最低下限提示（{min}）',
                'profile_ex_preview_r2b' => '股份兑余额预估（{amount}）',
                'profile_ex_preview_b2r' => '余额兑股份预估（{count}）',
                'profile_ex_closed'      => '兑换全关提示',
                'profile_ex_r2b_closed'  => '股份兑余额关闭',
                'profile_ex_b2r_closed'  => '余额兑股份关闭',
            ],
            '领取与账号回填' => [
                'claim_balance_label'    => '领取页余额标签',
                'claim_progress_ready'   => '领取进度-已达标（{threshold}）',
                'claim_progress_short'   => '领取进度-还差（{short}）',
                'claim_progress_idle'    => '领取进度-空闲（{threshold}）',
                'uid_label'              => 'UID标签',
                'uid_placeholder'        => 'UID占位符',
                'uid_submit_btn'         => '提交账号审核按钮',
                'uid_submit_pending'     => '审核中按钮',
                'uid_submit_approved'    => '已通过按钮',
                'uid_hint_idle'          => 'UID提示-未提交',
                'uid_hint_pending'       => 'UID提示-审核中',
                'uid_hint_approved'      => 'UID提示-已通过',
                'uid_hint_rejected'      => 'UID提示-未通过',
                'settle_title_low'       => '绿通按钮标题（未达标）',
                'settle_sub_low'         => '绿通按钮副标题（未达标）',
                'settle_title_high'      => '绿通按钮标题（已达标）',
                'settle_sub_high'        => '绿通按钮副标题（已达标）',
                'master_lock_title'      => '团长锁标题',
                'master_lock_desc'       => '团长锁说明',
                'master_lock_btn'        => '团长锁按钮',
            ],
            '团长二期' => [
                'phase2_master_only'              => '团长专属拦截',
                'phase2_checkin_done'             => '今日已签到',
                'phase2_confirm_violent_title'    => '放弃暴力分享标题',
                'phase2_confirm_violent_msg'      => '放弃暴力分享说明',
                'phase2_checkin_bonus_unlocked'   => '暴击已到账',
                'phase2_checkin_bonus_pending'    => '暴击锁定中',
                'phase2_checkin_success_title'    => '签到成功标题（{streak}）',
                'phase2_checkin_success_msg'      => '签到成功正文（{base}{unlocked}）',
                'phase2_checkin_unlock_hint'      => '解锁暴击提示',
                'phase2_streak_broken_title'      => '连签冻结标题',
                'phase2_streak_broken_msg'        => '连签冻结说明',
                'phase2_day7_title'               => '7天结算标题',
                'phase2_day7_msg'                 => '7天结算正文（{amount}）',
                'phase2_bonus_unlocked_title'     => '对账箱解锁标题',
                'phase2_bonus_unlocked_msg'       => '对账箱解锁正文（{amount}）',
                'phase2_streak_revived_title'     => '复活标题',
                'phase2_streak_revived_msg'       => '复活正文',
                'phase2_master_unlock_title'      => '团长解锁标题',
                'phase2_master_unlock_msg'        => '团长解锁正文',
                'phase2_urge_copy'                => '催活复制文案（{link}）',
                'phase2_honor_tier_title'         => '天梯晋级标题（{name}）',
                'phase2_honor_tier_msg'           => '天梯晋级正文',
                'phase2_honor_name_1'             => '段位名-青铜团长',
                'phase2_honor_name_2'             => '段位名-白金团长',
                'phase2_honor_name_3'             => '段位名-钻石团长',
                'phase2_honor_name_4'             => '段位名-最强王者',
                'phase2_honor_name_5'             => '段位名-荣耀王者',
                'phase2_honor_reward_full'        => '天梯奖励整行（含余额）',
                'phase2_honor_reward_rights'      => '天梯奖励股份行',
                'phase2_honor_people'             => '天梯人数括号（{count}）',
                'phase2_honor_title'              => '天梯标题',
                'phase2_honor_capped'             => '天梯封顶提示（{pack_total}）',
                'phase2_honor_progress'           => '天梯进度（{name}{need}{count}{pack_total}）',
                'phase2_honor_hint'               => '天梯默认提示',
                'phase2_checkin_streak'           => '连签标签（{streak}）',
                'phase2_checkin_ledger'           => '终极账本（{streak}{jackpot}{loss}）',
                'phase2_checkin_frozen'           => '断签冻结（{need}）',
                'phase2_checkin_revive_ready'     => '复活就绪',
                'phase2_checkin_done_btn'         => '已签到按钮（{mode}）',
                'phase2_checkin_mode_violent'     => '模式-暴力',
                'phase2_checkin_mode_normal'      => '模式-普通',
                'phase2_checkin_violent_btn'      => '暴力签到按钮',
                'phase2_checkin_normal_btn'       => '普通签到按钮',
                'phase2_checkin_toggle'           => '暴力开关（{amount}）',
                'phase2_checkin_pending'          => '对账箱等待（{amount}）',
                'phase2_checkin_pending_ok'       => '对账成功（{amount}）',
                'phase2_radar_title'              => '雷达标题',
                'phase2_radar_empty'              => '雷达空状态',
                'phase2_radar_progress'           => '雷达进度（{balance}{threshold}）',
                'phase2_radar_done'               => '雷达已达标',
                'phase2_radar_urge'               => '一键催促',
                'phase2_radar_fail'               => '雷达加载失败',
                'phase2_toast_urge_ok'            => '催活成功提示',
                'phase2_toast_promo_ok'           => '推广复制成功',
                'phase2_toast_checkin_ok'         => '签到成功提示',
                'phase2_toast_copy_fail'          => '复制失败',
                'phase2_toast_checkin_fail'       => '签到失败',
                'phase2_btn_know'                 => '弹窗-知道了',
                'phase2_btn_day7_cash'            => '弹窗-7天闪兑',
                'phase2_btn_honor_withdraw'       => '弹窗-荣誉提现',
                'phase2_btn_honor_exchange'       => '弹窗-前往闪兑',
                'phase2_btn_enter_master'         => '弹窗-进入团长',
                'phase2_btn_persist_1'            => '弹窗-坚持1元',
                'phase2_btn_reselect_violent'     => '弹窗-重选暴力',
                'phase2_share_checkin_mutex'      => '分享/签到互斥提示',
            ],
            '排行榜与留言' => [
                'leaderboard_title'   => '排行榜标题',
                'leaderboard_loading' => '排行榜加载中',
                'leaderboard_empty'   => '排行榜空状态',
                'leaderboard_fail'    => '排行榜失败',
                'leaderboard_invite_template' => '邀请数模板（{count}）',
                'leaderboard_user_fallback'   => '用户默认昵称',
                'comment_title'       => '留言区标题（{partner_count}）',
                'comment_placeholder' => '留言占位符',
                'comment_submit_btn'  => '发表按钮',
                'comment_scroll_hint' => '留言滚动提示',
                'comment_empty'       => '留言空状态',
                'comment_fail'        => '留言失败',
                'comment_user_mine'   => '我的留言昵称（{user}）',
                'comment_user_other'  => '他人留言昵称（{user}）',
            ],
            '页脚声明' => [
                'footer_line1' => '页脚第一行',
                'footer_line2' => '页脚第二行（安全承诺）',
                'footer_line3' => '页脚第三行（版权）',
            ],
            '跑马灯' => [
                'marquee_text'            => '跑马灯正文（每行一条，可含HTML）',
                'marquee_fallback'        => '跑马灯兜底',
                'marquee_fallback_prefix' => '跑马灯兜底前缀',
            ],
            '门槛阻断弹窗' => [
                'threshold_modal_title'    => '弹窗标题',
                'threshold_modal_desc'     => '弹窗说明HTML（{threshold}，含进度条DOM）',
                'threshold_modal_btn_open' => '去开户按钮（{open_account_rights}）',
                'threshold_modal_btn_later'=> '稍后再说按钮',
            ],
            '密令/客服弹窗' => [
                'withdraw_title_default' => '弹窗默认标题',
                'withdraw_title_vip'     => 'VIP领取标题',
                'withdraw_title_green'   => '绿通标题',
                'withdraw_amount_label'  => '金额标签',
                'withdraw_secret_label'  => '密令标签',
                'withdraw_secret_timer'  => '密令倒计时前缀',
                'withdraw_secret_expired'=> '密令已过期',
                'withdraw_secret_loading'=> '密令加载占位',
                'withdraw_step1'         => '客服步骤1',
                'withdraw_step2'         => '客服步骤2',
                'withdraw_step3'         => '客服步骤3',
                'withdraw_btn_cs'        => '跳转客服按钮',
                'withdraw_btn_app'       => '下载App按钮',
                'withdraw_btn_close'     => '关闭按钮',
                'withdraw_app_copy_ok'   => 'App链接复制成功',
            ],
            '提示与弹窗文案' => [
                'alert_phone_invalid'         => '手机号格式错误',
                'alert_sms_sent'              => '验证码发送成功前缀',
                'alert_phone_required'        => '请输入手机号',
                'alert_captcha_required'      => '请输入验证码',
                'alert_login_new'             => '新用户登录成功',
                'alert_login_back'            => '老用户登录成功',
                'alert_exchange_limit'        => '行权上限（{percent}{max}）',
                'alert_no_rights'             => '无可兑换股份',
                'alert_select_count'          => '请选择股数',
                'alert_uid_required'          => '请填写UID',
                'alert_open_first'            => '请先开户',
                'alert_secret_required'       => '请先生成密令',
                'alert_cs_not_configured'     => '客服未配置',
                'alert_secret_copied'         => '密令已复制',
                'alert_secret_copied_clipboard'=> '密令复制到剪贴板',
                'alert_comment_login'         => '留言需登录',
                'alert_comment_ok'            => '留言成功',
                'alert_comment_pending'       => '留言待审核',
                'alert_open_account'          => '跳转开户',
                'alert_copy_ok'               => '复制成功',
                'alert_exchange_success'      => '闪兑成功（{count}{amount}{balance}）',
                'alert_exchange_balance_only' => '仅显示余额（{balance}）',
                'alert_exchange_vip_ready'    => '达标可领取',
                'alert_exchange_vip_need_uid' => '达标需填UID',
                'alert_exchange_need_open'    => '达标需开户',
                'alert_open_reward_fail'      => '开户奖励失败',
                'alert_exchange_fail'         => '闪兑失败',
                'alert_secret_fail'           => '密令失败',
                'alert_share_fail'            => '分享失败',
                'alert_share_rewarded'        => '分享成功有奖励',
                'alert_share_copied'          => '分享文案已复制（{message}）',
                'alert_share_daily_limit'     => '分享日上限提示',
                'alert_share_cooldown_wait'   => '分享冷却提示（{minutes}）',
                'alert_share_reward_ok'       => '分享奖励发放成功',
                'alert_share_wait_default'    => '分享等待默认提示',
                'alert_comment_fail'          => '发表失败',
                'alert_sms_fail'              => '验证码失败',
                'alert_sms_hint_default'      => '验证码发送默认提示',
                'alert_login_fail'            => '登录失败',
                'alert_api_bad_response'      => '接口响应异常',
                'alert_api_request_fail'      => '请求失败默认提示',
            ],
            '接口与校验提示' => [
                'api_mobile_invalid'          => 'API-手机号不正确',
                'api_sms_mock_title'          => 'API-测试短信标题',
                'api_sms_mock_hint'           => 'API-测试验证码提示（{code}）',
                'api_sms_too_frequent'        => 'API-发送频繁',
                'api_sms_too_frequent_wait'   => 'API-发送频繁倒计时（{seconds}）',
                'api_sms_sent_ok'             => 'API-验证码已发送',
                'api_sms_send_fail'           => 'API-短信发送失败',
                'api_params_incomplete'       => 'API-参数不完整',
                'api_operation_fail'          => 'API-操作失败兜底',
                'api_sign_fail'               => 'API-签名校验失败',
                'api_bind_ok'                 => 'API-UID保存成功',
                'api_open_account_ok'         => 'API-开户奖励已发放',
                'api_secret_ok'               => 'API-密令已生成',
                'api_exchange_ok'             => 'API-闪兑成功',
                'srv_sign_secret_missing'     => '校验-签名密钥未配置',
                'srv_sign_params_missing'     => '校验-缺少签名参数',
                'srv_sign_expired'            => '校验-请求已过期',
                'srv_sign_duplicate'          => '校验-重复请求',
                'srv_sign_invalid'            => '校验-签名校验失败',
                'srv_device_limit'            => '校验-设备注册上限',
                'srv_device_fp_required'      => '校验-缺少设备指纹',
                'srv_request_id_required'     => '校验-缺少请求ID',
                'srv_duplicate_submit'        => '校验-重复提交',
                'srv_comment_empty'           => '校验-留言为空',
                'srv_comment_too_long'        => '校验-留言过长',
                'srv_comment_too_frequent'    => '校验-留言太频繁',
                'srv_user_not_found'          => '校验-用户不存在',
                'srv_captcha_invalid'         => '校验-验证码错误',
                'srv_slider_verify_fail'      => '校验-滑块验证失败',
                'srv_slider_create_fail'      => '校验-滑块生成失败',
                'srv_account_frozen'          => '校验-账号已冻结',
                'srv_login_fail'              => '校验-登录失败',
                'srv_insufficient_assets'     => '校验-资产不足',
                'srv_exchange_count_invalid'  => '校验-未选股数',
                'srv_exchange_limit'          => '校验-行权上限（{max}）',
                'srv_insufficient_rights'     => '校验-股份不足',
                'srv_rights_t1_locked'        => '校验-兑入股份T+1锁定',
                'srv_uid_required'            => '校验-需填UID',
                'srv_uid_bind_required'       => '校验-需回填UID',
                'srv_uid_format_invalid'      => '校验-UID格式不正确',
                'srv_uid_already_bound'     => '校验-UID已被绑定',
                'srv_uid_already_approved'  => '校验-UID已审核通过',
                'srv_uid_pending'           => '校验-UID审核中不可重提',
                'srv_uid_audit_empty'       => '校验-无待审UID',
                'srv_uid_verify_failed'     => '校验-主站UID不存在或未开户',
                'srv_uid_verify_not_configured' => '校验-UID校验接口未配置',
                'srv_uid_verify_unreachable'  => '校验-UID校验接口不可用',
                'srv_uid_verify_phone_mismatch' => '校验-UID与手机号不匹配',
                'srv_open_account_required'   => '校验-需先开户',
                'srv_open_account_uid_required' => '校验-开户需先绑UID',
                'comment_user_id_fallback'    => '留言用户兜底（{user_id}）',
                'time_just_now'               => '时间-刚刚',
                'time_minutes_ago'            => '时间-分钟前（{n}）',
                'time_hours_ago'              => '时间-小时前（{n}）',
            ],
            '多语言与区号' => [
                'lang_zh'                     => '语言名-中文',
                'lang_en'                     => '语言名-English',
                'lang_km'                     => '语言名-ខ្មែរ',
                'lang_id'                     => '语言名-Indonesia',
                'lang_vi'                     => '语言名-Tiếng Việt',
                'lang_ms'                     => '语言名-Melayu',
                'country_cn'                  => '国家-中国',
                'country_ph'                  => '国家-菲律宾',
                'country_kh'                  => '国家-柬埔寨',
                'country_id'                  => '国家-印尼',
                'country_vn'                  => '国家-越南',
                'country_my'                  => '国家-马来西亚',
                'login_phone_placeholder_ph'  => '菲律宾手机号占位',
                'login_phone_placeholder_kh'  => '柬埔寨手机号占位',
                'login_phone_placeholder_id'  => '印尼手机号占位',
                'login_phone_placeholder_vn'  => '越南手机号占位',
                'login_phone_placeholder_my'  => '马来西亚手机号占位',
                'aria_lang_select'            => '语言选择无障碍',
                'aria_country_select'         => '区号选择无障碍',
            ],
        ];
    }

    const JACKPOT_CACHE_KEY = 'fanshub_jackpot_amount';
    const SHARE_PRICE_CACHE_KEY = 'fanshub_share_price';

    public static function getSharePrice($tick = false)
    {
        return FansHubMarket::getSharePrice($tick);
    }

    public static function resetSharePriceCache($amount = null)
    {
        FansHubMarket::resetCaches();
        if ($amount !== null) {
            \think\Cache::set(FansHubMarket::CACHE_PRICE_FLOOR, round((float)$amount, 2), 86400 * 3650);
        }
    }

    public static function getJackpotAmount($tick = false)
    {
        return FansHubMarket::getCumulativePayout($tick);
    }

    public static function resetJackpotCache($amount = null)
    {
        FansHubMarket::resetCaches();
        if ($amount !== null) {
            \think\Cache::set(FansHubMarket::CACHE_CUMULATIVE, round((float)$amount, 2), 86400 * 3650);
        } else {
            \think\Cache::set(FansHubMarket::CACHE_CUMULATIVE, (float)self::config('jackpot_base', 1000000), 86400 * 3650);
        }
    }

    public static function jackpotPayload($tick = true, $lite = false)
    {
        return FansHubMarket::screenPayload($tick, $lite);
    }

    /**
     * 登录/进厅合并包：一次返回 config + 奖池快照 +（已登录）profile/排行榜
     * @param int $userId 0=游客
     * @param array $opts include_home / include_commission / tick_market
     */
    public static function bootstrapPayload($userId = 0, array $opts = [])
    {
        $userId = (int)$userId;
        $includeHome = !isset($opts['include_home']) || !empty($opts['include_home']);
        $includeCommission = !empty($opts['include_commission']);
        $tickMarket = !empty($opts['tick_market']);

        $out = [
            'config' => self::publicConfig(),
            'market' => self::jackpotPayload($tickMarket, true),
            'profile' => null,
            'home' => null,
            'commission' => null,
            'server_time' => time(),
        ];

        if ($userId > 0) {
            try {
                self::expireSecrets();
                $out['profile'] = self::profilePayload($userId);
            } catch (\Throwable $e) {
                $out['profile'] = null;
                $out['profile_error'] = $e->getMessage();
            }
            if ($includeHome) {
                try {
                    $out['home'] = [
                        'leaderboard' => self::inviteLeaderboard(10),
                    ];
                } catch (\Throwable $e) {
                    $out['home'] = ['leaderboard' => []];
                }
            }
            if ($includeCommission) {
                try {
                    $out['commission'] = self::commissionSummary($userId);
                } catch (\Throwable $e) {
                    $out['commission'] = null;
                }
            }
        }

        return $out;
    }

    /**
     * 钱包页合并包：余额 + 充值/提现通道（含 binds）
     */
    public static function walletBootstrapPayload($userId)
    {
        $userId = (int)$userId;
        return [
            'info'     => \app\common\library\FansHubWallet::walletInfo($userId),
            'recharge' => \app\common\library\FansHubWallet::listChannelsGrouped('recharge', $userId),
            'withdraw' => \app\common\library\FansHubWallet::listChannelsGrouped('withdraw', $userId),
            'server_time' => time(),
        ];
    }

    public static function publicConfig()
    {
        $cfg = self::config();
        $sharePrice = self::getSharePrice(false);
        $partnerCount = FansHubMarket::partnerCount(false);
        return [
            'single_ticket_value'  => $sharePrice,
            'current_share_price'  => $sharePrice,
            'share_price_max'      => (float)($cfg['share_price_max'] ?? 99.99),
            'partner_count'        => $partnerCount,
            'fission_user_count'   => $partnerCount,
            'partner_today_up'     => FansHubMarket::todayPartnerUp(),
            'price_up_pct'         => FansHubMarket::priceUpPercent(),
            'seed_total_shares'    => FansHubMarket::seedTotalShares(),
            'market_virtual_base'  => FansHubMarket::virtualBase(),
            'market_virtual_per_real' => FansHubMarket::virtualPerReal(),
            'market_daily_grow_min'=> FansHubMarket::dailyGrowMin(),
            'market_daily_grow_max'=> FansHubMarket::dailyGrowMax(),
            'withdraw_threshold'   => (float)($cfg['withdraw_threshold'] ?? 50),
            'withdraw_turnover_min'   => (float)($cfg['withdraw_turnover_min'] ?? 0),
            'withdraw_turnover_ratio' => max(0, (float)($cfg['withdraw_turnover_ratio'] ?? 1)),
            'im_member_can_create_group' => !isset($cfg['im_member_can_create_group']) || !empty($cfg['im_member_can_create_group']),
            'max_vote_percent'     => (float)($cfg['max_vote_percent'] ?? 1),
            'exchange_rights_to_balance_enabled' => self::exchangePairEnabled('rights', 'hongbao'),
            'exchange_balance_to_rights_enabled' => self::exchangePairEnabled('hongbao', 'rights'),
            'exchange_r2b_min'     => self::exchangePairMin('rights', 'hongbao'),
            'exchange_b2r_min'     => self::exchangePairMin('hongbao', 'rights'),
            'hongbao_unit_value'   => self::hongbaoUnitValue(),
            'exchange_pairs'       => (function () {
                $pairs = [];
                $assets = self::exchangeAssets();
                foreach ($assets as $from) {
                    foreach ($assets as $to) {
                        if ($from === $to) {
                            continue;
                        }
                        $code = self::exchangePairCode($from, $to);
                        $pairs[$from . '_' . $to] = [
                            'from'    => $from,
                            'to'      => $to,
                            'code'    => $code,
                            'enabled' => self::exchangePairEnabled($from, $to),
                            'min'     => self::exchangePairMin($from, $to),
                            'max'     => self::exchangePairMax($from, $to),
                        ];
                    }
                }
                return $pairs;
            })(),
            'secret_lock_seconds'  => (int)($cfg['secret_lock_seconds'] ?? 900),
            'customer_service_url' => self::utf8Safe($cfg['customer_service_url'] ?? ''),
            'app_download_url'     => self::utf8Safe($cfg['app_download_url'] ?? ''),
            'main_station_url'     => self::utf8Safe($cfg['main_station_url'] ?? ''),
            'im_ws_url'            => self::utf8Safe($cfg['im_ws_url'] ?? ''),
            'mine_compensate_rates'=> (function () {
                $rp = FansHubRedPacket::configMap();
                return [
                    5 => round(max(0.01, (float)($rp['mine_compensate_rate_5'] ?? 1.5)), 4),
                    7 => round(max(0.01, (float)($rp['mine_compensate_rate_7'] ?? 1.2)), 4),
                    9 => round(max(0.01, (float)($rp['mine_compensate_rate_9'] ?? 1.0)), 4),
                ];
            })(),
            'share_text'           => self::utf8Safe($cfg['share_text'] ?? ''),
            'invite_base_url'      => self::utf8Safe($cfg['invite_base_url'] ?? ''),
            'invite_code_offset'   => self::inviteCodeOffset(),
            'member_level_enabled' => !empty($cfg['member_level_enabled']),
            'sms_mock_enabled'     => !empty($cfg['sms_mock_enabled']),
            'sms_slider_enabled'   => !empty($cfg['sms_slider_enabled']),
            'sms_send_interval'    => self::smsSendInterval(),
            'countries'            => FansHubMobile::publicCountries(),
            'locales'              => [
                ['code' => 'zh-CN', 'label' => '中文'],
                ['code' => 'en-PH', 'label' => 'English'],
                ['code' => 'vi-VN', 'label' => 'Tiếng Việt'],
                ['code' => 'ms-MY', 'label' => 'Melayu'],
                ['code' => 'km-KH', 'label' => 'ខ្មែរ'],
                ['code' => 'id-ID', 'label' => 'Indonesia'],
            ],
            'default_locale'       => self::utf8Safe($cfg['default_locale'] ?? 'zh-CN'),
            'locale_auto_detect'   => !isset($cfg['locale_auto_detect']) || !empty($cfg['locale_auto_detect']),
            'marquee_items'        => self::parseMarqueeItems(self::utf8Safe($cfg['marquee_text'] ?? '')),
            'jackpot_base'         => (float)($cfg['jackpot_base'] ?? 1000000),
            'jackpot_current'      => self::getJackpotAmount(false),
            'jackpot_auto_grow'    => !empty($cfg['jackpot_auto_grow']),
            'jackpot_server_sync'  => !empty($cfg['jackpot_server_sync']),
            'jackpot_grow_min'     => (float)($cfg['jackpot_grow_min'] ?? 1000),
            'jackpot_grow_max'     => (float)($cfg['jackpot_grow_max'] ?? 20000),
            'jackpot_ceiling'      => (float)($cfg['jackpot_ceiling'] ?? 100000000),
            'jackpot_micro_grow_min'=> (float)($cfg['jackpot_micro_grow_min'] ?? 1),
            'jackpot_micro_grow_max'=> (float)($cfg['jackpot_micro_grow_max'] ?? 5),
            'market_seed_capital'  => (float)($cfg['market_seed_capital'] ?? 888888),
            'api_sign_enabled'     => !empty($cfg['api_sign_enabled']),
            // 浏览器 H5 不可下发签名密钥；开启签名仅适合服务端对服务端调用
            'api_sign_secret'      => '',
            'market_share_price_min' => (float)($cfg['market_share_price_min'] ?? 5),
            'market_share_price_max' => (float)($cfg['market_share_price_max'] ?? 7),
            'market_inject_factor'   => (float)($cfg['market_inject_factor'] ?? 1.3),
            'register_rights'      => (int)($cfg['register_rights'] ?? 5),
            'open_account_rights'  => (int)($cfg['open_account_rights'] ?? 2),
            'share_rights'         => (int)($cfg['share_rights'] ?? 1),
            'phase2_enabled'       => !empty($cfg['phase2_enabled']),
            'checkin_base_amount'  => (float)($cfg['checkin_base_amount'] ?? 1),
            'checkin_violent_bonus'=> (float)($cfg['checkin_violent_bonus'] ?? 4),
            'honor_tiers'          => FansHubPhase2::honorTiers(),
            'copy'                 => self::getH5Copy(),
        ];
    }

    public static function maskMobile($mobile)
    {
        $mobile = (string)$mobile;
        if ($mobile === '') {
            return '-';
        }
        if ($mobile[0] === '+') {
            $digits = preg_replace('/\D+/', '', $mobile);
            if (strlen($digits) >= 7) {
                return '+' . substr($digits, 0, min(4, strlen($digits) - 4)) . '****' . substr($digits, -4);
            }
        }
        if (strlen($mobile) >= 11) {
            return substr($mobile, 0, 3) . '****' . substr($mobile, -4);
        }
        return $mobile !== '' ? '***' : '-';
    }

    public static function dashboardStats($start = 0, $end = 0)
    {
        $prefix = config('database.prefix');
        $timeWhere = function ($query, $field = 'createtime') use ($start, $end) {
            if ($start > 0 && $end > 0) {
                $query->where($field, 'between', [$start, $end]);
            }
        };

        $registered = Account::where(function ($q) use ($timeWhere) {
            $timeWhere($q);
        })->count();

        $stage2Query = Account::where('flow_stage', 'stage2');
        if ($start > 0 && $end > 0) {
            $stage2Query->where('updatetime', 'between', [$start, $end]);
        }
        $stage2 = $stage2Query->count();

        $inviteQuery = Invite::where('id', '>', 0);
        $timeWhere($inviteQuery);
        $inviteTotal = $inviteQuery->count();

        $secretQuery = Secret::where('id', '>', 0);
        $timeWhere($secretQuery);
        $secretCreated = $secretQuery->count();

        $secretVipQuery = Secret::where('id', '>', 0)->where('tier', 'VIP');
        $timeWhere($secretVipQuery);
        $secretVip = $secretVipQuery->count();

        $ledgerTable = $prefix . 'fans_ledger';
        $timeSql = ($start > 0 && $end > 0) ? ' AND createtime BETWEEN ' . (int)$start . ' AND ' . (int)$end : '';
        $openAccount = (int)Db::query("SELECT COUNT(DISTINCT user_id) AS c FROM `{$ledgerTable}` WHERE type='open_account'{$timeSql}")[0]['c'];
        $exchanged = (int)Db::query("SELECT COUNT(DISTINCT user_id) AS c FROM `{$ledgerTable}` WHERE type='exchange'{$timeSql}")[0]['c'];
        $shared = (int)Db::query("SELECT COUNT(DISTINCT user_id) AS c FROM `{$ledgerTable}` WHERE type IN ('share','invite'){$timeSql}")[0]['c'];

        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $loginToday = LoginLog::where('createtime', '>=', $todayStart)->count();
        $newToday = Account::where('createtime', '>=', $todayStart)->count();

        $rate = function ($num, $den) {
            return $den > 0 ? round($num / $den * 100, 1) : 0;
        };

        return [
            'registered'      => $registered,
            'stage2'          => $stage2,
            'open_account'    => $openAccount,
            'exchanged'       => $exchanged,
            'shared'          => $shared,
            'invite_total'    => $inviteTotal,
            'secret_created'  => $secretCreated,
            'secret_vip'      => $secretVip,
            'login_today'     => $loginToday,
            'new_today'       => $newToday,
            'balance_sum'     => round((float)Account::sum('hongbao'), 2),
            'rights_sum'      => round((float)Account::sum('rights'), 2),
            'secret_pending'  => Secret::where('status', 'pending')->count(),
            'rates'           => [
                'stage2'       => $rate($stage2, $registered),
                'open_account' => $rate($openAccount, $registered),
                'exchange'     => $rate($exchanged, $registered),
                'invite'       => $rate($inviteTotal, $registered),
                'secret'       => $rate($secretCreated, $registered),
            ],
            'leaderboard'     => self::inviteLeaderboard(10),
            'phase2'          => FansHubPhase2::dashboardStats($start, $end),
        ];
    }

    public static function inviteLeaderboard($limit = 20)
    {
        $limit = min(50, max(1, (int)$limit));
        $rows = Db::name('fans_invite')
            ->field('inviter_user_id, COUNT(*) AS invite_count')
            ->group('inviter_user_id')
            ->order('invite_count', 'desc')
            ->limit($limit)
            ->select();
        $result = [];
        $rank = 1;
        foreach ($rows as $row) {
            $user = User::get($row['inviter_user_id']);
            $result[] = [
                'rank'         => $rank++,
                'user_id'      => (int)$row['inviter_user_id'],
                'mobile_mask'  => self::maskMobile($user ? $user->mobile : ''),
                'invite_count' => (int)$row['invite_count'],
            ];
        }
        return $result;
    }

    public static function inviteRankForUser($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return null;
        }
        $count = (int)Invite::where('inviter_user_id', $userId)->count();
        if ($count <= 0) {
            return ['rank' => 0, 'invite_count' => 0];
        }
        $prefix = config('database.prefix');
        $table = $prefix . 'fans_invite';
        $sql = "SELECT COUNT(*) AS c FROM (SELECT inviter_user_id FROM `{$table}` GROUP BY inviter_user_id HAVING COUNT(*) > ?) t";
        $better = (int)Db::query($sql, [$count])[0]['c'];
        return [
            'rank'         => $better + 1,
            'invite_count' => $count,
        ];
    }

    public static function inviteCodeOffset()
    {
        // 邀请码即八位用户 ID；偏移量固定为 0（保留配置项兼容旧后台字段）
        return max(0, (int)self::config('invite_code_offset', 0));
    }

    /**
     * 分配不重复的随机八位用户 ID（10000000–99999999）
     */
    public static function allocUserId()
    {
        $min = 10000000;
        $max = 99999999;
        for ($i = 0; $i < 80; $i++) {
            $id = random_int($min, $max);
            if ($id === FansHubDefaultCs::userId()) {
                continue;
            }
            if (User::get($id)) {
                continue;
            }
            if (Db::name('fans_account')->where('id', $id)->find()) {
                continue;
            }
            if (Db::name('fans_account')->where('user_id', $id)->find()) {
                continue;
            }
            return $id;
        }
        throw new Exception('无法分配唯一用户ID，请重试');
    }

    /** 对外邀请码 = 八位会员 user_id */
    public static function encodeInviteCode($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return '';
        }
        $offset = self::inviteCodeOffset();
        return (string)($userId + $offset);
    }

    /** ?code=八位用户ID → 邀请人 user_id（offset=0 时即原样） */
    public static function decodeInviteCode($publicCode)
    {
        $publicCode = trim((string)$publicCode);
        if ($publicCode === '' || !preg_match('/^\d+$/', $publicCode)) {
            return 0;
        }
        $num = (int)$publicCode;
        if ($num <= 0) {
            return 0;
        }
        $offset = self::inviteCodeOffset();
        if ($offset > 0 && $num >= $offset) {
            return $num - $offset;
        }
        return $num;
    }

    public static function defaultMemberLevels()
    {
        return [
            1 => ['name' => '普通会员', 'invite_reward' => 1.0],
            2 => ['name' => '银牌会员', 'invite_reward' => 2.0],
            3 => ['name' => '金牌会员', 'invite_reward' => 5.0],
        ];
    }

    public static function memberLevels()
    {
        $cfg = self::config('member_levels');
        if (!is_array($cfg) || !$cfg) {
            return self::defaultMemberLevels();
        }
        $out = [];
        foreach ($cfg as $level => $item) {
            $level = (int)$level;
            if ($level <= 0 || !is_array($item)) {
                continue;
            }
            $out[$level] = [
                'name'          => trim((string)($item['name'] ?? ('等级' . $level))),
                'invite_reward' => (float)($item['invite_reward'] ?? 1),
            ];
        }
        if (!$out) {
            return self::defaultMemberLevels();
        }
        ksort($out);
        return $out;
    }

    public static function memberLevelsToJson($levels = null)
    {
        if ($levels === null) {
            $levels = self::memberLevels();
        }
        return json_encode($levels, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public static function parseMemberLevelsJson($raw)
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return self::defaultMemberLevels();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \Exception('会员等级配置 JSON 格式无效');
        }
        return self::parseMemberLevelsArray($decoded, true);
    }

    public static function parseMemberLevelsArray($rows, $fromJsonKeys = false)
    {
        if (!is_array($rows) || !$rows) {
            throw new \Exception('至少配置一个会员等级');
        }
        $out = [];
        foreach ($rows as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $level = $fromJsonKeys ? (int)$key : (int)($item['level'] ?? $key);
            if ($level <= 0) {
                continue;
            }
            if (isset($out[$level])) {
                throw new \Exception('等级 ' . $level . ' 重复');
            }
            $out[$level] = [
                'name'          => trim((string)($item['name'] ?? ('等级' . $level))),
                'invite_reward' => max(0, (float)($item['invite_reward'] ?? 0)),
            ];
        }
        if (!$out) {
            throw new \Exception('至少配置一个会员等级');
        }
        ksort($out);
        return $out;
    }

    public static function memberLevelStats()
    {
        $prefix = config('database.prefix');
        $table = $prefix . 'fans_account';
        $rows = Db::query("SELECT `member_level`, COUNT(*) AS `cnt` FROM `{$table}` GROUP BY `member_level` ORDER BY `member_level` ASC");
        $levels = self::memberLevels();
        $result = [];
        foreach ($rows as $row) {
            $lv = (int)$row['member_level'];
            $result[] = [
                'level'         => $lv,
                'name'          => self::memberLevelName($lv),
                'count'         => (int)$row['cnt'],
                'invite_reward' => (float)($levels[$lv]['invite_reward'] ?? 0),
            ];
        }
        return $result;
    }

    public static function memberLevelName($level)
    {
        $levels = self::memberLevels();
        $level = (int)$level;
        return (string)($levels[$level]['name'] ?? ('等级' . $level));
    }

    public static function inviteRewardForUser($userId)
    {
        if (!self::config('member_level_enabled')) {
            return (float)self::config('share_rights', 1);
        }
        $account = self::getOrCreateAccount($userId);
        $level = (int)($account->member_level ?? self::config('default_member_level', 1));
        $levels = self::memberLevels();
        if (isset($levels[$level])) {
            return (float)$levels[$level]['invite_reward'];
        }
        return (float)self::config('share_rights', 1);
    }

    public static function verifyApiSign($request)
    {
        if (!self::config('api_sign_enabled')) {
            return;
        }
        $secret = (string)self::config('api_sign_secret', '');
        if ($secret === '') {
            self::throwCopy('srv_sign_secret_missing');
        }
        $ts = (int)$request->header('x-fanshub-timestamp');
        if ($ts <= 0) {
            $ts = (int)$request->header('X-Fanshub-Timestamp');
        }
        $nonce = (string)$request->header('x-fanshub-nonce');
        if ($nonce === '') {
            $nonce = (string)$request->header('X-Fanshub-Nonce');
        }
        $sign = strtolower((string)$request->header('x-fanshub-sign'));
        if ($sign === '') {
            $sign = strtolower((string)$request->header('X-Fanshub-Sign'));
        }
        $ttl = max(60, (int)self::config('api_sign_ttl', 300));
        if ($ts <= 0 || $nonce === '' || $sign === '') {
            self::throwCopy('srv_sign_params_missing');
        }
        if (abs(time() - $ts) > $ttl) {
            self::throwCopy('srv_sign_expired');
        }
        $cacheKey = 'fanshub_nonce_' . md5($nonce);
        if (\think\Cache::get($cacheKey)) {
            self::throwCopy('srv_sign_duplicate');
        }
        $method = strtoupper((string)$request->method());
        $path = '/' . ltrim((string)$request->path(), '/');
        $body = (string)$request->getContent();
        $payload = $ts . "\n" . $nonce . "\n" . $method . "\n" . $path . "\n" . $body;
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $sign)) {
            self::throwCopy('srv_sign_invalid');
        }
        \think\Cache::set($cacheKey, 1, $ttl);
    }

    public static function assertDeviceAllowed($fingerprint, $userId = 0)
    {
        if (!self::config('device_fp_limit_enabled')) {
            return;
        }
        $fingerprint = strtolower(trim((string)$fingerprint));
        if ($fingerprint === '') {
            self::throwCopy('srv_device_fp_required');
        }
        $max = (int)self::config('device_fp_max_accounts', 3);
        if ($max <= 0) {
            return;
        }
        $userIds = LoginLog::where('device_fingerprint', $fingerprint)->column('user_id');
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $userId = (int)$userId;
        if ($userId > 0 && in_array($userId, $userIds, true)) {
            return;
        }
        if (count($userIds) >= $max) {
            self::throwCopy('srv_device_limit');
        }
    }

    public static function assertIdempotent($userId, $action, $requestKey, $required = false)
    {
        $requestKey = trim((string)$requestKey);
        if ($requestKey === '') {
            if ($required) {
                self::throwCopy('srv_request_id_required');
            }
            return;
        }
        $exists = Idempotent::where([
            'user_id'     => $userId,
            'action'      => $action,
            'request_key' => $requestKey,
        ])->find();
        if ($exists) {
            self::throwCopy('srv_duplicate_submit');
        }
        try {
            Idempotent::create([
                'user_id'     => $userId,
                'action'      => $action,
                'request_key' => $requestKey,
                'createtime'  => time(),
            ]);
        } catch (\Throwable $e) {
            self::throwCopy('srv_duplicate_submit');
        }
    }

    public static function logLogin($userId, $fingerprint = '')
    {
        LoginLog::create([
            'user_id'            => $userId,
            'ip'                 => (string)request()->ip(),
            'user_agent'         => substr((string)request()->server('HTTP_USER_AGENT', ''), 0, 255),
            'device_fingerprint' => substr(strtolower(trim((string)$fingerprint)), 0, 64),
            'createtime'         => time(),
        ]);
    }

    public static function recordTask($userId, $taskType, $rights = 0, $balance = 0, $channel = '', $extra = '')
    {
        Task::create([
            'user_id'    => $userId,
            'task_type'  => (string)$taskType,
            'channel'    => (string)$channel,
            'rights'     => (float)$rights,
            'balance'    => (float)$balance,
            'extra'      => (string)$extra,
            'ip'         => (string)request()->ip(),
            'createtime' => time(),
        ]);
    }

    public static function parseMarqueeItems($text)
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
        $lines = explode("\n", $text);
        $items = [];
        foreach ($lines as $line) {
            $line = trim(self::utf8Safe($line));
            if ($line !== '') {
                $items[] = $line;
            }
        }
        return $items;
    }

    public static function formatRelativeTime($timestamp)
    {
        $diff = time() - (int)$timestamp;
        if ($diff < 60) {
            return self::h5CopyText('time_just_now');
        }
        if ($diff < 3600) {
            return self::h5CopyText('time_minutes_ago', ['n' => floor($diff / 60)]);
        }
        if ($diff < 86400) {
            return self::h5CopyText('time_hours_ago', ['n' => floor($diff / 3600)]);
        }
        return date('m-d H:i', $timestamp);
    }

    public static function listComments($page = 1, $limit = 20)
    {
        $page = max(1, (int)$page);
        $limit = min(50, max(1, (int)$limit));
        $table = (new Comment())->getTable();
        $query = Comment::with(['user'])
            ->where($table . '.status', 'approved')
            ->order($table . '.id', 'desc');
        $total = (clone $query)->count();
        $rows = $query->page($page, $limit)->select();
        $list = [];
        foreach ($rows as $row) {
            $mobile = $row->user ? (string)$row->user->mobile : '';
            $mask = self::maskMobile($mobile);
            if ($mask === '-' && $row->user_id) {
                $mask = self::h5CopyText('comment_user_id_fallback', ['user_id' => $row->user_id]);
            }
            $list[] = [
                'id'      => (int)$row->id,
                'user'    => $mask,
                'text'    => (string)$row->content,
                'time'    => self::formatRelativeTime($row->createtime),
            ];
        }
        return ['total' => $total, 'list' => $list, 'page' => $page, 'limit' => $limit];
    }

    public static function submitComment($userId, $content)
    {
        $content = trim((string)$content);
        if ($content === '') {
            self::throwCopy('srv_comment_empty');
        }
        if (mb_strlen($content) > 500) {
            self::throwCopy('srv_comment_too_long');
        }
        $recent = Comment::where('user_id', $userId)
            ->where('createtime', '>', time() - 60)
            ->count();
        if ($recent > 0) {
            self::throwCopy('srv_comment_too_frequent');
        }
        $autoApprove = !empty(self::config('comment_auto_approve'));
        $now = time();
        $comment = Comment::create([
            'user_id'    => $userId,
            'content'    => $content,
            'status'     => $autoApprove ? 'approved' : 'pending',
            'createtime' => $now,
            'updatetime' => $now,
        ]);
        $user = User::get($userId);
        $mobile = (string)($user->mobile ?? '');
        $mask = self::maskMobile($mobile);
        if ($mask === '-' || $mask === '***') {
            $mask = self::h5CopyText('comment_user_id_fallback', ['user_id' => $userId]);
        }
        return [
            'id'         => (int)$comment->id,
            'status'     => (string)$comment->status,
            'user'       => $mask,
            'text'       => $content,
            'time'       => self::h5CopyText('time_just_now'),
            'auto_show'  => $autoApprove,
        ];
    }

    /**
     * 仅默认客服（88888888）写入欢迎私聊（幂等）；其它托管账号不自动出现在新用户会话里
     */
    public static function seedImAdminConversations($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return 0;
        }
        $adminId = FansHubDefaultCs::userId();
        if ($adminId <= 0 || $adminId === $userId) {
            return 0;
        }
        try {
            FansHubDefaultCs::ensureAccount();
        } catch (\Throwable $e) {
        }
        $now = time();
        $welcome = '';
        try {
            $agent = Db::name('chat_agent_accounts')->where('user_id', $adminId)->where('status', 1)->find();
            if ($agent) {
                $welcome = trim((string)($agent['friend_reply'] ?? ''));
            }
        } catch (\Throwable $e2) {
        }
        if ($welcome === '') {
            $welcome = trim((string)self::config('im_cs_friend_reply', ''));
        }
        if ($welcome === '') {
            $welcome = self::h5CopyText('chat_admin_welcome');
        }
        if ($welcome === '' || $welcome === 'chat_admin_welcome') {
            $welcome = "您好，欢迎来到红宝！\n我是红宝官方客服，将竭诚为您服务。\n如您在使用过程中需要任何帮助，请随时联系我，我会及时为您解答与处理。";
        }
        $a = min($adminId, $userId);
        $b = max($adminId, $userId);
        $conv = $a . '_' . $b;
        $exists = Db::name('chat_messages')
            ->where(['conversation_type' => 1, 'conversation_id' => $conv, 'status' => 1])
            ->value('id');
        if ($exists) {
            return 0;
        }
        Db::name('chat_messages')->insert([
            'msg_id'            => sprintf('w%d%d%04d', $adminId, $userId, mt_rand(0, 9999)),
            'conversation_type' => 1,
            'conversation_id'   => $conv,
            'group_id'          => 0,
            'from_user_id'      => $adminId,
            'to_user_id'        => $userId,
            'msg_type'          => 1,
            'content'           => $welcome,
            'extra'             => null,
            'status'            => 1,
            'createtime'        => $now,
        ]);
        return 1;
    }

    public static function getOrCreateAccount($userId)
    {
        $account = Account::where('user_id', $userId)->find();
        if ($account) {
            return $account;
        }
        $now = time();
        $rights = (float)self::config('register_rights', 5);
        Db::startTrans();
        try {
            // id 即会员ID（与 fa_user.id / user_id 相同），不再使用另一套自增会员号
            $account = Account::create([
                'id'                     => (int)$userId,
                'user_id'                => (int)$userId,
                'rights'                 => $rights,
                'balance'                => 0,
                'main_uid'               => '',
                'main_uid_pending'       => '',
                'main_uid_audit'         => '',
                'main_uid_reject_reason' => '',
                'flow_stage'             => 'stage1',
                'member_level'           => max(1, (int)self::config('default_member_level', 1)),
                'status'                 => 'normal',
                'createtime'             => $now,
                'updatetime'             => $now,
            ]);
            Ledger::create([
                'user_id'        => $userId,
                'type'           => 'register',
                'rights_change'  => $rights,
                'balance_change' => 0,
                'rights_after'   => $rights,
                'balance_after'  => 0,
                'remark'         => '新用户注册赠送',
                'admin_id'       => 0,
                'createtime'     => $now,
            ]);
            Db::commit();
            FansHubMarket::onRealUserJoined();
            if ($rights > 0) {
                FansHubMarket::onSharesGranted($rights);
            }
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return $account;
    }

    public static function profilePayload($userId)
    {
        $user = User::get($userId);
        if (!$user) {
            self::throwCopy('srv_user_not_found');
        }
        $account = self::getOrCreateAccount($userId);
        $lockSnap = self::releaseExpiredRightsLock($account, true);
        $mobile = (string)$user->mobile;
        $payload = [
            'user_id'     => (int)$userId,
            'mobile'      => $mobile,
            'mobile_mask' => self::maskMobile($mobile),
            'nickname'    => (string)($user->nickname ?? ''),
            'username'    => (string)($user->username ?? ''),
            'avatar'      => (string)($user->avatar ?? ''),
            'avatar_url'  => $user->avatar ? cdnurl((string)$user->avatar, true) : '',
            'rights'      => (float)$account->rights,
            'rights_locked'=> (float)$lockSnap['locked'],
            'rights_free' => (float)$lockSnap['free'],
            'rights_lock_day' => $lockSnap['lock_day'] !== '' ? $lockSnap['lock_day'] : null,
            // balance 兼容字段：已并入红宝（可用，不含冻结）
            'balance'     => (float)($account->hongbao ?? 0),
            'hongbao'     => (float)($account->hongbao ?? 0),
            'hongbao_frozen' => (float)($account->hongbao_frozen ?? 0),
            'hongbao_total'  => round((float)($account->hongbao ?? 0) + (float)($account->hongbao_frozen ?? 0), 2),
            'turnover'    => (float)($account->turnover ?? 0),
            'main_uid'              => (string)$account->main_uid,
            'main_uid_pending'      => (string)($account->main_uid_pending ?? ''),
            'main_uid_audit'        => (string)($account->main_uid_audit ?? ''),
            'main_uid_reject_reason'=> (string)($account->main_uid_reject_reason ?? ''),
            'flow_stage'            => (string)$account->flow_stage,
            'status'      => (string)$account->status,
            'invite_code' => self::encodeInviteCode($userId),
            'jointime'    => (int)($user->jointime ?? 0),
            'logintime'   => (int)($user->logintime ?? 0),
            'loginip'     => (string)($user->loginip ?? ''),
            'invite_rank' => self::inviteRankForUser($userId),
            'has_pay_password' => self::hasPayPassword($userId),
        ];
        if (!empty(self::config('member_level_enabled'))) {
            $level = (int)($account->member_level ?? 1);
            $payload['member_level'] = $level;
            $payload['member_level_name'] = self::memberLevelName($level);
            $payload['invite_reward'] = self::inviteRewardForUser($userId);
        }
        return FansHubPhase2::enrichProfile($userId, $payload);
    }

    /**
     * 更新昵称 / 头像 URL
     */
    public static function updateProfile($userId, array $data)
    {
        $user = User::get((int)$userId);
        if (!$user) {
            self::throwCopy('srv_user_not_found');
        }
        $update = [];
        if (array_key_exists('nickname', $data)) {
            $nickname = trim((string)$data['nickname']);
            if ($nickname === '') {
                self::throwCopy('api_nickname_required');
            }
            if (mb_strlen($nickname) > 30) {
                self::throwCopy('api_nickname_too_long');
            }
            $update['nickname'] = $nickname;
        }
        if (array_key_exists('avatar', $data) && $data['avatar'] !== null && $data['avatar'] !== '') {
            $avatar = trim((string)$data['avatar']);
            if (mb_strlen($avatar) > 255) {
                self::throwCopy('api_avatar_invalid');
            }
            $update['avatar'] = $avatar;
        }
        if (!$update) {
            self::throwCopy('api_params_incomplete');
        }
        $update['updatetime'] = time();
        $user->save($update);
        return self::profilePayload($userId);
    }

    /**
     * 上传并设置头像
     */
    public static function uploadAvatar($userId, $file)
    {
        $userId = (int)$userId;
        $user = User::get($userId);
        if (!$user) {
            self::throwCopy('srv_user_not_found');
        }
        if (empty($file)) {
            self::throwCopy('api_avatar_required');
        }
        $info = $file->getInfo();
        $size = (int)($info['size'] ?? 0);
        if ($size <= 0 || $size > 2097152) {
            self::throwCopy('api_avatar_too_large');
        }
        $suffix = strtolower(pathinfo((string)($info['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($suffix, ['gif', 'png', 'jpg', 'jpeg', 'webp', 'bmp'], true)) {
            self::throwCopy('api_avatar_type_invalid');
        }
        $upload = new Upload($file);
        $md5 = md5_file($info['tmp_name']);
        $savekey = '/uploads/avatars/' . $userId . '/' . $md5 . '.' . $suffix;
        try {
            $attachment = $upload->upload($savekey);
        } catch (UploadException $e) {
            throw new Exception($e->getMessage() ?: self::h5CopyText('api_operation_fail'));
        }
        $url = (string)$attachment->url;
        $user->save(['avatar' => $url, 'updatetime' => time()]);
        $profile = self::profilePayload($userId);
        return [
            'url'      => $url,
            'fullurl'  => cdnurl($url, true),
            'profile'  => $profile,
        ];
    }

    /**
     * 修改密码：旧密码 或 短信验证码
     * @return array{need_relogin:bool,profile:array}
     */
    public static function changePassword($userId, $newPassword, $mode, $oldPassword = '', $captcha = '')
    {
        $userId = (int)$userId;
        $user = User::get($userId);
        if (!$user) {
            self::throwCopy('srv_user_not_found');
        }
        $newPassword = (string)$newPassword;
        if (strlen($newPassword) < 6 || strlen($newPassword) > 32) {
            self::throwCopy('api_password_length');
        }
        $mode = strtolower(trim((string)$mode));
        if (!in_array($mode, ['old', 'sms'], true)) {
            self::throwCopy('api_params_incomplete');
        }
        $auth = \app\common\library\Auth::instance();
        if ($mode === 'old') {
            if ($oldPassword === '') {
                self::throwCopy('api_old_password_required');
            }
            if ($user->password !== $auth->getEncryptPassword($oldPassword, $user->salt)) {
                self::throwCopy('api_old_password_wrong');
            }
        } else {
            $mobile = (string)$user->mobile;
            if ($mobile === '' || $captcha === '') {
                self::throwCopy('api_params_incomplete');
            }
            if (!self::verifyCaptcha($mobile, $captcha, 'fanshub_login')) {
                self::throwCopy('api_sms_code_wrong');
            }
            Sms::flush($mobile, 'fanshub_login');
            $alt = FansHubMobile::smsRecipient($mobile);
            if ($alt !== $mobile) {
                Sms::flush($alt, 'fanshub_login');
            }
        }
        $salt = Random::alnum();
        $enc = $auth->getEncryptPassword($newPassword, $salt);
        $user->save([
            'password'     => $enc,
            'salt'         => $salt,
            'loginfailure' => 0,
            'updatetime'   => time(),
        ]);
        // 改密后使当前 token 失效，前端需重新登录
        $token = $auth->getToken();
        if ($token) {
            \app\common\library\Token::delete($token);
        }
        return [
            'need_relogin' => true,
            'profile'      => self::profilePayload($userId),
        ];
    }

    public static function hasPayPassword($userId)
    {
        $account = self::getOrCreateAccount((int)$userId);
        return $account && trim((string)($account->pay_password ?? '')) !== '';
    }

    protected static function encryptPayPassword($password, $salt)
    {
        $auth = \app\common\library\Auth::instance();
        return $auth->getEncryptPassword((string)$password, (string)$salt);
    }

    /**
     * 首次设置支付密码（无需短信）
     */
    public static function setPayPassword($userId, $password)
    {
        $userId = (int)$userId;
        $password = (string)$password;
        if (strlen($password) < 6 || strlen($password) > 32) {
            self::throwCopy('api_password_length');
        }
        $account = self::getOrCreateAccount($userId);
        if (trim((string)($account->pay_password ?? '')) !== '') {
            throw new \RuntimeException(FansHubService::h5CopyText('api_pay_password_already_set') ?: '已设置支付密码，请通过短信验证修改');
        }
        $salt = Random::alnum();
        $account->save([
            'pay_password' => self::encryptPayPassword($password, $salt),
            'pay_salt'     => $salt,
            'updatetime'   => time(),
        ]);
        return self::profilePayload($userId);
    }

    /**
     * 修改支付密码（需短信验证码）
     */
    public static function changePayPassword($userId, $password, $captcha)
    {
        $userId = (int)$userId;
        $password = (string)$password;
        $captcha = trim((string)$captcha);
        if (strlen($password) < 6 || strlen($password) > 32) {
            self::throwCopy('api_password_length');
        }
        if ($captcha === '') {
            self::throwCopy('api_params_incomplete');
        }
        $account = self::getOrCreateAccount($userId);
        if (trim((string)($account->pay_password ?? '')) === '') {
            // 未设置时走首次设置，不要求短信
            return self::setPayPassword($userId, $password);
        }
        $user = User::get($userId);
        if (!$user) {
            self::throwCopy('srv_user_not_found');
        }
        $mobile = (string)$user->mobile;
        if ($mobile === '' || !self::verifyCaptcha($mobile, $captcha, 'fanshub_login')) {
            self::throwCopy('api_sms_code_wrong');
        }
        Sms::flush($mobile, 'fanshub_login');
        $alt = FansHubMobile::smsRecipient($mobile);
        if ($alt !== $mobile) {
            Sms::flush($alt, 'fanshub_login');
        }
        $salt = Random::alnum();
        $account->save([
            'pay_password' => self::encryptPayPassword($password, $salt),
            'pay_salt'     => $salt,
            'updatetime'   => time(),
        ]);
        return self::profilePayload($userId);
    }

    /**
     * 校验支付密码（提现 / 绑定地址）
     */
    public static function assertPayPassword($userId, $password)
    {
        $userId = (int)$userId;
        $password = (string)$password;
        $account = self::getOrCreateAccount($userId);
        if (trim((string)($account->pay_password ?? '')) === '') {
            throw new \RuntimeException(FansHubService::h5CopyText('api_pay_password_required_set') ?: '请先设置支付密码');
        }
        if ($password === '') {
            throw new \RuntimeException(FansHubService::h5CopyText('api_pay_password_required') ?: '请输入支付密码');
        }
        $enc = self::encryptPayPassword($password, (string)($account->pay_salt ?? ''));
        if (!hash_equals((string)$account->pay_password, $enc)) {
            throw new \RuntimeException(FansHubService::h5CopyText('api_pay_password_wrong') ?: '支付密码错误');
        }
    }

    public static function smsSendInterval()
    {
        return max(30, (int)self::config('sms_send_interval', 60));
    }

    protected static function smsSendCacheKey($mobile)
    {
        return 'fanshub_sms_sent:' . $mobile;
    }

    protected static function smsIpCacheKey()
    {
        return 'fanshub_sms_ip:' . request()->ip();
    }

    public static function getSmsLastSentTime($mobile)
    {
        $mobile = trim((string)$mobile);
        $last = 0;
        $cached = \think\Cache::get(self::smsSendCacheKey($mobile));
        if ($cached) {
            $last = max($last, (int)$cached);
        }
        $record = \app\common\library\Sms::get($mobile, 'fanshub_login');
        if ($record && !empty($record['createtime'])) {
            $last = max($last, (int)$record['createtime']);
        }
        $alt = FansHubMobile::smsRecipient($mobile);
        if ($alt !== $mobile) {
            $record = \app\common\library\Sms::get($alt, 'fanshub_login');
            if ($record && !empty($record['createtime'])) {
                $last = max($last, (int)$record['createtime']);
            }
        }
        return $last;
    }

    public static function getSmsRetryAfter($mobile)
    {
        $last = self::getSmsLastSentTime($mobile);
        if ($last <= 0) {
            return 0;
        }
        $remain = self::smsSendInterval() - (time() - $last);
        return $remain > 0 ? (int)$remain : 0;
    }

    public static function assertSmsIpAllowed()
    {
        $max = (int)self::config('sms_ip_hourly_max', 10);
        if ($max <= 0) {
            return;
        }
        $count = (int)\think\Cache::get(self::smsIpCacheKey());
        if ($count >= $max) {
            self::throwCopy('api_sms_too_frequent');
        }
    }

    public static function markSmsSent($mobile)
    {
        $mobile = trim((string)$mobile);
        $interval = self::smsSendInterval();
        \think\Cache::set(self::smsSendCacheKey($mobile), time(), $interval + 30);
        $ipKey = self::smsIpCacheKey();
        $count = (int)\think\Cache::get($ipKey);
        \think\Cache::set($ipKey, $count + 1, 3600);
    }

    public static function verifyCaptcha($mobile, $captcha, $event = 'fanshub_login')
    {
        if (self::config('sms_mock_enabled') && $captcha === (string)self::config('sms_mock_code', '123456')) {
            return true;
        }
        if (Sms::check($mobile, $captcha, $event)) {
            return true;
        }
        $alt = FansHubMobile::smsRecipient($mobile);
        if ($alt !== $mobile && Sms::check($alt, $captcha, $event)) {
            return true;
        }
        return false;
    }

    protected static function findUserByMobile($mobile)
    {
        $mobile = trim((string)$mobile);
        if ($mobile === '') {
            return null;
        }
        $user = User::getByMobile($mobile);
        if ($user) {
            return $user;
        }
        if ($mobile[0] === '+') {
            foreach (FansHubMobile::countries() as $code => $item) {
                $prefix = '+' . $item['dial'];
                if (strpos($mobile, $prefix) !== 0) {
                    continue;
                }
                $national = substr($mobile, strlen($prefix));
                if ($national === '') {
                    continue;
                }
                $legacy = User::getByMobile($national);
                if ($legacy) {
                    return $legacy;
                }
            }
        }
        return null;
    }

    public static function loginOrRegister($mobile, $captcha, $inviteCode = '', $deviceFingerprint = '')
    {
        $mobile = FansHubMobile::canonical(trim((string)$mobile));
        if ($mobile === '') {
            self::throwCopy('api_mobile_invalid');
        }
        if (!self::verifyCaptcha($mobile, $captcha)) {
            self::throwCopy('srv_captcha_invalid');
        }
        $user = self::findUserByMobile($mobile);
        $auth = Auth::instance();
        if ($user) {
            if ($user->status !== 'normal') {
                self::throwCopy('srv_account_frozen');
            }
            if ($mobile !== '' && (string)$user->mobile !== $mobile) {
                $user->mobile = $mobile;
                $user->save();
            }
            $ret = $auth->direct($user->id);
        } else {
            self::assertDeviceAllowed($deviceFingerprint, 0);
            $ret = $auth->register($mobile, Random::alnum(), '', $mobile, []);
            $user = self::findUserByMobile($mobile);
        }
        if (!$ret || !$user) {
            $err = trim((string)$auth->getError());
            throw new Exception($err !== '' ? $err : self::h5CopyText('srv_login_fail'));
        }
        Sms::flush($mobile, 'fanshub_login');
        $isNew = false;
        $account = Account::where('user_id', $user->id)->find();
        if (!$account) {
            $isNew = true;
            self::getOrCreateAccount($user->id);
            self::seedImAdminConversations((int)$user->id);
            try {
                FansHubDefaultCs::ensureFriendForUser((int)$user->id);
            } catch (\Throwable $eCs) {
            }
            try {
                FansHubOfficialStats::bumpMembers(1);
            } catch (\Throwable $eBump) {
            }
        } else {
            // 老用户补齐管理员会话 / 默认客服好友（幂等）
            self::seedImAdminConversations((int)$user->id);
            try {
                FansHubDefaultCs::ensureFriendForUser((int)$user->id);
            } catch (\Throwable $eCs2) {
            }
        }
        if ($isNew && $inviteCode !== '') {
            $inviterId = self::decodeInviteCode($inviteCode);
            if ($inviterId > 0) {
                self::bindInvite($inviterId, (int)$user->id, $mobile, request()->ip());
            }
        }
        self::logLogin($user->id, $deviceFingerprint);
        $profile = self::profilePayload($user->id);
        $profile['invite_rank'] = self::inviteRankForUser($user->id);
        return [
            'token'    => $auth->getToken(),
            'userinfo' => $auth->getUserinfo(),
            'profile'  => $profile,
            'is_new'   => $isNew,
        ];
    }

    public static function bindInvite($inviterUserId, $inviteeUserId, $inviteeMobile, $inviteeIp = '')
    {
        if ($inviterUserId <= 0 || $inviterUserId === $inviteeUserId) {
            return false;
        }
        if (Invite::where('invitee_user_id', $inviteeUserId)->find()) {
            return false;
        }
        $inviter = User::get($inviterUserId);
        if (!$inviter || $inviter->status !== 'normal') {
            return false;
        }
        $inviteeIp = (string)$inviteeIp;
        if (!empty(self::config('invite_ip_limit_enabled', true)) && $inviteeIp !== '') {
            if (Invite::where('invitee_ip', $inviteeIp)->find()) {
                return false;
            }
        }
        $shareRights = self::inviteRewardForUser($inviterUserId);
        Db::startTrans();
        try {
            Invite::create([
                'inviter_user_id' => $inviterUserId,
                'invitee_user_id' => $inviteeUserId,
                'invitee_mobile'  => $inviteeMobile,
                'invitee_ip'      => $inviteeIp,
                'inviter_ip'      => (string)request()->ip(),
                'createtime'      => time(),
            ]);
            self::changeAssets($inviterUserId, $shareRights, 0, 'invite', '邀请新用户奖励', 0, '');
            self::recordTask($inviterUserId, 'invite', $shareRights, 0, '', 'invitee:' . $inviteeUserId);
            FansHubPhase2::onInviteRegistered($inviterUserId);
            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            return false;
        }
    }

    /**
     * 查询用户的上线（邀请人）
     */
    public static function getInviterInfo($inviteeUserId)
    {
        $inviteeUserId = (int)$inviteeUserId;
        if ($inviteeUserId <= 0) {
            return null;
        }
        $row = Invite::where('invitee_user_id', $inviteeUserId)->find();
        if (!$row) {
            return null;
        }
        $inviter = User::get((int)$row->inviter_user_id);
        return [
            'inviter_user_id' => (int)$row->inviter_user_id,
            'mobile'          => $inviter ? (string)$inviter->mobile : '',
            'nickname'        => $inviter ? (string)($inviter->nickname ?? '') : '',
            'createtime'      => (int)($row->createtime ?? 0),
        ];
    }

    /**
     * 批量查询上线信息，返回 [invitee_user_id => info]
     */
    public static function getInviterInfoMap(array $inviteeUserIds)
    {
        $inviteeUserIds = array_values(array_unique(array_filter(array_map('intval', $inviteeUserIds))));
        if (!$inviteeUserIds) {
            return [];
        }
        $rows = Invite::where('invitee_user_id', 'in', $inviteeUserIds)->select();
        $inviterIds = [];
        foreach ($rows as $row) {
            $inviterIds[] = (int)$row->inviter_user_id;
        }
        $inviterIds = array_values(array_unique(array_filter($inviterIds)));
        $users = [];
        if ($inviterIds) {
            $userRows = User::where('id', 'in', $inviterIds)->field('id,mobile,nickname')->select();
            foreach ($userRows as $u) {
                $users[(int)$u->id] = $u;
            }
        }
        $map = [];
        foreach ($rows as $row) {
            $iid = (int)$row->inviter_user_id;
            $u = $users[$iid] ?? null;
            $map[(int)$row->invitee_user_id] = [
                'inviter_user_id' => $iid,
                'mobile'          => $u ? (string)$u->mobile : '',
                'nickname'        => $u ? (string)($u->nickname ?? '') : '',
                'createtime'      => (int)($row->createtime ?? 0),
            ];
        }
        return $map;
    }

    /**
     * 后台设置/更换/清除上线（不发放邀请奖励）
     * @param string|int $inviterRef 上线会员ID或手机号；空则清除
     */
    public static function adminSetInviter($inviteeUserId, $inviterRef)
    {
        $inviteeUserId = (int)$inviteeUserId;
        $invitee = User::get($inviteeUserId);
        if (!$invitee) {
            self::throwCopy('srv_user_not_found');
        }
        $ref = trim((string)$inviterRef);
        $existing = Invite::where('invitee_user_id', $inviteeUserId)->find();

        if ($ref === '' || $ref === '0') {
            if ($existing) {
                $existing->delete();
            }
            return null;
        }

        $inviter = null;
        if (ctype_digit($ref)) {
            $inviter = User::get((int)$ref);
            // 纯数字也可能是手机号（如 11 位），会员ID 未命中再按手机查
            if (!$inviter && strlen($ref) >= 8) {
                $inviter = self::findUserByMobileRef($ref);
            }
        } else {
            $inviter = self::findUserByMobileRef($ref);
        }
        if (!$inviter) {
            throw new Exception('上线用户不存在，请填写正确的会员ID或手机号');
        }
        $inviterUserId = (int)$inviter->id;
        if ($inviterUserId === $inviteeUserId) {
            throw new Exception('不能将自己设为上线');
        }
        if (self::isInInviteUpline($inviterUserId, $inviteeUserId)) {
            throw new Exception('不能将自己的下线设为上线（会形成循环）');
        }

        if ($existing) {
            $existing->save([
                'inviter_user_id' => $inviterUserId,
                'invitee_mobile'  => (string)$invitee->mobile,
            ]);
        } else {
            Invite::create([
                'inviter_user_id' => $inviterUserId,
                'invitee_user_id' => $inviteeUserId,
                'invitee_mobile'  => (string)$invitee->mobile,
                'invitee_ip'      => '',
                'inviter_ip'      => '',
                'createtime'      => time(),
            ]);
        }

        return [
            'inviter_user_id' => $inviterUserId,
            'mobile'          => (string)$inviter->mobile,
            'nickname'        => (string)($inviter->nickname ?? ''),
        ];
    }

    /**
     * 判断 $needleUserId 是否在 $userId 的上线链路中（含直接上线）
     */
    protected static function isInInviteUpline($userId, $needleUserId, $depth = 0)
    {
        $userId = (int)$userId;
        $needleUserId = (int)$needleUserId;
        if ($userId <= 0 || $needleUserId <= 0 || $depth > 64) {
            return $depth > 64;
        }
        $row = Invite::where('invitee_user_id', $userId)->find();
        if (!$row) {
            return false;
        }
        $up = (int)$row->inviter_user_id;
        if ($up === $needleUserId) {
            return true;
        }
        return self::isInInviteUpline($up, $needleUserId, $depth + 1);
    }

    protected static function findUserByMobileRef($ref)
    {
        $ref = trim((string)$ref);
        if ($ref === '') {
            return null;
        }
        $candidates = [$ref];
        $normalized = FansHubMobile::normalize($ref, 'CN');
        if ($normalized !== '') {
            $candidates[] = $normalized;
        }
        $country = FansHubMobile::detectCountryFromMobile($ref);
        if ($country) {
            $n2 = FansHubMobile::normalize($ref, $country);
            if ($n2 !== '') {
                $candidates[] = $n2;
            }
        }
        $candidates = array_values(array_unique(array_filter($candidates)));
        foreach ($candidates as $mobile) {
            $user = User::where('mobile', $mobile)->find();
            if ($user) {
                return $user;
            }
        }
        return null;
    }

    /**
     * 将已过期的密令标记为 expired
     */
    public static function expireSecrets()
    {
        $now = time();
        return Secret::where('status', 'in', ['pending', 'contacted'])
            ->where('expiretime', '>', 0)
            ->where('expiretime', '<', $now)
            ->update(['status' => 'expired', 'updatetime' => $now]);
    }

    /**
     * 释放已过期（非当日）的兑入锁定股份；分享送股等不进入锁定。
     * @return array{rights:float,locked:float,free:float,lock_day:string}
     */
    public static function releaseExpiredRightsLock($account, $persist = true)
    {
        $rights = round((float)($account->rights ?? 0), 2);
        $locked = round((float)($account->rights_locked ?? 0), 2);
        $lockDay = trim((string)($account->rights_lock_day ?? ''));
        $today = date('Y-m-d');
        $dirty = false;

        if ($locked < 0) {
            $locked = 0;
            $dirty = true;
        }
        if ($locked > $rights) {
            $locked = $rights;
            $dirty = true;
        }
        // 锁定日为空或早于今天 → 全部解锁（T+1）
        if ($locked > 0 && ($lockDay === '' || $lockDay < $today)) {
            $locked = 0;
            $lockDay = '';
            $dirty = true;
        }
        if ($locked <= 0 && $lockDay !== '') {
            $lockDay = '';
            $dirty = true;
        }

        if ($dirty) {
            $account->rights_locked = $locked;
            $account->rights_lock_day = $lockDay !== '' ? $lockDay : null;
            if ($persist) {
                $account->save([
                    'rights_locked'   => $locked,
                    'rights_lock_day' => $lockDay !== '' ? $lockDay : null,
                    'updatetime'      => time(),
                ]);
            }
        }

        $locked = round((float)($account->rights_locked ?? 0), 2);
        return [
            'rights'   => $rights,
            'locked'   => $locked,
            'free'     => round(max(0, $rights - $locked), 2),
            'lock_day' => trim((string)($account->rights_lock_day ?? '')),
        ];
    }

    public static function changeAssets($userId, $rightsDelta, $balanceDelta, $type, $remark = '', $adminId = 0, $channel = '', $hongbaoDelta = 0)
    {
        $account = self::getOrCreateAccount($userId);
        if ($account->status !== 'normal') {
            self::throwCopy('srv_account_frozen');
        }
        $user = User::get($userId);
        if ($user && $user->status !== 'normal') {
            self::throwCopy('srv_account_frozen');
        }
        // 余额已并入红宝：原 balance 增量全部落到 hongbao
        $rightsDelta = round((float)$rightsDelta, 2);
        $balanceDelta = round((float)$balanceDelta, 2);
        $hongbaoDelta = round((float)$hongbaoDelta, 2);
        if (abs($balanceDelta) > 1e-8) {
            $hongbaoDelta = round($hongbaoDelta + $balanceDelta, 2);
            $balanceDelta = 0.0;
        }
        $snap = self::releaseExpiredRightsLock($account, false);
        $locked = (float)$snap['locked'];
        $lockDay = $snap['lock_day'];
        $rightsAfter = round((float)$account->rights + $rightsDelta, 2);
        $hongbaoAfter = round((float)($account->hongbao ?? 0) + $hongbaoDelta, 2);
        if ($rightsAfter < 0 || $hongbaoAfter < 0) {
            self::throwCopy('srv_insufficient_assets');
        }
        // 扣减股份时优先动用可兑（分享等自由股）；不足再扣锁定
        if ($rightsDelta < 0) {
            $need = abs($rightsDelta);
            $free = max(0, (float)$account->rights - $locked);
            if ($need > $free + 1e-8) {
                $locked = max(0, round($locked - ($need - $free), 2));
            }
        }
        $locked = min($locked, $rightsAfter);
        if ($locked <= 0) {
            $locked = 0;
            $lockDay = '';
        }
        $now = time();
        $account->save([
            'rights'          => $rightsAfter,
            'rights_locked'   => $locked,
            'rights_lock_day' => $lockDay !== '' ? $lockDay : null,
            'balance'         => 0,
            'hongbao'         => $hongbaoAfter,
            'updatetime'      => $now,
        ]);
        Ledger::create([
            'user_id'         => $userId,
            'type'            => $type,
            'rights_change'   => $rightsDelta,
            'balance_change'  => 0,
            'hongbao_change'  => $hongbaoDelta,
            'rights_after'    => $rightsAfter,
            'balance_after'   => 0,
            'hongbao_after'   => $hongbaoAfter,
            'remark'          => $remark,
            'channel'         => (string)$channel,
            'admin_id'        => $adminId,
            'createtime'      => $now,
        ]);
        if ($rightsDelta > 0) {
            FansHubMarket::onSharesGranted($rightsDelta);
        }
        return $account;
    }

    /** @return string[] */
    public static function exchangeAssets()
    {
        return ['rights', 'hongbao'];
    }

    public static function exchangeAssetLabel($asset)
    {
        $map = [
            'rights'  => '股份',
            'hongbao' => '红宝',
            'balance' => '红宝', // 兼容旧调用
        ];
        return $map[$asset] ?? (string)$asset;
    }

    public static function exchangePairCode($from, $to)
    {
        // 兼容旧 balance 代号：b → h
        $map = ['rights' => 'r', 'balance' => 'h', 'hongbao' => 'h'];
        return ($map[$from] ?? '') . ($map[$to] ?? '');
    }

    public static function hongbaoUnitValue()
    {
        return max(0.0001, (float)self::config('hongbao_unit_value', 1));
    }

    public static function assetUnitValue($asset)
    {
        if ($asset === 'balance' || $asset === 'hongbao') {
            return $asset === 'hongbao' ? self::hongbaoUnitValue() : 1.0;
        }
        return max(0.0001, (float)self::getSharePrice(false));
    }

    public static function exchangePairEnabled($from, $to)
    {
        $from = $from === 'balance' ? 'hongbao' : $from;
        $to = $to === 'balance' ? 'hongbao' : $to;
        $code = self::exchangePairCode($from, $to);
        if ($code === '' || $from === $to) {
            return false;
        }
        // 仅保留股份↔红宝
        if ($code !== 'rh' && $code !== 'hr') {
            return false;
        }
        $cfg = self::config();
        $key = 'exchange_' . $code . '_enabled';
        return !isset($cfg[$key]) || !empty($cfg[$key]);
    }

    public static function exchangePairMin($from, $to)
    {
        $from = $from === 'balance' ? 'hongbao' : $from;
        $to = $to === 'balance' ? 'hongbao' : $to;
        $code = self::exchangePairCode($from, $to);
        $cfg = self::config();
        if ($code === 'rh') {
            return max(1, (float)($cfg['exchange_rh_min'] ?? $cfg['exchange_rb_min'] ?? $cfg['exchange_r2b_min'] ?? 1));
        }
        if ($code === 'hr') {
            return max(1, (float)($cfg['exchange_hr_min'] ?? $cfg['exchange_br_min'] ?? $cfg['exchange_b2r_min'] ?? 1));
        }
        return max(1, (float)($cfg['exchange_' . $code . '_min'] ?? 1));
    }

    public static function exchangePairMax($from, $to)
    {
        $code = self::exchangePairCode($from, $to);
        $cfg = self::config();
        $legacy = (float)($cfg['exchange_max'] ?? 99999);
        $pairMax = (float)($cfg['exchange_' . $code . '_max'] ?? $legacy);
        return max(1, $pairMax > 0 ? $pairMax : 99999);
    }

    public static function exchangeR2bMin()
    {
        return self::exchangePairMin('rights', 'hongbao');
    }

    public static function exchangeB2rMin()
    {
        return self::exchangePairMin('hongbao', 'rights');
    }

    /** @deprecated use exchangeR2bMin / exchangeB2rMin */
    public static function exchangeMin()
    {
        return self::exchangeR2bMin();
    }

    public static function exchangeRightsToBalanceEnabled()
    {
        return self::exchangePairEnabled('rights', 'hongbao');
    }

    public static function exchangeBalanceToRightsEnabled()
    {
        return self::exchangePairEnabled('hongbao', 'rights');
    }

    /**
     * 双资产互兑：股份 / 红宝
     * $amount 以转出资产计量；每次兑换校验后台该方向最低限额
     */
    public static function swapAssets($userId, $from, $to, $amount, $channel = '', $requestKey = '')
    {
        $from = strtolower(trim((string)$from));
        $to = strtolower(trim((string)$to));
        if ($from === 'balance') {
            $from = 'hongbao';
        }
        if ($to === 'balance') {
            $to = 'hongbao';
        }
        $allowed = self::exchangeAssets();
        if (!in_array($from, $allowed, true) || !in_array($to, $allowed, true) || $from === $to) {
            self::throwCopy('srv_exchange_pair_invalid');
        }
        if (!self::exchangePairEnabled($from, $to)) {
            self::throwCopy('srv_exchange_pair_disabled', [
                'from' => self::exchangeAssetLabel($from),
                'to'   => self::exchangeAssetLabel($to),
            ]);
        }
        $code = self::exchangePairCode($from, $to);
        self::assertIdempotent($userId, 'swap_' . $code, $requestKey, true);

        $amount = round((float)$amount, 2);
        $min = self::exchangePairMin($from, $to);
        $max = self::exchangePairMax($from, $to);
        if ($amount <= 0) {
            self::throwCopy('srv_exchange_amount_invalid');
        }
        if ($amount < $min) {
            self::throwCopy('srv_exchange_min', ['min' => $min]);
        }
        if ($amount > $max + 1e-8) {
            self::throwCopy('srv_exchange_max', ['max' => $max]);
        }

        $fromUnit = self::assetUnitValue($from);
        $toUnit = self::assetUnitValue($to);
        if ($fromUnit <= 0 || $toUnit <= 0) {
            self::throwCopy('srv_exchange_amount_invalid');
        }

        // 转出为股份时取整，并按股数回算价值，避免浮点误差
        if ($from === 'rights') {
            $amount = (float)((int)round($amount));
            if ($amount < $min) {
                self::throwCopy('srv_exchange_min', ['min' => (int)ceil($min)]);
            }
            if ($amount > $max) {
                self::throwCopy('srv_exchange_max', ['max' => (int)floor($max)]);
            }
        }

        $cny = round($amount * $fromUnit, 2);
        $credit = round($cny / $toUnit, 2);
        if ($credit <= 0) {
            self::throwCopy('srv_exchange_amount_invalid');
        }

        $channel = trim((string)$channel);
        $remark = self::exchangeAssetLabel($from) . '兑' . self::exchangeAssetLabel($to)
            . ' ' . rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.')
            . ' → ' . rtrim(rtrim(number_format($credit, 2, '.', ''), '0'), '.')
            . ($channel ? ' [' . $channel . ']' : '');

        $deltas = ['rights' => 0.0, 'hongbao' => 0.0];
        $deltas[$from] -= $amount;
        $deltas[$to] += $credit;

        Db::startTrans();
        try {
            $account = Account::where('user_id', $userId)->lock(true)->find();
            if (!$account) {
                Db::rollback();
                $account = self::getOrCreateAccount($userId);
                Db::startTrans();
                $account = Account::where('user_id', $userId)->lock(true)->find();
            }
            if (!$account) {
                self::throwCopy('srv_user_not_found');
            }
            if ($account->status !== 'normal') {
                self::throwCopy('srv_account_frozen');
            }

            $snap = self::releaseExpiredRightsLock($account, false);
            $locked = (float)$snap['locked'];
            $free = (float)$snap['free'];
            $lockDay = $snap['lock_day'];
            $today = date('Y-m-d');

            $cur = [
                'rights'  => (float)$account->rights,
                'hongbao' => (float)($account->hongbao ?? 0),
            ];
            if ($from === 'rights') {
                // 兑出股份只能用可兑份额（分享送股等）；当日红宝兑入的锁定至次日
                if ($amount > $free + 1e-8) {
                    self::throwCopy('srv_rights_t1_locked', [
                        'free'   => (int)floor($free),
                        'locked' => (int)ceil($locked),
                    ]);
                }
                $maxPercent = (float)self::config('max_vote_percent', 1);
                $allowedMax = (int)floor($free * $maxPercent);
                if ($allowedMax < 1 && $free > 0) {
                    $allowedMax = 1;
                }
                if ($amount > $allowedMax) {
                    self::throwCopy('srv_exchange_limit', ['max' => $allowedMax]);
                }
            }
            if ($cur[$from] + 1e-8 < $amount) {
                if ($from === 'rights') {
                    self::throwCopy('srv_insufficient_rights');
                }
                self::throwCopy('srv_insufficient_hongbao');
            }

            $after = [
                'rights'  => round($cur['rights'] + $deltas['rights'], 2),
                'hongbao' => round($cur['hongbao'] + $deltas['hongbao'], 2),
            ];

            // 红宝 → 股份：计入当日锁定，次日才可再兑出（防反复刷）
            if ($to === 'rights' && $from === 'hongbao') {
                $locked = round($locked + $credit, 2);
                $lockDay = $today;
            }
            $locked = min($locked, $after['rights']);
            if ($locked <= 0) {
                $locked = 0;
                $lockDay = '';
            }

            $now = time();
            $account->save([
                'rights'          => $after['rights'],
                'rights_locked'   => $locked,
                'rights_lock_day' => $lockDay !== '' ? $lockDay : null,
                'balance'         => 0,
                'hongbao'         => $after['hongbao'],
                'updatetime'      => $now,
            ]);
            Ledger::create([
                'user_id'         => $userId,
                'type'            => 'exchange_swap',
                'rights_change'   => $deltas['rights'],
                'balance_change'  => 0,
                'hongbao_change'  => $deltas['hongbao'],
                'rights_after'    => $after['rights'],
                'balance_after'   => 0,
                'hongbao_after'   => $after['hongbao'],
                'remark'          => $remark,
                'channel'         => $channel,
                'admin_id'        => 0,
                'createtime'      => $now,
            ]);
            self::recordTask($userId, 'exchange_swap', $deltas['rights'], 0, $channel, $remark);
            if ($deltas['rights'] > 0) {
                FansHubMarket::onSharesGranted($deltas['rights']);
            }
            if ($deltas['hongbao'] > 0) {
                FansHubMarket::bumpCumulative($deltas['hongbao']);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return self::profilePayload($userId);
    }

    public static function exchange($userId, $ticketCount, $channel = '', $requestKey = '')
    {
        return self::swapAssets($userId, 'rights', 'hongbao', $ticketCount, $channel, $requestKey);
    }

    /**
     * 红宝 → 股份
     */
    public static function exchangeBalanceToRights($userId, $amount, $requestKey = '')
    {
        return self::swapAssets($userId, 'hongbao', 'rights', $amount, '', $requestKey);
    }

    public static function productionChecklist()
    {
        $cfg = self::config();
        $items = [];
        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];

        $push = function ($key, $title, $status, $message, $field = '') use (&$items, &$counts) {
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $items[] = [
                'key'     => $key,
                'title'   => $title,
                'status'  => $status,
                'message' => $message,
                'field'   => $field,
            ];
        };

        $urlOk = function ($url) {
            $url = trim((string)$url);
            return $url !== '' && preg_match('#^https?://#i', $url);
        };
        $isPlaceholder = function ($url, array $needles) {
            $url = strtolower(trim((string)$url));
            foreach ($needles as $needle) {
                if ($needle !== '' && strpos($url, strtolower($needle)) !== false) {
                    return true;
                }
            }
            return false;
        };

        if (!empty($cfg['sms_mock_enabled'])) {
            $push('sms_mock_enabled', '关闭测试短信', 'fail', '当前仍开启测试短信模式，任何人可用固定验证码登录。', 'sms_mock_enabled');
        } else {
            $push('sms_mock_enabled', '关闭测试短信', 'ok', '已关闭测试短信，需接入真实短信通道。', 'sms_mock_enabled');
        }

        $csUrl = (string)($cfg['customer_service_url'] ?? '');
        if (!$urlOk($csUrl)) {
            $push('customer_service_url', '客服链接', 'fail', '请填写可访问的客服链接（http/https）。', 'customer_service_url');
        } else {
            $push('customer_service_url', '客服链接', 'ok', '已配置客服链接。', 'customer_service_url');
        }

        $appUrl = (string)($cfg['app_download_url'] ?? '');
        if (!$urlOk($appUrl) || $isPlaceholder($appUrl, ['your-app.com', 'example.com'])) {
            $push('app_download_url', 'App 下载链接', 'warn', '请填写真实 App 下载地址，密令页会展示给用户。', 'app_download_url');
        } else {
            $push('app_download_url', 'App 下载链接', 'ok', '已配置 App 下载链接。', 'app_download_url');
        }

        $inviteUrl = (string)($cfg['invite_base_url'] ?? '');
        if (!$urlOk($inviteUrl)) {
            $push('invite_base_url', '邀请落地页域名', 'warn', '未配置时将使用当前访问域名生成分享链接，生产环境建议填写正式域名。', 'invite_base_url');
        } else {
            $push('invite_base_url', '邀请落地页域名', 'ok', '已配置邀请落地页域名。', 'invite_base_url');
        }

        $mainStationUrl = (string)($cfg['main_station_url'] ?? '');
        if (!$urlOk($mainStationUrl)) {
            $push('main_station_url', '主站开户链接', 'warn', '未配置主站开户跳转链接，H5「去开户」按钮可能无效。', 'main_station_url');
        } else {
            $push('main_station_url', '主站开户链接', 'ok', '已配置主站开户链接。', 'main_station_url');
        }

        $signEnabled = !empty($cfg['api_sign_enabled']);
        $signSecret = (string)($cfg['api_sign_secret'] ?? '');
        if (!$signEnabled) {
            $push('api_sign_enabled', 'API 签名校验', 'ok', '已关闭（H5 不下发密钥；登录态靠 token）。服务端对服务端可另开。', 'api_sign_enabled');
        } else {
            $push('api_sign_enabled', 'API 签名校验', 'warn', '已开启签名：浏览器 H5 拿不到密钥会全部失败，仅适合服务端调用。', 'api_sign_enabled');
            if ($signSecret === '' || $signSecret === 'fanshub_dev_sign_key_change_me') {
                $push('api_sign_secret', '签名密钥', 'fail', '请配置强密钥（仅服务端保管，切勿下发 H5）。', 'api_sign_secret');
            } else {
                $push('api_sign_secret', '签名密钥', 'ok', '签名密钥已配置（仅服务端）。', 'api_sign_secret');
            }
        }

        $uidVerifyEnabled = !empty($cfg['main_uid_verify_enabled']);
        $uidVerifyLocal = !empty($cfg['main_uid_verify_local']);
        $uidVerifyUrl = trim((string)($cfg['main_uid_verify_url'] ?? ''));
        if (!$uidVerifyEnabled) {
            $push('main_uid_verify_enabled', '主站 UID 外部校验', 'warn', '生产环境建议开启 UID 校验，防止用户自报假 UID。', 'main_uid_verify_enabled');
        } elseif ($uidVerifyLocal) {
            $push('main_uid_verify_local', '主站 UID 本地校验', 'ok', '已开启本地会员库 UID 校验（主站 UID 需在 fa_user 中存在）。', 'main_uid_verify_enabled');
            $push('main_uid_verify_enabled', '主站 UID 校验', 'ok', '已开启 UID 校验。', 'main_uid_verify_enabled');
        } elseif ($uidVerifyUrl === '') {
            $push('main_uid_verify_url', '主站 UID 外部校验', 'fail', '已开启 UID 校验，但未填写校验接口地址。', 'main_uid_verify_url');
        } else {
            $push('main_uid_verify_enabled', '主站 UID 外部校验', 'ok', '已开启 UID 外部校验。', 'main_uid_verify_enabled');
            $push('main_uid_verify_url', 'UID 校验接口', 'ok', '已配置 UID 校验接口：' . $uidVerifyUrl, 'main_uid_verify_url');
        }

        if (empty($cfg['device_fp_limit_enabled'])) {
            $push('device_fp_limit_enabled', '设备指纹限制', 'warn', '可选：开启后可限制单设备注册账号数，降低刷号风险。', 'device_fp_limit_enabled');
        } else {
            $push('device_fp_limit_enabled', '设备指纹限制', 'ok', '已开启设备指纹注册限制。', 'device_fp_limit_enabled');
        }

        if (empty($cfg['sms_slider_enabled'])) {
            $push('sms_slider_enabled', '短信滑块验证', 'warn', '建议开启：获取短信验证码前需完成滑块验证，防刷短信。', 'sms_slider_enabled');
        } else {
            $push('sms_slider_enabled', '短信滑块验证', 'ok', '已开启短信滑块验证。', 'sms_slider_enabled');
        }

        $localePath = ROOT_PATH . 'public' . DS . '888' . DS . 'i18n' . DS . 'locales' . DS . 'zh-CN.js';
        if (!is_file($localePath)) {
            $push('i18n_bundle', 'H5 多语言包', 'fail', '缺少 public/888/i18n/locales/zh-CN.js，请执行 php scripts/generate_i18n_locales.php', '');
        } else {
            $push('i18n_bundle', 'H5 多语言包', 'ok', '多语言按需分包已生成（正式入口 /888/）。', '');
        }

        $defaultLocale = (string)($cfg['default_locale'] ?? 'zh-CN');
        $allowedLocales = ['zh-CN', 'en-PH', 'km-KH', 'id-ID', 'vi-VN', 'ms-MY'];
        if (!in_array($defaultLocale, $allowedLocales, true)) {
            $push('default_locale', 'H5 默认语言', 'warn', '默认语言配置异常，将回退为中文。', 'default_locale');
        } else {
            $push('default_locale', 'H5 默认语言', 'ok', '默认语言：' . $defaultLocale, 'default_locale');
        }

        if (empty($cfg['jackpot_server_sync'])) {
            $push('jackpot_server_sync', '服务端奖池同步', 'warn', '建议开启：全用户看到一致的实时大盘金额。', 'jackpot_server_sync');
        } else {
            $push('jackpot_server_sync', '服务端奖池同步', 'ok', '已开启服务端奖池同步。', 'jackpot_server_sync');
        }

        if (empty($cfg['sms_mock_enabled'])) {
            $dagouOk = \app\common\library\FansHubDagouSms::enabled();
            $httpSms = !empty($cfg['sms_http_enabled']) && trim((string)($cfg['sms_http_url'] ?? '')) !== '';
            $hasHook = \think\Hook::get('sms_send');
            if ($dagouOk) {
                $push('sms_dagou_enabled', '大狗短信（中国）', 'ok', '已配置大狗短信，+86 用户走该通道。', 'sms_dagou_enabled');
            }
            $unaOk = \app\common\library\FansHubUnaSms::enabled();
            if ($unaOk) {
                $push('sms_una_enabled', 'Universe Action 国际短信', 'ok', '已配置 UNAL 国际短信（非中国区）。', 'sms_una_enabled');
            }
            if ($httpSms) {
                $push('sms_http_enabled', '通用 HTTP 短信网关', 'ok', '已配置备用 HTTP 网关。', 'sms_http_enabled');
            }
            if (!$dagouOk && !$unaOk && !$httpSms && !$hasHook) {
                $push('sms_dagou_enabled', '短信发送通道', 'fail', '请配置大狗短信（中国）或 Universe Action（国际），或安装短信插件。', 'sms_dagou_enabled');
            } elseif (!$dagouOk && !$hasHook) {
                $push('sms_dagou_enabled', '大狗短信（中国）', 'warn', '未配置大狗短信，中国用户可能无法收验证码。', 'sms_dagou_enabled');
            } elseif (!$unaOk && !$httpSms && !$hasHook) {
                $push('sms_una_enabled', 'Universe Action 国际短信', 'warn', '未配置国际短信，非中国用户可能无法收验证码。', 'sms_una_enabled');
            }
        }

        $ready = ($counts['fail'] ?? 0) === 0;
        $level = $ready ? (($counts['warn'] ?? 0) > 0 ? 'warning' : 'success') : 'danger';

        return [
            'items'   => $items,
            'counts'  => $counts,
            'ready'   => $ready,
            'level'   => $level,
            'summary' => $ready
                ? (($counts['warn'] ?? 0) > 0 ? '基本可上线，仍有建议项待完善' : '生产关键项已通过')
                : '存在必须修复项，暂不建议上线',
        ];
    }

    public static function normalizeMainUid($mainUid)
    {
        return trim((string)$mainUid);
    }

    public static function validateMainUidFormat($mainUid)
    {
        $mainUid = self::normalizeMainUid($mainUid);
        if ($mainUid === '') {
            self::throwCopy('srv_uid_required');
        }
        $minLen = max(1, (int)self::config('main_uid_min_length', 4));
        $maxLen = max($minLen, (int)self::config('main_uid_max_length', 32));
        if (strlen($mainUid) < $minLen || strlen($mainUid) > $maxLen) {
            self::throwCopy('srv_uid_format_invalid');
        }
        $pattern = trim((string)self::config('main_uid_pattern', ''));
        if ($pattern === '') {
            $pattern = '/^[A-Za-z0-9]+$/';
        }
        if (@preg_match($pattern, $mainUid) !== 1) {
            self::throwCopy('srv_uid_format_invalid');
        }
        return $mainUid;
    }

    public static function assertMainUidAvailable($userId, $mainUid)
    {
        $mainUid = self::normalizeMainUid($mainUid);
        if ($mainUid === '') {
            return;
        }
        $userId = (int)$userId;
        // 校验后仅含字母数字，可安全拼入 SQL，避免 whereRaw 占位符与 ORM 混用触发 HY093
        $uidKey = strtolower($mainUid);
        if ($uidKey === '' || !preg_match('/^[a-z0-9]+$/', $uidKey)) {
            self::throwCopy('srv_uid_format_invalid');
        }

        // 已被其他手机号正式绑定（不区分大小写）
        $bound = Account::where('main_uid', '<>', '')
            ->where('user_id', '<>', $userId)
            ->whereRaw('LOWER(main_uid) = \'' . $uidKey . '\'')
            ->find();
        if ($bound) {
            self::throwCopy('srv_uid_already_bound');
        }

        // 其他账户正在审核占用同一账号
        $pending = Account::where('main_uid_audit', 'pending')
            ->where('main_uid_pending', '<>', '')
            ->where('user_id', '<>', $userId)
            ->whereRaw('LOWER(main_uid_pending) = \'' . $uidKey . '\'')
            ->find();
        if ($pending) {
            self::throwCopy('srv_uid_already_bound');
        }
    }

    protected static function isMainUidVerifyResponseOk(array $json)
    {
        $truthy = function ($value) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int)$value === 1;
            }
            $value = strtolower(trim((string)$value));
            return in_array($value, ['1', 'true', 'yes', 'ok'], true);
        };
        if (isset($json['valid']) && $truthy($json['valid'])) {
            return true;
        }
        if (isset($json['exists']) && $truthy($json['exists'])) {
            return true;
        }
        if (isset($json['success']) && $truthy($json['success'])) {
            return true;
        }
        if (isset($json['data']) && is_array($json['data'])) {
            if (isset($json['data']['valid']) && $truthy($json['data']['valid'])) {
                return true;
            }
            if (isset($json['data']['exists']) && $truthy($json['data']['exists'])) {
                return true;
            }
        }
        $codes = array_filter(array_map('trim', explode(',', (string)self::config('main_uid_verify_success_codes', '1,0,200'))));
        if (isset($json['code']) && in_array((string)$json['code'], $codes, true)) {
            return true;
        }
        return false;
    }

    protected static function extractResponseMobile(array $json)
    {
        if (isset($json['mobile']) && $json['mobile'] !== '') {
            return (string)$json['mobile'];
        }
        if (isset($json['phone']) && $json['phone'] !== '') {
            return (string)$json['phone'];
        }
        if (isset($json['data']) && is_array($json['data'])) {
            if (!empty($json['data']['mobile'])) {
                return (string)$json['data']['mobile'];
            }
            if (!empty($json['data']['phone'])) {
                return (string)$json['data']['phone'];
            }
        }
        return '';
    }

    public static function verifyMainUidRemote($userId, $mainUid, $mobileOverride = null)
    {
        if (!self::config('main_uid_verify_enabled')) {
            return true;
        }
        if (!empty(self::config('main_uid_verify_local'))) {
            return self::verifyMainUidLocal($userId, $mainUid, $mobileOverride);
        }
        $urlTemplate = trim((string)self::config('main_uid_verify_url', ''));
        if ($urlTemplate === '') {
            self::throwCopy('srv_uid_verify_not_configured');
        }
        $user = User::get($userId);
        $mobile = $mobileOverride !== null ? (string)$mobileOverride : (string)($user->mobile ?? '');
        $url = str_replace(
            ['{uid}', '{mobile}'],
            [rawurlencode($mainUid), rawurlencode($mobile)],
            $urlTemplate
        );
        $method = strtoupper((string)self::config('main_uid_verify_method', 'GET'));
        $timeout = max(1, min(30, (int)self::config('main_uid_verify_timeout', 5)));
        $apiKey = trim((string)self::config('main_uid_verify_api_key', ''));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $headers = ['Accept: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'X-Main-Api-Key: ' . $apiKey;
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'uid'    => $mainUid,
                'mobile' => $mobile,
            ]));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            self::throwCopy('srv_uid_verify_unreachable');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            self::throwCopy('srv_uid_verify_failed');
        }
        $json = json_decode((string)$response, true);
        if (!is_array($json) || !self::isMainUidVerifyResponseOk($json)) {
            self::throwCopy('srv_uid_verify_failed');
        }
        if (!empty(self::config('main_uid_verify_match_phone'))) {
            $respMobile = self::extractResponseMobile($json);
            if ($respMobile !== '' && $mobile !== '' && !FansHubMobile::equivalent($respMobile, $mobile)) {
                self::throwCopy('srv_uid_verify_phone_mismatch');
            }
        }
        return true;
    }

    /**
     * 本地 UID 校验：在会员库中查找主站 UID（数字 ID 或用户名），可选校验手机号一致。
     * 适用于主站与本站共用会员库；若主站为独立系统请关闭此项并配置外部校验 URL。
     */
    protected static function verifyMainUidLocal($userId, $mainUid, $mobileOverride = null)
    {
        $mainUid = self::normalizeMainUid($mainUid);
        $stationUser = null;
        if (ctype_digit($mainUid)) {
            $stationUser = User::get((int)$mainUid);
        }
        if (!$stationUser) {
            $stationUser = User::where('username', $mainUid)->find();
        }
        if (!$stationUser || (string)($stationUser->status ?? '') !== 'normal') {
            self::throwCopy('srv_uid_verify_failed');
        }
        if (!empty(self::config('main_uid_verify_match_phone'))) {
            $mobile = $mobileOverride !== null ? (string)$mobileOverride : '';
            if ($mobile === '') {
                $fansUser = User::get((int)$userId);
                $mobile = (string)($fansUser->mobile ?? '');
            }
            if ($mobile === '' || !FansHubMobile::equivalent((string)$stationUser->mobile, $mobile)) {
                self::throwCopy('srv_uid_verify_phone_mismatch');
            }
        }
        return true;
    }

    public static function verifyMainUid($userId, $mainUid, $mobileOverride = null)
    {
        $mainUid = self::validateMainUidFormat($mainUid);
        self::assertMainUidAvailable($userId, $mainUid);
        self::verifyMainUidRemote($userId, $mainUid, $mobileOverride);
        return $mainUid;
    }

    /**
     * 用户提交主站 UID 进入后台审核（不立即绑定正式 main_uid）
     */
    public static function bindUid($userId, $mainUid)
    {
        $mainUid = self::validateMainUidFormat($mainUid);
        $account = self::getOrCreateAccount($userId);
        $currentUid = self::normalizeMainUid($account->main_uid);
        $audit = (string)($account->main_uid_audit ?? '');

        // 已通过：彻底锁死
        if ($currentUid !== '' && $audit === 'approved') {
            if ($currentUid === $mainUid) {
                return self::profilePayload($userId);
            }
            self::throwCopy('srv_uid_already_approved');
        }

        // 审核中：只允许提交一次，不可改号重提
        if ($audit === 'pending') {
            $pendingUid = self::normalizeMainUid($account->main_uid_pending ?? '');
            if ($pendingUid !== '' && $pendingUid === $mainUid) {
                return self::profilePayload($userId);
            }
            self::throwCopy('srv_uid_pending');
        }

        self::assertMainUidAvailable($userId, $mainUid);

        $account->save([
            'main_uid_pending'       => $mainUid,
            'main_uid_audit'         => 'pending',
            'main_uid_reject_reason' => '',
            'updatetime'             => time(),
        ]);

        // SugarCRM：若手机号已 Verified，立即自动核销通过
        try {
            self::tryApproveUidViaSugarCrm($userId);
        } catch (\Throwable $e) {
            \think\Log::write('SugarCRM auto-approve skip: ' . $e->getMessage(), 'notice');
        }

        return self::profilePayload($userId);
    }

    /**
     * 查询 SugarCRM：游戏账号手机已验证则自动核销通过
     * @return bool true=已通过
     */
    public static function tryApproveUidViaSugarCrm($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0 || !SugarCrm::enabled() || !self::config('sugarcrm_auto_approve', true)) {
            return false;
        }
        $account = self::getOrCreateAccount($userId);
        if ((string)($account->main_uid_audit ?? '') !== 'pending') {
            return false;
        }
        $pending = self::normalizeMainUid($account->main_uid_pending ?? '');
        if ($pending === '') {
            return false;
        }

        try {
            self::assertUidMobileVerifiedBySugarCrm($userId, $pending, ['trigger' => 'auto_approve']);
        } catch (\Throwable $e) {
            return false;
        }

        self::approveMainUid($userId, ['skip_sugarcrm' => true]);
        return true;
    }

    /**
     * 后台「通过核销」遇业务失败时可自动拒绝并回前台的 copy key
     * @return string[]
     */
    public static function sugarCrmAutoRejectCopyKeys()
    {
        return ['srv_uid_verify_failed', 'srv_uid_sugar_not_verified'];
    }

    /**
     * 查询 SugarCRM 核销结果：成功返回会员数组；失败返回 copy key 字符串
     * @return array|string
     */
    public static function resolveUidViaSugarCrm($userId, $playername, array $options = [])
    {
        $userId = (int)$userId;
        $playername = self::normalizeMainUid($playername);
        if ($playername === '') {
            return 'srv_uid_required';
        }
        if (!SugarCrm::enabled()) {
            return 'srv_uid_sugar_disabled';
        }

        $member = SugarCrm::instance()->findByPlayername($playername, [
            'user_id' => $userId,
            'trigger' => (string)($options['trigger'] ?? 'uid_verify'),
        ]);
        if ($member === false) {
            return 'srv_uid_verify_unreachable';
        }
        if ($member === null) {
            return 'srv_uid_verify_failed';
        }
        if (!SugarCrm::isMobileVerified($member)) {
            return 'srv_uid_sugar_not_verified';
        }

        // 核销只认 mobilestatus=Verified，不比对 CRM 手机号与本站手机号
        return $member;
    }

    /**
     * 强制校验 SugarCRM：账号存在且 mobilestatus=Verified（后台手动核销必走）
     * @return array 会员信息
     */
    public static function assertUidMobileVerifiedBySugarCrm($userId, $playername, array $options = [])
    {
        $result = self::resolveUidViaSugarCrm($userId, $playername, $options);
        if (is_string($result)) {
            self::throwCopy($result);
        }
        return $result;
    }

    /**
     * 定时扫描待核销游戏账号（SugarCRM）
     * @return array{scanned:int,approved:int}
     */
    public static function pollPendingUidViaSugarCrm($limit = 80)
    {
        $limit = max(1, min(200, (int)$limit));
        $result = ['scanned' => 0, 'approved' => 0];
        if (!SugarCrm::enabled() || !self::config('sugarcrm_auto_approve', true)) {
            return $result;
        }
        $rows = Account::where('main_uid_audit', 'pending')
            ->where('main_uid_pending', '<>', '')
            ->order('updatetime', 'asc')
            ->limit($limit)
            ->select();
        foreach ($rows as $row) {
            $result['scanned']++;
            try {
                if (self::tryApproveUidViaSugarCrm((int)$row->user_id)) {
                    $result['approved']++;
                }
            } catch (\Throwable $e) {
                \think\Log::write(
                    'SugarCRM poll fail user=' . (int)$row->user_id . ' ' . $e->getMessage(),
                    'error'
                );
            }
        }
        return $result;
    }

    /**
     * 后台核销通过：写入正式 main_uid 并进入阶段二
     * 默认必须先请求 SugarCRM，确认 mobilestatus=Verified
     *
     * @param int   $userId
     * @param array $options skip_sugarcrm=true 跳过接口（仅内部已校验后使用）
     *                       auto_reject_on_fail=true 账号不存在/手机未验证时自动拒绝并回前台
     * @return array{status:string,reason_code?:string}
     */
    public static function approveMainUid($userId, array $options = [])
    {
        $account = self::getOrCreateAccount($userId);
        $pending = self::normalizeMainUid($account->main_uid_pending ?? '');
        if ($pending === '' || (string)($account->main_uid_audit ?? '') !== 'pending') {
            self::throwCopy('srv_uid_audit_empty');
        }
        self::validateMainUidFormat($pending);
        self::assertMainUidAvailable($userId, $pending);

        if (empty($options['skip_sugarcrm'])) {
            $sugar = self::resolveUidViaSugarCrm($userId, $pending, ['trigger' => 'admin_approve']);
            if (is_string($sugar)) {
                $autoKeys = self::sugarCrmAutoRejectCopyKeys();
                if (!empty($options['auto_reject_on_fail']) && in_array($sugar, $autoKeys, true)) {
                    self::rejectMainUid($userId, $sugar);
                    return ['status' => 'rejected', 'reason_code' => $sugar];
                }
                self::throwCopy($sugar);
            }
        }

        try {
            $account->save([
                'main_uid'               => $pending,
                'main_uid_pending'       => '',
                'main_uid_audit'         => 'approved',
                'main_uid_reject_reason' => '',
                'flow_stage'             => 'stage2',
                'updatetime'             => time(),
            ]);
        } catch (\Throwable $e) {
            // 唯一索引冲突：同 UID 已被其他账户绑定
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'uk_main_uid') !== false) {
                self::throwCopy('srv_uid_already_bound');
            }
            throw $e;
        }
        return ['status' => 'approved'];
    }

    /**
     * 后台拒绝 UID
     * @param string $reason 可存文案或 copy key（如 srv_uid_verify_failed），前台按多语言解析
     */
    public static function rejectMainUid($userId, $reason = '')
    {
        $account = self::getOrCreateAccount($userId);
        if ((string)($account->main_uid_audit ?? '') !== 'pending') {
            self::throwCopy('srv_uid_audit_empty');
        }
        $reason = mb_substr(trim((string)$reason), 0, 200);
        $account->save([
            'main_uid_audit'         => 'rejected',
            'main_uid_reject_reason' => $reason,
            'updatetime'             => time(),
        ]);
        return true;
    }

    /**
     * 将拒绝原因解析为当前语言展示文案（支持 copy key 或旧版纯文本）
     */
    public static function resolveUidRejectReasonText($reason)
    {
        $reason = trim((string)$reason);
        if ($reason === '') {
            return '';
        }
        if (preg_match('/^(srv_|uid_|api_|alert_)/', $reason)) {
            $text = self::h5CopyText($reason);
            return $text !== '' ? $text : $reason;
        }
        return $reason;
    }

    public static function openAccountReward($userId)
    {
        $account = self::getOrCreateAccount($userId);
        $mainUid = self::normalizeMainUid($account->main_uid);
        if ($mainUid === '') {
            self::throwCopy('srv_open_account_uid_required');
        }
        self::validateMainUidFormat($mainUid);
        // 正式 UID 已由后台核销写入，领奖时不再做远程自校验
        if ((string)($account->main_uid_audit ?? '') !== 'approved') {
            self::verifyMainUidRemote($userId, $mainUid);
        }

        $done = Ledger::where('user_id', $userId)->where('type', 'open_account')->count();
        if ($done > 0) {
            if ($account->flow_stage !== 'stage2') {
                $account->save(['flow_stage' => 'stage2', 'updatetime' => time()]);
            }
            return self::profilePayload($userId);
        }
        $rights = (float)self::config('open_account_rights', 2);
        self::changeAssets($userId, $rights, 0, 'open_account', '主站开户奖励');
        self::recordTask($userId, 'open_account', $rights, 0, '', 'main_station');
        $account = self::getOrCreateAccount($userId);
        $account->save([
            'flow_stage' => 'stage2',
            'updatetime' => time(),
        ]);
        return self::profilePayload($userId);
    }

    public static function buildSharePayload($userId)
    {
        $cfg = self::config();
        $base = rtrim((string)($cfg['invite_base_url'] ?: request()->domain()), '/');
        $shareText = (string)($cfg['share_text'] ?? '');
        $code = self::encodeInviteCode($userId);
        $h5Path = trim((string)($cfg['h5_entry_path'] ?? '888'), '/');
        $link = ($base && $code !== '' && $h5Path !== '')
            ? $base . '/' . $h5Path . '?code=' . $code
            : '';
        return [
            'profile'    => self::profilePayload($userId),
            'share_text' => $shareText . ($link ? "\n" . $link : ''),
            'share_link' => $link,
            'rewarded'   => false,
            'message'    => '',
        ];
    }

    public static function shareReward($userId)
    {
        $payload = self::buildSharePayload($userId);

        $cooldown = (int)self::config('share_cooldown_seconds', 300);
        $dailyMax = (int)self::config('share_daily_max', 20);
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $todayCount = Ledger::where('user_id', $userId)
            ->where('type', 'share')
            ->where('remark', '分享裂变奖励')
            ->where('createtime', '>=', $todayStart)
            ->count();
        $lastShare = Ledger::where('user_id', $userId)
            ->where('type', 'share')
            ->where('remark', '分享裂变奖励')
            ->order('id', 'desc')
            ->find();

        if ($dailyMax > 0 && $todayCount >= $dailyMax) {
            $payload['message'] = self::h5CopyText('alert_share_daily_limit');
            return $payload;
        }
        if ($lastShare && $cooldown > 0 && (time() - $lastShare->createtime) < $cooldown) {
            $wait = $cooldown - (time() - $lastShare->createtime);
            $payload['message'] = self::h5CopyText('alert_share_cooldown_wait', [
                'minutes' => max(1, (int)ceil($wait / 60)),
            ]);
            return $payload;
        }

        // 二期：当天已走签到通道则不再发「分享送股」（文案仍可复制）
        if (FansHubPhase2::enabled()
            && class_exists(\app\common\model\fanshub\Checkin::class)
            && \app\common\model\fanshub\Checkin::where('user_id', $userId)
                ->where('checkin_date', date('Y-m-d'))
                ->find()
        ) {
            $payload['message'] = self::h5CopyText('phase2_share_checkin_mutex');
            return $payload;
        }

        $rights = (float)self::config('share_rights', 1);
        self::changeAssets($userId, $rights, 0, 'share', '分享裂变奖励');
        self::recordTask($userId, 'share', $rights, 0, '', 'share_click');
        $payload['profile'] = self::profilePayload($userId);
        $payload['rewarded'] = true;
        $payload['message'] = self::h5CopyText('alert_share_reward_ok');
        return $payload;
    }

    public static function createSecret($userId, $requestKey = '')
    {
        self::assertIdempotent($userId, 'createsecret', $requestKey, true);
        self::expireSecrets();
        $account = self::getOrCreateAccount($userId);
        if ($account->status !== 'normal') {
            self::throwCopy('srv_account_frozen');
        }
        $existing = Secret::where('user_id', $userId)
            ->where('status', 'pending')
            ->where('expiretime', '>', time())
            ->order('id', 'desc')
            ->find();
        if ($existing) {
            $lockSeconds = max(0, (int)$existing->expiretime - time());
            return [
                'secret' => [
                    'id'           => (int)$existing->id,
                    'code'         => (string)$existing->code,
                    'amount'       => (float)$existing->amount,
                    'tier'         => (string)$existing->tier,
                    'expiretime'   => (int)$existing->expiretime,
                    'lock_seconds' => $lockSeconds,
                ],
                'profile' => self::profilePayload($userId),
                'reused'  => true,
            ];
        }
        $hongbao = (float)($account->hongbao ?? 0);
        $threshold = (float)self::config('withdraw_threshold', 50);
        $tier = $hongbao >= $threshold ? 'VIP' : 'GREEN';
        $mainUid = (string)$account->main_uid;
        if ($tier === 'VIP' && $account->flow_stage === 'stage2' && $mainUid === '') {
            self::throwCopy('srv_uid_bind_required');
        }
        if ($tier === 'VIP' && $account->flow_stage === 'stage1') {
            self::throwCopy('srv_open_account_required');
        }
        $user = User::get($userId);
        $mobile = (string)($user->mobile ?? '');
        $masked = self::maskMobile($mobile);
        if ($masked === '-' || $masked === '***') {
            $masked = 'USER';
        }
        $randomCode = random_int(1000, 9999);
        $amt = (int)round($hongbao);
        $uidPart = $mainUid !== '' ? 'UID' . $mainUid : 'UID-PENDING';
        $code = sprintf('FH-%s-PH%s-%s-AMT%d-SEC%d', $tier, $masked, $uidPart, $amt, $randomCode);
        $lockSeconds = (int)self::config('secret_lock_seconds', 900);
        $now = time();
        $secret = Secret::create([
            'user_id'    => $userId,
            'code'       => $code,
            'amount'     => $hongbao,
            'tier'       => $tier,
            'main_uid'   => $mainUid,
            'status'     => 'pending',
            'expiretime' => $now + $lockSeconds,
            'createtime' => $now,
            'updatetime' => $now,
        ]);
        if ($tier === 'VIP') {
            FansHubMarket::bumpWithdrawAchiever($userId);
            FansHubMarket::bumpCumulative($hongbao);
        }
        return [
            'secret' => [
                'id'         => (int)$secret->id,
                'code'       => $code,
                'amount'     => $hongbao,
                'tier'       => $tier,
                'expiretime' => $now + $lockSeconds,
                'lock_seconds' => $lockSeconds,
            ],
            'profile' => self::profilePayload($userId),
            'reused'  => false,
        ];
    }

    /**
     * 红宝公告动态（朋友圈风格，完整展开）
     * @param string $category latest|promote|ads|rules|空=全部
     */
    public static function noticeFeed($page = 1, $limit = 20, $category = '')
    {
        $page = max(1, (int)$page);
        $limit = max(1, min(50, (int)$limit));
        $locale = self::requestLocale();
        $cats = \app\common\model\fanshub\Notice::categoryMap();
        $category = trim((string)$category);
        if ($category !== '' && !isset($cats[$category])) {
            $legacy = [
                '规则' => 'rules', '玩法' => 'rules', '推广' => 'promote', '广告' => 'ads',
                '最新发布' => 'latest', '推广赚钱' => 'promote', '广告发布' => 'ads',
                '游戏规则' => 'rules', '游戏规划' => 'rules',
            ];
            $category = $legacy[$category] ?? '';
        }

        $now = time();
        $query = Notice::where('status', 'published')
            ->where('publishtime', '<=', $now);
        if ($category !== '' && isset($cats[$category])) {
            $query->where('category', $category);
        }
        $total = (int)$query->count();
        $listQuery = Notice::where('status', 'published')
            ->where('publishtime', '<=', $now);
        if ($category !== '' && isset($cats[$category])) {
            $listQuery->where('category', $category);
        }
        $rows = $listQuery
            ->order('weigh', 'desc')
            ->order('publishtime', 'desc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select();
        $list = [];
        foreach ($rows as $row) {
            $images = $row->images;
            if (!is_array($images)) {
                $images = [];
            }
            $images = array_values(array_filter(array_map(function ($u) {
                $u = trim((string)$u);
                return $u !== '' ? cdnurl($u, true) : '';
            }, $images)));
            $video = trim((string)$row->video);
            $buttons = $row->action_buttons;
            if (!is_array($buttons)) {
                $buttons = [];
            }
            $normButtons = [];
            foreach ($buttons as $btn) {
                if (!is_array($btn)) {
                    continue;
                }
                $label = trim((string)($btn['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $normButtons[] = [
                    'label' => $label,
                    'url'   => trim((string)($btn['url'] ?? '')),
                ];
            }
            $catCode = (string)$row->category;
            if (!isset($cats[$catCode])) {
                $catCode = 'latest';
            }
            $list[] = [
                'id'             => (int)$row->id,
                'author_name'    => $row->localized('author_name', $locale) ?: '红宝官方公告',
                'author_avatar'  => $row->author_avatar ? cdnurl((string)$row->author_avatar, true) : '',
                'category'       => $catCode,
                'category_label' => \app\common\model\fanshub\Notice::categoryLabel($catCode, $locale),
                'content'        => $row->localized('content', $locale),
                'images'         => $images,
                'video'          => $video !== '' ? cdnurl($video, true) : '',
                'action_type'    => (string)$row->action_type,
                'action_label'   => $row->localized('action_label', $locale),
                'action_url'     => (string)$row->action_url,
                'action_buttons' => $normButtons,
                'publishtime'    => (int)$row->publishtime,
            ];
        }

        $categories = [];
        foreach ($cats as $code => $zh) {
            $categories[] = [
                'code'  => $code,
                'label' => \app\common\model\fanshub\Notice::categoryLabel($code, $locale),
            ];
        }

        return [
            'list'       => $list,
            'categories' => $categories,
            'category'   => $category,
            'locale'     => $locale,
            'total'      => $total,
            'page'       => $page,
            'limit'      => $limit,
            'has_more'   => ($page * $limit) < $total,
        ];
    }

    /**
     * 红包返佣展示类型：owner=群主1% / invite=推荐0.5% / dual=双吃1.5%
     */
    protected static function resolveRpRebateRevenueType($type, $remark = '')
    {
        $type = (string)$type;
        $remark = (string)$remark;
        if ($type === 'red_packet_dual_rebate_in' || preg_match('/1\.5\s*%|双重|双吃/', $remark)) {
            return 'dual';
        }
        if ($type === 'red_packet_invite_rebate_in'
            || $type === 'red_packet_rebate'
            || preg_match('/0\.5\s*%|推荐发包|拉新返佣|邀请返/', $remark)
        ) {
            return 'invite';
        }
        if ($type === 'red_packet_agent_rebate_in' || preg_match('/1\s*%|群主|管理津贴|代理返/', $remark)) {
            return 'owner';
        }
        return '';
    }

    protected static function rpRebateTypeLabel($revenueType, $type, array $labelMap, array $labels)
    {
        if ($revenueType === 'dual') {
            return '🔥 群主+推荐双重返佣';
        }
        if ($revenueType === 'invite') {
            return '🔗 推荐发包返佣';
        }
        if ($revenueType === 'owner') {
            return '群主返佣';
        }
        return $labelMap[$type] ?? ($labels[$type] ?? $type);
    }

    /**
     * 推广佣金汇总（邀请/分享/红包返点）
     */
    public static function commissionSummary($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            self::throwCopy('srv_user_not_found');
        }
        $promoTypes = ['invite', 'share'];
        $rebateTypes = [
            'red_packet_rebate',
            'red_packet_agent_rebate_in',
            'red_packet_invite_rebate_in',
            'red_packet_dual_rebate_in',
        ];
        $allTypes = array_merge($promoTypes, $rebateTypes);
        $inviteCount = (int)Invite::where('inviter_user_id', $userId)->count();
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $account = self::getOrCreateAccount($userId);
        $withdrawable = round((float)($account->hongbao ?? 0), 2);

        $sumTypes = function (array $types, $since = 0) use ($userId) {
            $q = Ledger::where('user_id', $userId)->where('type', 'in', $types);
            if ($since > 0) {
                $q->where('createtime', '>=', $since);
            }
            $row = $q->field('SUM(rights_change) AS rights_sum, SUM(balance_change) AS balance_sum, SUM(hongbao_change) AS hongbao_sum')
                ->find();
            $rights = round((float)($row['rights_sum'] ?? 0), 2);
            $balance = round((float)($row['balance_sum'] ?? 0), 2);
            $hongbao = round((float)($row['hongbao_sum'] ?? 0), 2);
            $money = round($hongbao + $balance + $rights, 2);
            return [
                'rights'  => $rights,
                'balance' => $balance,
                'hongbao' => $hongbao,
                'money'   => $money,
            ];
        };

        $total = $sumTypes($allTypes);
        $today = $sumTypes($allTypes, $todayStart);
        $promo = $sumTypes($promoTypes);
        $promoToday = $sumTypes($promoTypes, $todayStart);
        $rebate = $sumTypes($rebateTypes);

        $labels = FansHubWallet::ledgerTypeLabels();
        $labelMap = [
            'invite'                      => '下级拉新奖励',
            'share'                       => '今日推广收益',
            'red_packet_rebate'           => '推荐发包返佣',
            'red_packet_agent_rebate_in'  => '群主返佣',
            'red_packet_invite_rebate_in' => '推荐发包返佣',
            'red_packet_dual_rebate_in'   => '群主+推荐双重返佣',
        ];
        $buildList = function (array $types, $limit = 20) use ($userId, $labels, $labelMap) {
            $rows = Ledger::where('user_id', $userId)
                ->where('type', 'in', $types)
                ->order('id', 'desc')
                ->limit($limit)
                ->select();
            $list = [];
            foreach ($rows as $row) {
                $type = (string)$row->type;
                $remark = (string)$row->remark;
                $revenueType = self::resolveRpRebateRevenueType($type, $remark);
                $list[] = [
                    'id'             => (int)$row->id,
                    'type'           => $type,
                    'revenue_type'   => $revenueType,
                    'type_label'     => self::rpRebateTypeLabel($revenueType, $type, $labelMap, $labels),
                    'rights_change'  => round((float)$row->rights_change, 2),
                    'balance_change' => round((float)$row->balance_change, 2),
                    'hongbao_change' => round((float)($row->hongbao_change ?? 0), 2),
                    'remark'         => $remark,
                    'createtime'     => (int)$row->createtime,
                ];
            }
            return $list;
        };

        $withdrawRows = Ledger::where('user_id', $userId)
            ->where('type', 'withdraw')
            ->order('id', 'desc')
            ->limit(20)
            ->select();
        $withdrawList = [];
        foreach ($withdrawRows as $row) {
            $withdrawList[] = [
                'id'             => (int)$row->id,
                'type'           => 'withdraw',
                'type_label'     => $labels['withdraw'] ?? '提现扣款',
                'rights_change'  => round((float)$row->rights_change, 2),
                'balance_change' => round((float)$row->balance_change, 2),
                'hongbao_change' => round((float)($row->hongbao_change ?? 0), 2),
                'remark'         => (string)$row->remark,
                'createtime'     => (int)$row->createtime,
            ];
        }

        $share = self::buildSharePayload($userId);
        return [
            'invite_count'    => $inviteCount,
            'invite_code'     => self::encodeInviteCode($userId),
            'share_link'      => (string)($share['share_link'] ?? ''),
            'share_text'      => (string)($share['share_text'] ?? ''),
            'total'           => $total,
            'today'           => $today,
            'promo'           => $promo,
            'promo_today'     => $promoToday,
            'rebate'          => $rebate,
            'withdrawable'    => $withdrawable,
            'total_money'     => (float)$total['money'],
            'today_money'     => (float)$today['money'],
            'rebate_money'    => (float)$rebate['money'],
            'recent'          => $buildList($allTypes, 20),
            'promo_recent'    => $buildList($promoTypes, 30),
            'rebate_recent'   => $buildList($rebateTypes, 30),
            'withdraw_recent' => $withdrawList,
        ];
    }
    public static function getAccountDetail($userId)
    {
        $user = User::get($userId);
        if (!$user) {
            self::throwCopy('srv_user_not_found');
        }
        $account = self::getOrCreateAccount($userId);
        $loginLogs = LoginLog::where('user_id', $userId)->order('id', 'desc')->limit(10)->select();
        $tasks = Task::where('user_id', $userId)->order('id', 'desc')->limit(20)->select();
        $checkins = [];
        if (FansHubPhase2::enabled() && class_exists(\app\common\model\fanshub\Checkin::class)) {
            $checkins = \app\common\model\fanshub\Checkin::where('user_id', $userId)
                ->order('checkin_date', 'desc')
                ->limit(30)
                ->select();
        }
        $inviteAsInviter = (int)Invite::where('inviter_user_id', $userId)->count();
        $phase2 = [];
        if (FansHubPhase2::enabled()) {
            $phase2 = [
                'user_mode'               => (string)($account->user_mode ?? 'newbie'),
                'fission_streak_days'     => (int)($account->fission_streak_days ?? 0),
                'fission_streak_qualified'=> !empty($account->fission_streak_qualified),
                'fission_last_checkin_date'=> (string)($account->fission_last_checkin_date ?? ''),
                'sub_withdrawn_count'       => (int)($account->sub_withdrawn_count ?? 0),
                'honor_tier_claimed'      => (int)($account->honor_tier_claimed ?? 0),
                'honor_tier_name'         => FansHubPhase2::honorTierName((int)($account->honor_tier_claimed ?? 0)),
                'first_withdraw_done'     => !empty($account->first_withdraw_done),
                'invite_count'            => $inviteAsInviter,
            ];
        }
        return [
            'user'       => $user->toArray(),
            'account'    => $account->toArray(),
            'inviter'    => self::getInviterInfo($userId),
            'phase2'     => $phase2,
            'checkins'   => collection($checkins)->toArray(),
            'login_logs' => collection($loginLogs)->toArray(),
            'tasks'      => collection($tasks)->toArray(),
        ];
    }

    /** 官方社群列表缓存（后台增删改时清除，不设短 TTL 轮询） */
    const CACHE_OFFICIAL_COMMUNITIES = 'fanshub_official_communities_v1';

    public static function clearOfficialCommunityCache()
    {
        try {
            \think\Cache::rm(self::CACHE_OFFICIAL_COMMUNITIES);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * H5「官方社群」列表：缓存公共字段；登录后批量标记 is_member
     * @param int $userId
     * @return array{list:array}
     */
    public static function officialCommunities($userId = 0)
    {
        $userId = (int)$userId;
        $list = \think\Cache::get(self::CACHE_OFFICIAL_COMMUNITIES);
        if (!is_array($list)) {
            $list = self::buildOfficialCommunityList();
            // 长期缓存；仅后台操作时 rm
            \think\Cache::set(self::CACHE_OFFICIAL_COMMUNITIES, $list, 86400 * 30);
        }

        $joined = [];
        if ($userId > 0 && $list) {
            $ids = array_column($list, 'id');
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if ($ids) {
                $joined = Db::name('chat_group_members')
                    ->where('user_id', $userId)
                    ->where('status', 1)
                    ->where('group_id', 'in', $ids)
                    ->column('group_id');
                $joined = array_fill_keys(array_map('intval', (array)$joined), true);
            }
        }

        $out = [];
        foreach ($list as $g) {
            $gid = (int)($g['id'] ?? 0);
            $item = $g;
            $item['is_member'] = !empty($joined[$gid]);
            $base = (int)($g['display_member_count'] ?? $g['member_count'] ?? 0);
            // 每群各自基数 + 2s 确定性浮动（±10）；在线同理
            $item['member_count'] = FansHubOfficialStats::memberCount($gid, $base);
            $item['online_count'] = FansHubOfficialStats::onlineCount($gid);
            $out[] = $item;
        }
        return ['list' => $out];
    }

    protected static function buildOfficialCommunityList()
    {
        $hasRecommend = self::chatGroupsHasColumn('is_recommend');
        $hasWeigh = self::chatGroupsHasColumn('weigh');
        $query = Db::name('chat_groups')->where('status', 'in', [1, 3]);
        if ($hasRecommend) {
            $query->where('is_recommend', 1);
        } else {
            $query->where(function ($q) {
                $q->where('privacy_mode', 'open')
                    ->whereOr(function ($q2) {
                        $q2->where('privacy_mode', '')->where('hide_member_list', 0);
                    })
                    ->whereOr(function ($q3) {
                        $q3->whereNull('privacy_mode')->where('hide_member_list', 0);
                    });
            });
        }
        if ($hasWeigh) {
            $query->order('weigh', 'desc')->order('id', 'desc');
        } else {
            $query->order('id', 'desc');
        }
        $rows = $query->limit(50)->select();
        $out = [];
        foreach ($rows as $g) {
            $display = (int)($g['display_member_count'] ?? 0);
            $memberCount = $display > 0 ? $display : (int)($g['member_count'] ?? 0);
            $out[] = [
                'id'                    => (int)$g['id'],
                'name'                  => (string)($g['name'] ?? ''),
                'avatar'                => (string)($g['avatar'] ?? ''),
                'notice'                => (string)($g['notice'] ?? ''),
                'member_count'          => $memberCount,
                'display_member_count'  => $display > 0 ? $display : $memberCount,
                'privacy_mode'          => (string)($g['privacy_mode'] ?? 'private'),
                'chat_mode'             => (string)($g['chat_mode'] ?? 'chat'),
                'weigh'                 => (int)($g['weigh'] ?? 0),
                'is_recommend'          => 1,
            ];
        }
        return $out;
    }

    protected static function chatGroupsHasColumn($column)
    {
        static $cache = [];
        $column = preg_replace('/[^a-z0-9_]/i', '', (string)$column);
        if ($column === '') {
            return false;
        }
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }
        try {
            $prefix = (string)Config::get('database.prefix');
            if ($prefix === '') {
                $prefix = 'fa_';
            }
            $table = $prefix . 'chat_groups';
            $rows = Db::query('SHOW COLUMNS FROM `' . $table . '` LIKE \'' . $column . '\'');
            $cache[$column] = !empty($rows);
        } catch (\Throwable $e) {
            // 已知线上已加字段时兜底，避免误判导致官方社群为空
            if (in_array($column, ['is_recommend', 'weigh'], true)) {
                $cache[$column] = true;
            } else {
                $cache[$column] = false;
            }
        }
        return $cache[$column];
    }
}
