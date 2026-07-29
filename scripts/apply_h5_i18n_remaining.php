<?php
/**
 * Apply remaining H5 i18n: add keys, wire HTML by id/selector, patch JS, field groups, regenerate locales.
 * Run: php scripts/apply_h5_i18n_remaining.php
 */
$root = dirname(__DIR__);
mb_internal_encoding('UTF-8');

function j($s)
{
    // Accept raw UTF-8 or already-decoded string
    return $s;
}

/** @return array<string,string> */
function newCopyKeys()
{
    // Built from current UI Chinese; keys are stable English snake_case.
    return [
        // Existing data-copy refs missing from defaults
        'chat_add_friend_btn' => '添加好友',
        'chat_add_friend_phone_label' => '对方手机号',
        'chat_add_friend_phone_placeholder' => '请输入手机号',
        'chat_add_friend_submit' => '查找并申请',
        'chat_add_friend_title' => '添加好友',
        'chat_create_group_btn' => '建群',
        'chat_friend_req_empty' => '暂无申请',
        'chat_friend_req_entry' => '好友申请',
        'chat_friend_req_incoming' => '收到的',
        'chat_friend_req_outgoing' => '发出的',
        'chat_friend_req_title' => '好友申请',
        'chat_notice_empty' => '暂无公告',
        'chat_tab_commission' => '佣金',
        'chat_tab_notice' => '公告',
        'profile_ledger_loading' => '加载中…',
        'profile_ledger_more' => '加载更多',
        'profile_ledger_title' => '资金流水',
        'profile_menu_ledger' => '资金流水',
        'profile_menu_ledger_sub' => '红宝、红利与股份变动明细',

        // Asset swap (flash exchange tab)
        'swap_title' => '资产兑换',
        'swap_avail' => '可用 —',
        'swap_from_label' => '转出',
        'swap_unit_share' => '股',
        'swap_asset_rights' => '股份',
        'swap_asset_balance' => '红利',
        'swap_asset_hongbao' => '红宝',
        'swap_all_btn' => '全部',
        'swap_min_hint' => '单次最低 1',
        'swap_to_label' => '兑换目标',
        'swap_rate_label' => '兑换比例',
        'swap_est_label' => '预计到账',
        'swap_submit' => '确认兑换',
        'swap_aria_from' => '转出资产',
        'swap_aria_flip' => '互换方向',
        'swap_aria_to' => '兑换目标',

        // Profile
        'profile_vip_badge' => '官方会员',
        'profile_quick_qr' => '二维码',
        'profile_quick_scan' => '扫一扫',
        'profile_quick_recharge' => '充值',
        'profile_quick_withdraw' => '提现',
        'profile_section_asset' => '资产服务',
        'profile_section_security' => '账号与安全',
        'profile_foot_note' => '抢红宝官方 · 会员中心',
        'aria_profile_vip' => '会员资料',
        'aria_profile_quick' => '常用功能',
        'profile_recharge_title' => '充值',
        'profile_recharge_amount_label' => '充值红宝金额（元）',
        'profile_recharge_submit' => '确认充值',
        'profile_withdraw_title' => '提现',
        'profile_withdraw_avail_prefix' => '可提现红宝：',
        'profile_turnover_prefix' => '累计流水：',
        'profile_withdraw_amount_label' => '提现红宝金额（元）',
        'profile_withdraw_method' => '收款方式',
        'profile_withdraw_opt_bank' => '银行卡 (102)',
        'profile_withdraw_opt_alipay' => '支付宝 (101)',
        'profile_withdraw_name_label' => '收款人姓名',
        'profile_withdraw_account_label' => '收款账号',
        'profile_withdraw_bank_label' => '银行名称',
        'profile_withdraw_branch_label' => '支行（可选）',
        'profile_withdraw_region_label' => '省市（可选）',
        'profile_withdraw_submit' => '确认提现',
        'profile_amount_ph' => '请输入金额',
        'profile_withdraw_name_ph' => '真实姓名 / 支付宝实名',
        'profile_withdraw_account_ph' => '银行卡号 / 支付宝账号',
        'profile_withdraw_bank_ph' => '如：工商银行；支付宝填：支付宝',
        'profile_withdraw_branch_ph' => '开户支行',
        'profile_withdraw_province_ph' => '省',
        'profile_withdraw_city_ph' => '市',
        'profile_qr_title' => '我的二维码',
        'profile_qr_uid_prefix' => '会员ID：',
        'profile_qr_tip' => '好友扫一扫即可添加你',
        'profile_qr_copy_btn' => '复制会员ID',

        // Wallet JS
        'wallet_not_login' => '未登录',
        'wallet_unit_share' => '股',
        'wallet_unit_hongbao' => '红宝',
        'wallet_unit_balance' => '红利',
        'wallet_ledger_empty' => '暂无资金流水',
        'wallet_ledger_other' => '其他',
        'wallet_load_fail' => '加载失败',
        'wallet_channel_empty' => '暂无可用通道，请联系客服',
        'wallet_channel_fallback' => '通道{id}',
        'wallet_turnover_need' => '流水需≥{need}',
        'wallet_turnover_ratio_suffix' => '，且不少于提现额×{ratio}',
        'wallet_turnover_line' => '累计流水：{amount}',
        'wallet_loading' => '加载中…',
        'wallet_module_fail' => '钱包模块加载失败',
        'wallet_need_channel_amount' => '请选择通道并填写金额',
        'wallet_recharge_ok' => '充值申请已提交',
        'wallet_need_payee' => '请填写收款人姓名与账号',
        'wallet_need_bank' => '请填写银行名称（支付宝可填：支付宝）',
        'wallet_withdraw_ok' => '提现申请已提交',
        'wallet_fail' => '失败',

        // Chat UI
        'chat_scan' => '扫一扫',
        'chat_cancel' => '取消',
        'chat_community_official' => '官方社群',
        'chat_friend_list' => '好友列表',
        'chat_notice_latest' => '最新发布',
        'chat_notice_promote' => '推广赚钱',
        'chat_notice_ads' => '广告发布',
        'chat_notice_rules' => '游戏规则',
        'chat_commission_total' => '累计佣金',
        'chat_commission_withdraw_btn' => '提现',
        'chat_commission_withdrawable' => '可提现',
        'chat_commission_today' => '今日收益',
        'chat_commission_rebate' => '红包返佣',
        'chat_commission_nav_promo' => '推广结算',
        'chat_commission_nav_rebate' => '红包返佣',
        'chat_commission_nav_ledger' => '收益明细',
        'chat_commission_nav_withdraw' => '提现记录',
        'chat_commission_recent' => '最近结算',
        'chat_commission_login_hint' => '登录后查看佣金明细',
        'chat_qr_scan_hint' => '将好友二维码放入框内即可自动识别',
        'chat_qr_pick_album' => '从相册选择图片',
        'chat_session' => '会话',
        'chat_group_notice_pin' => '群公告',
        'chat_attach_image' => '图片',
        'chat_attach_video' => '视频',
        'chat_attach_file' => '文件',
        'chat_attach_rp' => '红包',
        'chat_group_settings' => '群设置',
        'chat_group_avatar_change' => '更换',
        'chat_group_avatar_fb' => '群',
        'chat_group_default_name' => '群聊',
        'chat_group_members_count' => '{count} 名成员',
        'chat_group_name_label' => '群名称',
        'chat_group_notice_label' => '群公告（聊天页置顶）',
        'chat_group_save' => '保存修改',
        'chat_view_members' => '查看群成员',
        'chat_mute_all' => '全员禁言',
        'chat_group_members_title' => '群成员',
        'chat_add_members_btn' => '＋ 添加群成员',
        'chat_add_members_title' => '添加群成员',
        'chat_confirm_add' => '确认添加 ({count} 人)',
        'chat_member_actions' => '成员操作',
        'chat_mute_one' => '单人禁言',
        'chat_unmute' => '取消禁言',
        'chat_set_admin' => '设为管理员',
        'chat_unset_admin' => '取消管理员',
        'chat_kick' => '踢出群组',
        'chat_mute_duration' => '选择禁言时长',
        'chat_mute_10m' => '10 分钟',
        'chat_mute_1h' => '1 小时',
        'chat_mute_24h' => '24 小时',
        'chat_close' => '关闭',
        'chat_rp_title' => '发红包',
        'chat_rp_blessing_default' => '恭喜发财',
        'chat_rp_lucky_sub' => '拼手气红包',
        'chat_rp_amount_label' => '金额（元）',
        'chat_rp_count_label' => '红包个数',
        'chat_rp_count_hint' => '群聊 5～10 个，私聊固定 1 个',
        'chat_rp_type_label' => '红包类型',
        'chat_rp_type_lucky' => '拼手气',
        'chat_rp_type_avg' => '人均',
        'chat_rp_type_mine' => '埋雷',
        'chat_rp_mine_digit' => '雷号（尾数 0-9）',
        'chat_rp_mine_hint' => '领取金额尾数等于雷号则中雷，赔付整包金额',
        'chat_rp_blessing_label' => '红包封面语',
        'chat_rp_submit' => '塞钱进红包',
        'chat_create_group_title' => '创建新群聊',
        'chat_next' => '下一步',
        'chat_group_type_title' => '群类型',
        'chat_group_type_open' => '开放群',
        'chat_group_type_open_desc' => '可查看成员资料，支持自由加好友',
        'chat_group_type_private' => '隐私群',
        'chat_group_type_private_desc' => '隐藏成员列表，陌生人不可互加',
        'chat_run_mode_title' => '运行模式',
        'chat_run_mode_chat' => '聊天模式',
        'chat_run_mode_chat_desc' => '自由聊天，可发普通/手气/埋雷红包',
        'chat_run_mode_grab' => '红包对战模式',
        'chat_run_mode_grab_desc' => '全员禁言，仅管理员/机器人可发红包',
        'chat_create_group_hint' => '群主可后续在群设置中修改',
        'chat_group_name_ph' => '输入群名称',
        'chat_group_notice_ph' => '输入群公告，成员进入聊天可见',
        'chat_member_search_ph' => '搜索成员昵称/ID',
        'chat_invite_search_ph' => '搜索用户名/手机号/ID',
        'chat_rp_amount_ph' => '最低 10',
        'chat_rp_blessing_ph' => '恭喜发财，大吉大利',
        'chat_create_group_name_ph' => '请输入群名称',
        'aria_search' => '搜索',
        'aria_more' => '更多',
        'aria_community_cats' => '社群分类',
        'aria_notice_cats' => '公告分类',
        'aria_back' => '返回',
        'aria_dial' => '区号',
        'aria_collapse' => '收起',
        'aria_emoji' => '表情',
        'aria_close' => '关闭',
        'title_change_group_avatar' => '更换群头像',
        'title_toggle_avatar' => '点击切换头像',
    ];
}

function newFieldGroups()
{
    return [
        '资产闪兑互兑' => [
            'swap_title' => '互兑标题',
            'swap_avail' => '可用余额行',
            'swap_from_label' => '转出标签',
            'swap_unit_share' => '股份单位缩写',
            'swap_asset_rights' => '资产-股份',
            'swap_asset_balance' => '资产-红利',
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
            'wallet_unit_balance' => '单位-红利',
            'wallet_ledger_empty' => '流水空态',
            'wallet_ledger_other' => '其他类型',
            'wallet_load_fail' => '加载失败',
            'wallet_channel_empty' => '无通道',
            'wallet_channel_fallback' => '通道兜底（{id}）',
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
            'chat_add_friend_btn' => '添加好友',
            'chat_add_friend_title' => '添加好友页标题',
            'chat_add_friend_phone_label' => '对方手机号',
            'chat_add_friend_phone_placeholder' => '手机号占位',
            'chat_add_friend_submit' => '查找并申请',
            'chat_create_group_btn' => '建群',
            'chat_friend_req_entry' => '好友申请入口',
            'chat_friend_req_title' => '好友申请标题',
            'chat_friend_req_incoming' => '收到的',
            'chat_friend_req_outgoing' => '发出的',
            'chat_friend_req_empty' => '申请空态',
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
            'chat_rp_type_avg' => '人均',
            'chat_rp_type_mine' => '埋雷',
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
    ];
}

function exportPhpArray($arr)
{
    $export = var_export($arr, true);
    $export = preg_replace('/^array \(/', 'array (', $export);
    return "<?php\nreturn " . $export . ";\n";
}

function addAttrToId($html, $id, $attr, $key)
{
    // Match id="..." element opening tag and inject attr if missing
    $pattern = '/(<[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*)(>)/u';
    return preg_replace_callback($pattern, function ($m) use ($attr, $key) {
        if (preg_match('/\b' . preg_quote($attr, '/') . '\s*=/', $m[1])) {
            return $m[0];
        }
        return $m[1] . ' ' . $attr . '="' . $key . '"' . $m[2];
    }, $html, 1);
}

function replaceExact($html, $search, $replace)
{
    if (strpos($html, $search) === false) {
        return [$html, false];
    }
    return [str_replace($search, $replace, $html), true];
}

// ---------- 1) Merge keys into fanshub_h5_copy.php ----------
$copyPath = $root . '/application/extra/fanshub_h5_copy.php';
$copy = include $copyPath;
if (!is_array($copy)) {
    fwrite(STDERR, "invalid copy file\n");
    exit(1);
}
$newKeys = newCopyKeys();
$added = 0;
foreach ($newKeys as $k => $v) {
    if (!isset($copy[$k])) {
        $copy[$k] = $v;
        $added++;
    }
}
file_put_contents($copyPath, exportPhpArray($copy));
echo "copy_keys_added=$added total=" . count($copy) . "\n";

// ---------- 2) Patch field groups in FansHubService ----------
$svcPath = $root . '/application/common/library/FansHubService.php';
$svc = file_get_contents($svcPath);
$marker = "            '消息中心' => [";
if (strpos($svc, "'资产闪兑互兑'") === false && strpos($svc, $marker) !== false) {
    $inject = '';
    foreach (newFieldGroups() as $gName => $fields) {
        $inject .= "            '" . $gName . "' => [\n";
        foreach ($fields as $fk => $flabel) {
            $inject .= "                '" . $fk . "' => '" . str_replace("'", "\\'", $flabel) . "',\n";
        }
        $inject .= "            ],\n";
    }
    $svc = str_replace($marker, $inject . $marker, $svc);
    file_put_contents($svcPath, $svc);
    echo "field_groups_injected=1\n";
} else {
    echo "field_groups_injected=0\n";
}

// ---------- 3) Wire HTML ----------
$htmlJobs = [];

// header skin options
$htmlJobs[] = function ($html) {
    $pairs = [
        ['<option value="default">默认</option>', '<option value="default" data-copy="skin_option_default">默认</option>'],
        ['<option value="a">激情中国红</option>', '<option value="a" data-copy="skin_option_a">激情中国红</option>'],
        ['<option value="b">皇家高级蓝</option>', '<option value="b" data-copy="skin_option_b">皇家高级蓝</option>'],
        ['<option value="d">科技冷银灰</option>', '<option value="d" data-copy="skin_option_d">科技冷银灰</option>'],
        ['title="抢红宝"', 'title="抢红宝" data-copy-title="brand_name"'],
        ['alt="抢红宝"', 'alt="抢红宝" data-copy-alt="brand_name"'],
    ];
    foreach ($pairs as $p) {
        list($html) = replaceExact($html, $p[0], $p[1]);
    }
    return $html;
};

// exchange
$htmlJobs['public/888/partials/tab-exchange.php'] = function ($html) {
    $ids = [
        'shareSwapTitle' => ['data-copy', 'swap_title'],
        'shareSwapFromLabel' => ['data-copy', 'swap_from_label'],
        'shareSwapFromIcon' => ['data-copy', 'swap_unit_share'],
        'shareSwapAllBtn' => ['data-copy', 'swap_all_btn'],
        'shareSwapHint' => ['data-copy', 'swap_min_hint'],
        'shareSwapEstLabel' => ['data-copy', 'swap_est_label'],
        'shareSwapSubmitBtn' => ['data-copy', 'swap_submit'],
        'shareSwapFromSelect' => ['data-copy-aria', 'swap_aria_from'],
        'shareSwapFlipBtn' => ['data-copy-aria', 'swap_aria_flip'],
        'shareSwapToSelect' => ['data-copy-aria', 'swap_aria_to'],
    ];
    foreach ($ids as $id => $pair) {
        $html = addAttrToId($html, $id, $pair[0], $pair[1]);
    }
    $pairs = [
        ['aria-label="转出资产"', 'aria-label="转出资产" data-copy-aria="swap_aria_from"'],
        ['aria-label="互换方向"', 'aria-label="互换方向" data-copy-aria="swap_aria_flip"'],
        ['aria-label="兑换目标"', 'aria-label="兑换目标" data-copy-aria="swap_aria_to"'],
        ['<option value="rights">股份</option>', '<option value="rights" data-copy="swap_asset_rights">股份</option>'],
        ['<option value="balance">红利</option>', '<option value="balance" data-copy="swap_asset_balance">红利</option>'],
        ['<option value="hongbao">红宝</option>', '<option value="hongbao" data-copy="swap_asset_hongbao">红宝</option>'],
        ["兑换目标\n                    </div>", "<span data-copy=\"swap_to_label\">兑换目标</span>\n                    </div>"],
        ['<div class="share-swap-summary-label">兑换比例</div>', '<div class="share-swap-summary-label" data-copy="swap_rate_label">兑换比例</div>'],
    ];
    // Only first occurrence of option pairs may double - use replace carefully for dual selects
    foreach ($pairs as $p) {
        if (strpos($p[0], '<option') === 0) {
            $html = preg_replace('/' . preg_quote($p[0], '/') . '/', $p[1], $html, 2);
        } else {
            list($html) = replaceExact($html, $p[0], $p[1]);
        }
    }
    return $html;
};

$htmlJobs['public/888/partials/header.php'] = $htmlJobs[0];
unset($htmlJobs[0]);

$htmlJobs['public/888/partials/tab-profile.php'] = function ($html) {
    $pairs = [
        ['aria-label="会员资料"', 'aria-label="会员资料" data-copy-aria="aria_profile_vip"'],
        ['aria-label="常用功能"', 'aria-label="常用功能" data-copy-aria="aria_profile_quick"'],
        ['<div class="profile-vip-watermark" aria-hidden="true">抢红宝</div>', '<div class="profile-vip-watermark" aria-hidden="true" data-copy="brand_name">抢红宝</div>'],
        ['<span class="profile-vip-badge">官方会员</span>', '<span class="profile-vip-badge" data-copy="profile_vip_badge">官方会员</span>'],
        ['<span class="profile-quick-label">二维码</span>', '<span class="profile-quick-label" data-copy="profile_quick_qr">二维码</span>'],
        ['<span class="profile-quick-label">扫一扫</span>', '<span class="profile-quick-label" data-copy="profile_quick_scan">扫一扫</span>'],
        ['<span class="profile-quick-label">充值</span>', '<span class="profile-quick-label" data-copy="profile_quick_recharge">充值</span>'],
        ['<span class="profile-quick-label">提现</span>', '<span class="profile-quick-label" data-copy="profile_quick_withdraw">提现</span>'],
        ['<h3 class="profile-section-label">资产服务</h3>', '<h3 class="profile-section-label" data-copy="profile_section_asset">资产服务</h3>'],
        ['<h3 class="profile-section-label">账号与安全</h3>', '<h3 class="profile-section-label" data-copy="profile_section_security">账号与安全</h3>'],
        ['<p class="profile-foot-note">抢红宝官方 · 会员中心</p>', '<p class="profile-foot-note" data-copy="profile_foot_note">抢红宝官方 · 会员中心</p>'],
    ];
    foreach ($pairs as $p) {
        list($html) = replaceExact($html, $p[0], $p[1]);
    }
    return $html;
};

$htmlJobs['public/888/partials/profile-subpages.php'] = function ($html) {
    $pairs = [
        ['<div class="profile-sub-title">充值</div>', '<div class="profile-sub-title" data-copy="profile_recharge_title">充值</div>'],
        ['<label>充值红宝金额（元）</label>', '<label data-copy="profile_recharge_amount_label">充值红宝金额（元）</label>'],
        ['id="profileRechargeAmount" step="0.01" min="1" placeholder="请输入金额"', 'id="profileRechargeAmount" step="0.01" min="1" data-copy-placeholder="profile_amount_ph" placeholder="请输入金额"'],
        ['onclick="submitProfileRecharge()">确认充值</button>', 'onclick="submitProfileRecharge()" data-copy="profile_recharge_submit">确认充值</button>'],
        ['<div class="profile-sub-title">提现</div>', '<div class="profile-sub-title" data-copy="profile_withdraw_title">提现</div>'],
        ['<div class="profile-meta-line">可提现红宝：<strong id="profileWithdrawBalance">￥0.00</strong></div>', '<div class="profile-meta-line"><span data-copy="profile_withdraw_avail_prefix">可提现红宝：</span><strong id="profileWithdrawBalance">￥0.00</strong></div>'],
        ['id="profileTurnoverLine">累计流水：￥0.00</div>', 'id="profileTurnoverLine" data-turnover-prefix-key="profile_turnover_prefix">累计流水：￥0.00</div>'],
        ['<label>提现红宝金额（元）</label>', '<label data-copy="profile_withdraw_amount_label">提现红宝金额（元）</label>'],
        ['id="profileWithdrawAmount" step="0.01" min="1" placeholder="请输入金额"', 'id="profileWithdrawAmount" step="0.01" min="1" data-copy-placeholder="profile_amount_ph" placeholder="请输入金额"'],
        ['<label>收款方式</label>', '<label data-copy="profile_withdraw_method">收款方式</label>'],
        ['<option value="102">银行卡 (102)</option>', '<option value="102" data-copy="profile_withdraw_opt_bank">银行卡 (102)</option>'],
        ['<option value="101">支付宝 (101)</option>', '<option value="101" data-copy="profile_withdraw_opt_alipay">支付宝 (101)</option>'],
        ['<label>收款人姓名</label>', '<label data-copy="profile_withdraw_name_label">收款人姓名</label>'],
        ['id="profileWithdrawName" placeholder="真实姓名 / 支付宝实名"', 'id="profileWithdrawName" data-copy-placeholder="profile_withdraw_name_ph" placeholder="真实姓名 / 支付宝实名"'],
        ['<label>收款账号</label>', '<label data-copy="profile_withdraw_account_label">收款账号</label>'],
        ['id="profileWithdrawAccount" placeholder="银行卡号 / 支付宝账号"', 'id="profileWithdrawAccount" data-copy-placeholder="profile_withdraw_account_ph" placeholder="银行卡号 / 支付宝账号"'],
        ['<label>银行名称</label>', '<label data-copy="profile_withdraw_bank_label">银行名称</label>'],
        ['id="profileWithdrawBank" placeholder="如：工商银行；支付宝填：支付宝"', 'id="profileWithdrawBank" data-copy-placeholder="profile_withdraw_bank_ph" placeholder="如：工商银行；支付宝填：支付宝"'],
        ['<label>支行（可选）</label>', '<label data-copy="profile_withdraw_branch_label">支行（可选）</label>'],
        ['id="profileWithdrawBranch" placeholder="开户支行"', 'id="profileWithdrawBranch" data-copy-placeholder="profile_withdraw_branch_ph" placeholder="开户支行"'],
        ['<label>省市（可选）</label>', '<label data-copy="profile_withdraw_region_label">省市（可选）</label>'],
        ['id="profileWithdrawProvince" placeholder="省"', 'id="profileWithdrawProvince" data-copy-placeholder="profile_withdraw_province_ph" placeholder="省"'],
        ['id="profileWithdrawCity" placeholder="市"', 'id="profileWithdrawCity" data-copy-placeholder="profile_withdraw_city_ph" placeholder="市"'],
        ['onclick="submitProfileWithdraw()">确认提现</button>', 'onclick="submitProfileWithdraw()" data-copy="profile_withdraw_submit">确认提现</button>'],
        ['<div class="profile-sub-title">我的二维码</div>', '<div class="profile-sub-title" data-copy="profile_qr_title">我的二维码</div>'],
        ['<div class="profile-qr-uid-line">会员ID：<strong id="profileQrUid">-</strong></div>', '<div class="profile-qr-uid-line"><span data-copy="profile_qr_uid_prefix">会员ID：</span><strong id="profileQrUid">-</strong></div>'],
        ['id="profileQrTip">好友扫一扫即可添加你</p>', 'id="profileQrTip" data-copy="profile_qr_tip">好友扫一扫即可添加你</p>'],
        ['id="profileQrCopyBtn">复制会员ID</button>', 'id="profileQrCopyBtn" data-copy="profile_qr_copy_btn">复制会员ID</button>'],
    ];
    foreach ($pairs as $p) {
        list($html, $ok) = replaceExact($html, $p[0], $p[1]);
        if (!$ok) {
            echo "WARN profile-subpages miss: " . substr($p[0], 0, 40) . "\n";
        }
    }
    return $html;
};

$htmlJobs['public/888/partials/tab-messages.php'] = function ($html) {
    $pairs = [
        ['aria-label="搜索" title="搜索"', 'aria-label="搜索" title="搜索" data-copy-aria="aria_search" data-copy-title="aria_search"'],
        ['aria-label="更多" title="更多" aria-expanded="false"', 'aria-label="更多" title="更多" data-copy-aria="aria_more" data-copy-title="aria_more" aria-expanded="false"'],
        ["id=\"chatQrScanBtn\">\n                                        <img class=\"chat-plus-menu-ico-img\" src=\"img/chat/plus_scan.png\" width=\"28\" height=\"28\" alt=\"\" decoding=\"async\">\n                                        <span>扫一扫</span>", "id=\"chatQrScanBtn\">\n                                        <img class=\"chat-plus-menu-ico-img\" src=\"img/chat/plus_scan.png\" width=\"28\" height=\"28\" alt=\"\" decoding=\"async\">\n                                        <span data-copy=\"chat_scan\">扫一扫</span>"],
        ['id="chatSearchCancelBtn">取消</button>', 'id="chatSearchCancelBtn" data-copy="chat_cancel">取消</button>'],
        ['aria-label="社群分类"', 'aria-label="社群分类" data-copy-aria="aria_community_cats"'],
        ['data-community-tab="official" role="tab" aria-selected="true">官方社群</button>', 'data-community-tab="official" role="tab" aria-selected="true" data-copy="chat_community_official">官方社群</button>'],
        ['data-community-tab="mine" role="tab" aria-selected="false">我的群组</button>', 'data-community-tab="mine" role="tab" aria-selected="false" data-copy="chat_my_groups">我的群组</button>'],
        ['data-community-tab="friends" role="tab" aria-selected="false">好友列表</button>', 'data-community-tab="friends" role="tab" aria-selected="false" data-copy="chat_friend_list">好友列表</button>'],
        ['aria-label="公告分类"', 'aria-label="公告分类" data-copy-aria="aria_notice_cats"'],
        ['data-notice-cat="latest" role="tab" aria-selected="true">最新发布</button>', 'data-notice-cat="latest" role="tab" aria-selected="true" data-copy="chat_notice_latest">最新发布</button>'],
        ['data-notice-cat="promote" role="tab" aria-selected="false">推广赚钱</button>', 'data-notice-cat="promote" role="tab" aria-selected="false" data-copy="chat_notice_promote">推广赚钱</button>'],
        ['data-notice-cat="ads" role="tab" aria-selected="false">广告发布</button>', 'data-notice-cat="ads" role="tab" aria-selected="false" data-copy="chat_notice_ads">广告发布</button>'],
        ['data-notice-cat="rules" role="tab" aria-selected="false">游戏规则</button>', 'data-notice-cat="rules" role="tab" aria-selected="false" data-copy="chat_notice_rules">游戏规则</button>'],
        ['<span class="chat-commission-hero-label">累计佣金</span>', '<span class="chat-commission-hero-label" data-copy="chat_commission_total">累计佣金</span>'],
        ['id="chatCommissionWithdrawBtn">提现</button>', 'id="chatCommissionWithdrawBtn" data-copy="chat_commission_withdraw_btn">提现</button>'],
        ['<span class="chat-commission-stat-label">可提现</span>', '<span class="chat-commission-stat-label" data-copy="chat_commission_withdrawable">可提现</span>'],
        ['<span class="chat-commission-stat-label">今日收益</span>', '<span class="chat-commission-stat-label" data-copy="chat_commission_today">今日收益</span>'],
        ['<span class="chat-commission-stat-label">红包返佣</span>', '<span class="chat-commission-stat-label" data-copy="chat_commission_rebate">红包返佣</span>'],
        ['<span class="chat-commission-nav-label">推广结算 <span aria-hidden="true">›</span></span>', '<span class="chat-commission-nav-label"><span data-copy="chat_commission_nav_promo">推广结算</span> <span aria-hidden="true">›</span></span>'],
        ['<span class="chat-commission-nav-label">红包返佣 <span aria-hidden="true">›</span></span>', '<span class="chat-commission-nav-label"><span data-copy="chat_commission_nav_rebate">红包返佣</span> <span aria-hidden="true">›</span></span>'],
        ['<span class="chat-commission-nav-label">收益明细 <span aria-hidden="true">›</span></span>', '<span class="chat-commission-nav-label"><span data-copy="chat_commission_nav_ledger">收益明细</span> <span aria-hidden="true">›</span></span>'],
        ['<span class="chat-commission-nav-label">提现记录 <span aria-hidden="true">›</span></span>', '<span class="chat-commission-nav-label"><span data-copy="chat_commission_nav_withdraw">提现记录</span> <span aria-hidden="true">›</span></span>'],
        ['id="chatCommissionListTitle">最近结算</div>', 'id="chatCommissionListTitle" data-copy="chat_commission_recent">最近结算</div>'],
        ['<div class="chat-empty chat-empty-glass">登录后查看佣金明细</div>', '<div class="chat-empty chat-empty-glass" data-copy="chat_commission_login_hint">登录后查看佣金明细</div>'],
        ['<div class="chat-hero-title">扫一扫</div>', '<div class="chat-hero-title" data-copy="chat_scan">扫一扫</div>'],
        ['<p class="chat-qr-scan-hint">将好友二维码放入框内即可自动识别</p>', '<p class="chat-qr-scan-hint" data-copy="chat_qr_scan_hint">将好友二维码放入框内即可自动识别</p>'],
        ['id="chatQrPickBtn">从相册选择图片</button>', 'id="chatQrPickBtn" data-copy="chat_qr_pick_album">从相册选择图片</button>'],
        ['<div class="chat-notice-pin-label">群公告</div>', '<div class="chat-notice-pin-label" data-copy="chat_group_notice_pin">群公告</div>'],
        ['id="chatPickImageBtn">\n                                <span class="chat-attach-icon">🖼️</span>\n                                <span>图片</span>', 'id="chatPickImageBtn">\n                                <span class="chat-attach-icon">🖼️</span>\n                                <span data-copy="chat_attach_image">图片</span>'],
        ['id="chatPickVideoBtn">\n                                <span class="chat-attach-icon">🎬</span>\n                                <span>视频</span>', 'id="chatPickVideoBtn">\n                                <span class="chat-attach-icon">🎬</span>\n                                <span data-copy="chat_attach_video">视频</span>'],
        ['id="chatPickFileBtn">\n                                <span class="chat-attach-icon">📎</span>\n                                <span>文件</span>', 'id="chatPickFileBtn">\n                                <span class="chat-attach-icon">📎</span>\n                                <span data-copy="chat_attach_file">文件</span>'],
        ['id="chatAttachRpBtn">\n                                <span class="chat-attach-icon">🧧</span>\n                                <span>红包</span>', 'id="chatAttachRpBtn">\n                                <span class="chat-attach-icon">🧧</span>\n                                <span data-copy="chat_attach_rp">红包</span>'],
        ['title="表情" aria-label="表情"', 'title="表情" aria-label="表情" data-copy-title="aria_emoji" data-copy-aria="aria_emoji"'],
        ['title="更多" aria-label="更多">\n                                <svg viewBox="0 0 24 24" width="20"', 'title="更多" aria-label="更多" data-copy-title="aria_more" data-copy-aria="aria_more">\n                                <svg viewBox="0 0 24 24" width="20"'],
        ['<div class="chat-hero-title">群设置</div>', '<div class="chat-hero-title" data-copy="chat_group_settings">群设置</div>'],
        ['title="更换群头像"', 'title="更换群头像" data-copy-title="title_change_group_avatar"'],
        ['id="chatGroupSettingsAvatarFb">群</span>', 'id="chatGroupSettingsAvatarFb" data-copy="chat_group_avatar_fb">群</span>'],
        ['id="chatGroupAvatarEditHint" style="display:none">更换</span>', 'id="chatGroupAvatarEditHint" style="display:none" data-copy="chat_group_avatar_change">更换</span>'],
        ['for="chatGroupNameInput">群名称</label>', 'for="chatGroupNameInput" data-copy="chat_group_name_label">群名称</label>'],
        ['id="chatGroupNameInput" maxlength="64" placeholder="输入群名称"', 'id="chatGroupNameInput" maxlength="64" data-copy-placeholder="chat_group_name_ph" placeholder="输入群名称"'],
        ['for="chatGroupNoticeInput">群公告（聊天页置顶）</label>', 'for="chatGroupNoticeInput" data-copy="chat_group_notice_label">群公告（聊天页置顶）</label>'],
        ['rows="4" placeholder="输入群公告，成员进入聊天可见"', 'rows="4" data-copy-placeholder="chat_group_notice_ph" placeholder="输入群公告，成员进入聊天可见"'],
        ['id="chatGroupSaveBtn">保存修改</button>', 'id="chatGroupSaveBtn" data-copy="chat_group_save">保存修改</button>'],
        ["id=\"chatViewMembersBtn\">\n                        <span>查看群成员</span>", "id=\"chatViewMembersBtn\">\n                        <span data-copy=\"chat_view_members\">查看群成员</span>"],
        ["id=\"chatMuteAllRow\" style=\"display:none\">\n                        <span>全员禁言</span>", "id=\"chatMuteAllRow\" style=\"display:none\">\n                        <span data-copy=\"chat_mute_all\">全员禁言</span>"],
        ['<div class="chat-hero-title">群成员</div>', '<div class="chat-hero-title" data-copy="chat_group_members_title">群成员</div>'],
        ['id="chatAddMemberBtn" style="display:none">＋ 添加群成员</button>', 'id="chatAddMemberBtn" style="display:none" data-copy="chat_add_members_btn">＋ 添加群成员</button>'],
        ['id="chatMemberSearch" placeholder="搜索成员昵称/ID"', 'id="chatMemberSearch" data-copy-placeholder="chat_member_search_ph" placeholder="搜索成员昵称/ID"'],
        ['<div class="chat-hero-title">添加群成员</div>', '<div class="chat-hero-title" data-copy="chat_add_members_title">添加群成员</div>'],
        ['id="chatInviteSearch" placeholder="搜索用户名/手机号/ID"', 'id="chatInviteSearch" data-copy-placeholder="chat_invite_search_ph" placeholder="搜索用户名/手机号/ID"'],
        ['id="chatMemberActionTitle">成员操作</div>', 'id="chatMemberActionTitle" data-copy="chat_member_actions">成员操作</div>'],
        ['id="chatActMute">单人禁言</button>', 'id="chatActMute" data-copy="chat_mute_one">单人禁言</button>'],
        ['id="chatActUnmute" style="display:none">取消禁言</button>', 'id="chatActUnmute" style="display:none" data-copy="chat_unmute">取消禁言</button>'],
        ['id="chatActSetAdmin" style="display:none">设为管理员</button>', 'id="chatActSetAdmin" style="display:none" data-copy="chat_set_admin">设为管理员</button>'],
        ['id="chatActUnsetAdmin" style="display:none">取消管理员</button>', 'id="chatActUnsetAdmin" style="display:none" data-copy="chat_unset_admin">取消管理员</button>'],
        ['id="chatActKick">踢出群组</button>', 'id="chatActKick" data-copy="chat_kick">踢出群组</button>'],
        ['id="chatMemberActionCancel">取消</button>', 'id="chatMemberActionCancel" data-copy="chat_cancel">取消</button>'],
        ['<div class="chat-action-sheet-title">选择禁言时长</div>', '<div class="chat-action-sheet-title" data-copy="chat_mute_duration">选择禁言时长</div>'],
        ['data-mute-sec="600">10 分钟</button>', 'data-mute-sec="600" data-copy="chat_mute_10m">10 分钟</button>'],
        ['data-mute-sec="3600">1 小时</button>', 'data-mute-sec="3600" data-copy="chat_mute_1h">1 小时</button>'],
        ['data-mute-sec="86400">24 小时</button>', 'data-mute-sec="86400" data-copy="chat_mute_24h">24 小时</button>'],
        ['data-mute-sec="0">取消禁言</button>', 'data-mute-sec="0" data-copy="chat_unmute">取消禁言</button>'],
        ['id="chatMuteDurationCancel">关闭</button>', 'id="chatMuteDurationCancel" data-copy="chat_close">关闭</button>'],
        ['id="chatRpCancelBtn">取消</button>', 'id="chatRpCancelBtn" data-copy="chat_cancel">取消</button>'],
        ['<div class="chat-hero-title">发红包</div>', '<div class="chat-hero-title" data-copy="chat_rp_title">发红包</div>'],
        ['for="chatRpAmount">金额（元）</label>', 'for="chatRpAmount" data-copy="chat_rp_amount_label">金额（元）</label>'],
        ['id="chatRpAmount" inputmode="decimal" step="0.01" min="10" placeholder="最低 10"', 'id="chatRpAmount" inputmode="decimal" step="0.01" min="10" data-copy-placeholder="chat_rp_amount_ph" placeholder="最低 10"'],
        ['for="chatRpCount">红包个数</label>', 'for="chatRpCount" data-copy="chat_rp_count_label">红包个数</label>'],
        ['id="chatRpCountHint">群聊 5～10 个，私聊固定 1 个</div>', 'id="chatRpCountHint" data-copy="chat_rp_count_hint">群聊 5～10 个，私聊固定 1 个</div>'],
        ['<label>红包类型</label>', '<label data-copy="chat_rp_type_label">红包类型</label>'],
        ['data-type="2">拼手气</button>', 'data-type="2" data-copy="chat_rp_type_lucky">拼手气</button>'],
        ['data-type="1">人均</button>', 'data-type="1" data-copy="chat_rp_type_avg">人均</button>'],
        ['data-type="3">埋雷</button>', 'data-type="3" data-copy="chat_rp_type_mine">埋雷</button>'],
        ['for="chatRpMineDigit">雷号（尾数 0-9）</label>', 'for="chatRpMineDigit" data-copy="chat_rp_mine_digit">雷号（尾数 0-9）</label>'],
        ['<div class="chat-rp-field-hint">领取金额尾数等于雷号则中雷，赔付整包金额</div>', '<div class="chat-rp-field-hint" data-copy="chat_rp_mine_hint">领取金额尾数等于雷号则中雷，赔付整包金额</div>'],
        ['for="chatRpBlessing">红包封面语</label>', 'for="chatRpBlessing" data-copy="chat_rp_blessing_label">红包封面语</label>'],
        ['id="chatRpBlessing" maxlength="100" placeholder="恭喜发财，大吉大利" value="恭喜发财"', 'id="chatRpBlessing" maxlength="100" data-copy-placeholder="chat_rp_blessing_ph" placeholder="恭喜发财，大吉大利" value="恭喜发财"'],
        ['id="chatRpSubmitBtn">塞钱进红包</button>', 'id="chatRpSubmitBtn" data-copy="chat_rp_submit">塞钱进红包</button>'],
        ['<div class="chat-cg-title">创建新群聊</div>', '<div class="chat-cg-title" data-copy="chat_create_group_title">创建新群聊</div>'],
        ['id="chatCreateGroupNextTop">下一步</button>', 'id="chatCreateGroupNextTop" data-copy="chat_next">下一步</button>'],
        ['title="点击切换头像"', 'title="点击切换头像" data-copy-title="title_toggle_avatar"'],
        ['id="chatCreateGroupName" maxlength="64" placeholder="请输入群名称"', 'id="chatCreateGroupName" maxlength="64" data-copy-placeholder="chat_create_group_name_ph" placeholder="请输入群名称"'],
        ['id="chatCreateGroupNext">下一步</button>', 'id="chatCreateGroupNext" data-copy="chat_next">下一步</button>'],
        ['<div class="chat-cg-section-title"><span>群类型</span></div>', '<div class="chat-cg-section-title"><span data-copy="chat_group_type_title">群类型</span></div>'],
        ['<span class="chat-cg-radio"></span> 开放群', '<span class="chat-cg-radio"></span> <span data-copy="chat_group_type_open">开放群</span>'],
        ['<span>可查看成员资料，支持自由加好友</span>', '<span data-copy="chat_group_type_open_desc">可查看成员资料，支持自由加好友</span>'],
        ['<span class="chat-cg-radio"></span> 隐私群', '<span class="chat-cg-radio"></span> <span data-copy="chat_group_type_private">隐私群</span>'],
        ['<span>隐藏成员列表，陌生人不可互加</span>', '<span data-copy="chat_group_type_private_desc">隐藏成员列表，陌生人不可互加</span>'],
        ['<div class="chat-cg-section-title"><span>运行模式</span></div>', '<div class="chat-cg-section-title"><span data-copy="chat_run_mode_title">运行模式</span></div>'],
        ['<span class="chat-cg-radio"></span> 聊天模式', '<span class="chat-cg-radio"></span> <span data-copy="chat_run_mode_chat">聊天模式</span>'],
        ['<span>自由聊天，可发普通/手气/埋雷红包</span>', '<span data-copy="chat_run_mode_chat_desc">自由聊天，可发普通/手气/埋雷红包</span>'],
        ['<span class="chat-cg-radio"></span> 红包对战模式', '<span class="chat-cg-radio"></span> <span data-copy="chat_run_mode_grab">红包对战模式</span>'],
        ['<span>全员禁言，仅管理员/机器人可发红包</span>', '<span data-copy="chat_run_mode_grab_desc">全员禁言，仅管理员/机器人可发红包</span>'],
        ['<div class="chat-cg-hint">群主可后续在群设置中修改</div>', '<div class="chat-cg-hint" data-copy="chat_create_group_hint">群主可后续在群设置中修改</div>'],
        ['id="chatAddFriendCountry" class="chat-add-friend-country" aria-label="区号"', 'id="chatAddFriendCountry" class="chat-add-friend-country" aria-label="区号" data-copy-aria="aria_dial"'],
        ['id="chatNoticePinClose" aria-label="收起"', 'id="chatNoticePinClose" aria-label="收起" data-copy-aria="aria_collapse"'],
        ['id="chatGroupMoreBtn" aria-label="更多"', 'id="chatGroupMoreBtn" aria-label="更多" data-copy-aria="aria_more"'],
        ['id="chatMediaLightboxClose" aria-label="关闭"', 'id="chatMediaLightboxClose" aria-label="关闭" data-copy-aria="aria_close"'],
        ['id="chatQrScanBack" aria-label="返回"', 'id="chatQrScanBack" aria-label="返回" data-copy-aria="aria_back"'],
        ['id="chatAddFriendBack" aria-label="返回"', 'id="chatAddFriendBack" aria-label="返回" data-copy-aria="aria_back"'],
        ['id="chatFriendReqBack" aria-label="返回"', 'id="chatFriendReqBack" aria-label="返回" data-copy-aria="aria_back"'],
        ['id="chatGroupSettingsBack" aria-label="返回"', 'id="chatGroupSettingsBack" aria-label="返回" data-copy-aria="aria_back"'],
        ['id="chatGroupMembersBack" aria-label="返回"', 'id="chatGroupMembersBack" aria-label="返回" data-copy-aria="aria_back"'],
        ['id="chatGroupInviteBack" aria-label="返回"', 'id="chatGroupInviteBack" aria-label="返回" data-copy-aria="aria_back"'],
        ['id="chatCreateGroupBack" aria-label="返回"', 'id="chatCreateGroupBack" aria-label="返回" data-copy-aria="aria_back"'],
    ];
    $miss = 0;
    foreach ($pairs as $p) {
        list($html, $ok) = replaceExact($html, $p[0], $p[1]);
        if (!$ok) {
            $miss++;
            echo "WARN messages miss: " . str_replace("\n", '\\n', substr($p[0], 0, 60)) . "\n";
        }
    }
    echo "messages_miss=$miss\n";
    return $html;
};

foreach ($htmlJobs as $rel => $fn) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        echo "SKIP missing $rel\n";
        continue;
    }
    $before = file_get_contents($path);
    $after = $fn($before);
    if ($after !== $before) {
        file_put_contents($path, $after);
        echo "patched $rel\n";
    } else {
        echo "unchanged $rel\n";
    }
}

echo "DONE_HTML\n";
