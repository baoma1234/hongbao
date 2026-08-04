<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubService;
use think\Config as ThinkConfig;

/**
 * 福利活动配置（分栏）
 *
 * @icon fa fa-cog
 */
class Config extends Backend
{
    protected $noNeedRight = [
        'index', 'basic', 'exchange', 'invite', 'copy', 'market', 'security',
        'save', 'resetcopy', 'checklist', 'testuidverify', 'resetjackpot', 'i18n', 'savei18n',
    ];

    /** @var array 分区字段（保存时只改本区，避免其它开关被清空） */
    protected $sectionFields = [
        'basic' => [
            'single_ticket_value', 'withdraw_threshold', 'max_vote_percent',
            'register_rights', 'share_rights', 'open_account_rights',
            'secret_lock_seconds',
            'customer_service_url', 'app_download_url', 'main_station_url',
            'h5_entry_path', 'default_locale', 'locale_auto_detect',
        ],
        'exchange' => [
            'exchange_rb_enabled', 'exchange_br_enabled', 'exchange_rh_enabled',
            'exchange_hr_enabled', 'exchange_bh_enabled', 'exchange_hb_enabled',
            'exchange_rb_min', 'exchange_br_min', 'exchange_rh_min',
            'exchange_hr_min', 'exchange_bh_min', 'exchange_hb_min',
            'hongbao_unit_value',
            'exchange_rights_to_balance_enabled', 'exchange_balance_to_rights_enabled',
            'exchange_r2b_min', 'exchange_b2r_min',
        ],
        'invite' => [
            'invite_base_url', 'invite_code_offset', 'share_text', 'marquee_text',
            'invite_ip_limit_enabled', 'share_daily_max', 'share_cooldown_seconds',
            'comment_auto_approve',
        ],
        'copy' => [],
        'market' => [
            'jackpot_base', 'jackpot_ceiling', 'jackpot_auto_grow', 'jackpot_grow_min', 'jackpot_grow_max',
            'jackpot_micro_grow_min', 'jackpot_micro_grow_max', 'jackpot_server_sync',
            'market_virtual_base', 'market_virtual_per_real', 'market_daily_grow_min', 'market_daily_grow_max',
            'market_total_shares_seed', 'market_seed_capital',
            'market_share_price_base', 'market_share_price_min', 'market_share_price_max', 'market_inject_factor',
            'market_share_price_per_n',
            'market_smooth_day_min', 'market_smooth_day_max', 'market_smooth_night_min', 'market_smooth_night_max',
            'market_day_start_hour', 'market_day_end_hour',
        ],
        'security' => [
            'api_sign_enabled', 'api_sign_secret', 'api_sign_ttl',
            'device_fp_limit_enabled', 'device_fp_max_accounts',
            'main_uid_verify_enabled', 'main_uid_verify_local', 'main_uid_verify_url', 'main_uid_verify_method',
            'main_uid_verify_api_key', 'main_uid_verify_timeout', 'main_uid_verify_success_codes',
            'main_uid_verify_match_phone', 'main_uid_min_length', 'main_uid_max_length', 'main_uid_pattern',
        ],
    ];

    protected $sectionMeta = [
        'basic' => ['title' => '基础参数', 'desc' => '面值、门槛、送股、外链与默认语言'],
        'exchange' => ['title' => '资产闪兑', 'desc' => '三资产互兑开关与最低额'],
        'invite' => ['title' => '邀请分享', 'desc' => '邀请域名、分享文案与防刷'],
        'copy' => ['title' => 'H5文案', 'desc' => '大厅界面中文默认文案'],
        'market' => ['title' => '大盘控盘', 'desc' => '虚拟人数、股价与创造价值'],
        'security' => ['title' => '安全校验', 'desc' => '签名、设备指纹与主站 UID'],
    ];

    public function index()
    {
        $this->view->assign('configSection', '');
        return $this->view->fetch();
    }

    public function basic()
    {
        return $this->renderSection('basic');
    }

    public function exchange()
    {
        return $this->renderSection('exchange');
    }

    public function invite()
    {
        return $this->renderSection('invite');
    }

    public function copy()
    {
        return $this->renderSection('copy');
    }

    public function market()
    {
        return $this->renderSection('market');
    }

    public function security()
    {
        return $this->renderSection('security');
    }

    protected function renderSection($section)
    {
        $meta = $this->sectionMeta[$section] ?? ['title' => '活动配置', 'desc' => ''];
        $config = $this->configForView();
        $this->view->assign('config', $config);
        $this->view->assign('configSection', $section);
        $this->view->assign('sectionTitle', $meta['title']);
        $this->view->assign('sectionDesc', $meta['desc']);
        $this->view->assign('h5CopyAdminGroups', FansHubService::h5CopyAdminGroups());
        $this->view->assign('productionChecklist', FansHubService::productionChecklist());
        $this->view->assign('jackpotCurrent', FansHubService::getJackpotAmount(false));
        return $this->view->fetch($section);
    }

    protected function configForView()
    {
        $config = ThinkConfig::get('fanshub') ?: [];
        if (!is_array($config)) {
            $config = [];
        }
        if (empty($config['default_locale'])) {
            $config['default_locale'] = 'zh-CN';
        }
        if (!isset($config['main_uid_verify_method']) || $config['main_uid_verify_method'] === '') {
            $config['main_uid_verify_method'] = 'GET';
        }
        return $config;
    }

    public function checklist()
    {
        $this->success('ok', FansHubService::productionChecklist());
    }

    public function testuidverify()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $mainUid = $this->request->post('main_uid', '');
        $mobile = $this->request->post('mobile', '');
        $boolFields = ['main_uid_verify_match_phone'];
        $intFields = ['main_uid_verify_timeout', 'main_uid_min_length', 'main_uid_max_length'];
        $stringFields = [
            'main_uid_verify_url', 'main_uid_verify_method', 'main_uid_verify_api_key',
            'main_uid_verify_success_codes', 'main_uid_pattern',
        ];
        $backup = ThinkConfig::get('fanshub') ?: [];
        $cfg = $backup;
        $cfg['main_uid_verify_enabled'] = true;
        foreach ($boolFields as $field) {
            if ($this->request->has($field, 'post')) {
                $cfg[$field] = $this->request->post($field) ? true : false;
            }
        }
        foreach ($intFields as $field) {
            if ($this->request->has($field, 'post')) {
                $cfg[$field] = (int)$this->request->post($field);
            }
        }
        foreach ($stringFields as $field) {
            if ($this->request->has($field, 'post')) {
                $cfg[$field] = (string)$this->request->post($field);
            }
        }
        ThinkConfig::set('fanshub', $cfg);
        try {
            FansHubService::validateMainUidFormat($mainUid);
            FansHubService::verifyMainUidRemote(0, FansHubService::normalizeMainUid($mainUid), $mobile);
            $this->success('UID 校验通过');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        } finally {
            ThinkConfig::set('fanshub', $backup);
        }
    }

    public function i18n()
    {
        $grid = FansHubService::i18nAdminGroups();
        $this->view->assign('configSection', 'i18n');
        $this->view->assign('i18nGroups', $grid['groups']);
        $this->view->assign('i18nLocales', $grid['locales']);
        return $this->view->fetch();
    }

    public function savei18n()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        // 文案键 × 多语言会远超 PHP max_input_vars(默认1000)，改为读取单个 JSON 字段
        $posted = [];
        $json = (string)$this->request->post('i18n_json', '');
        if ($json === '') {
            $raw = (string)$this->request->getContent();
            if ($raw !== '' && isset($_SERVER['CONTENT_TYPE']) && stripos((string)$_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    if (isset($decoded['i18n']) && is_array($decoded['i18n'])) {
                        $posted = $decoded['i18n'];
                    } elseif (isset($decoded['i18n_json']) && is_string($decoded['i18n_json'])) {
                        $inner = json_decode($decoded['i18n_json'], true);
                        $posted = is_array($inner) ? $inner : [];
                    } else {
                        $posted = $decoded;
                    }
                }
            }
        } else {
            $decoded = json_decode($json, true);
            $posted = is_array($decoded) ? $decoded : [];
        }
        if (!$posted) {
            // 兼容旧表单 name=i18n[locale][key]（字段少时仍可用）
            $legacy = $this->request->post('i18n/a', []);
            if (is_array($legacy) && $legacy) {
                $posted = $legacy;
            }
        }
        if (!is_array($posted) || !$posted) {
            $this->error('没有可保存的文案');
        }
        if (!FansHubService::saveI18nLocales($posted)) {
            $this->error('保存失败，请检查文件权限');
        }
        $this->success('多语言文案已保存并同步到 H5');
    }

    public function resetjackpot()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $cfg = ThinkConfig::get('fanshub') ?: [];
        $base = (float)($cfg['jackpot_base'] ?? 1000000);
        FansHubService::resetJackpotCache($base);
        $this->success('奖池已重置为基数', ['amount' => $base]);
    }

    public function save()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $section = (string)$this->request->post('section', '');
        $allBoolFields = [
            'comment_auto_approve', 'invite_ip_limit_enabled', 'jackpot_auto_grow', 'jackpot_server_sync',
            'locale_auto_detect', 'api_sign_enabled', 'device_fp_limit_enabled',
            'main_uid_verify_enabled', 'main_uid_verify_local', 'main_uid_verify_match_phone',
            'exchange_rights_to_balance_enabled', 'exchange_balance_to_rights_enabled',
            'exchange_rb_enabled', 'exchange_br_enabled', 'exchange_rh_enabled',
            'exchange_hr_enabled', 'exchange_bh_enabled', 'exchange_hb_enabled',
        ];
        $intFields = [
            'secret_lock_seconds', 'register_rights', 'share_rights', 'open_account_rights',
            'share_daily_max', 'share_cooldown_seconds', 'api_sign_ttl', 'device_fp_max_accounts',
            'main_uid_verify_timeout', 'main_uid_min_length', 'main_uid_max_length', 'invite_code_offset',
            'market_virtual_base', 'market_virtual_per_real', 'market_daily_grow_min', 'market_daily_grow_max',
            'market_smooth_day_min', 'market_smooth_day_max', 'market_smooth_night_min', 'market_smooth_night_max',
            'market_day_start_hour', 'market_day_end_hour',
        ];
        $floatFields = [
            'single_ticket_value', 'withdraw_threshold', 'max_vote_percent',
            'exchange_r2b_min', 'exchange_b2r_min', 'exchange_rb_min', 'exchange_br_min',
            'exchange_rh_min', 'exchange_hr_min', 'exchange_bh_min', 'exchange_hb_min', 'hongbao_unit_value',
            'jackpot_base', 'jackpot_ceiling', 'jackpot_grow_min', 'jackpot_grow_max',
            'jackpot_micro_grow_min', 'jackpot_micro_grow_max',
            'market_total_shares_seed', 'market_seed_capital',
            'market_share_price_base', 'market_share_price_min', 'market_share_price_max',
            'market_inject_factor', 'market_share_price_per_n', 'share_price_max',
        ];

        if ($section !== '' && !isset($this->sectionFields[$section])) {
            $this->error('未知配置分区');
        }
        $fields = $section !== ''
            ? $this->sectionFields[$section]
            : array_values(array_unique(array_merge(...array_values($this->sectionFields))));

        $data = ThinkConfig::get('fanshub') ?: [];
        $oldJackpotBase = (float)($data['jackpot_base'] ?? 1000000);

        // 仅对本区布尔字段：未勾选视为 false
        foreach ($allBoolFields as $field) {
            if (in_array($field, $fields, true)) {
                $data[$field] = false;
            }
        }

        foreach ($fields as $field) {
            if (!$this->request->has($field, 'post')) {
                continue;
            }
            $value = $this->request->post($field);
            if (in_array($field, $allBoolFields, true)) {
                $data[$field] = $value ? true : false;
            } elseif (in_array($field, $intFields, true)) {
                $data[$field] = (int)$value;
            } elseif (in_array($field, $floatFields, true)) {
                $data[$field] = (float)$value;
            } else {
                $data[$field] = (string)$value;
            }
        }

        if ($section === '' || $section === 'exchange') {
            $data['exchange_rights_to_balance_enabled'] = !empty($data['exchange_rb_enabled']);
            $data['exchange_balance_to_rights_enabled'] = !empty($data['exchange_br_enabled']);
            $data['exchange_r2b_min'] = max(1, (float)($data['exchange_rb_min'] ?? $data['exchange_r2b_min'] ?? 1));
            $data['exchange_b2r_min'] = max(1, (float)($data['exchange_br_min'] ?? $data['exchange_b2r_min'] ?? 1));
            $data['exchange_rb_min'] = $data['exchange_r2b_min'];
            $data['exchange_br_min'] = $data['exchange_b2r_min'];
            if (!isset($data['hongbao_unit_value']) || (float)$data['hongbao_unit_value'] <= 0) {
                $data['hongbao_unit_value'] = 1.0;
            }
        }

        $copyChanged = false;
        if ($section === '' || $section === 'copy') {
            $postedCopy = $this->request->post('h5_copy/a', []);
            if (is_array($postedCopy) && $postedCopy) {
                $data['h5_copy'] = FansHubService::mergeH5CopyDefaults($postedCopy);
                $copyChanged = true;
            } elseif ($section === 'copy') {
                $this->error('没有可保存的文案');
            } elseif (empty($data['h5_copy'])) {
                $data['h5_copy'] = FansHubService::mergeH5CopyDefaults();
                $copyChanged = true;
            }
        }

        if (!FansHubService::saveFanshubConfig($data)) {
            $this->error('保存失败，请检查文件权限');
        }
        if (isset($data['jackpot_base']) && (float)$data['jackpot_base'] !== $oldJackpotBase) {
            FansHubService::resetJackpotCache((float)$data['jackpot_base']);
        }
        if ($copyChanged) {
            FansHubService::exportH5CopyDefaultsJs();
            FansHubService::regenerateI18nBundle();
        }
        $this->success('保存成功');
    }

    public function resetcopy()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $data = ThinkConfig::get('fanshub') ?: [];
        $data['h5_copy'] = FansHubService::defaultH5Copy();
        if (!FansHubService::saveFanshubConfig($data)) {
            $this->error('保存失败，请检查文件权限');
        }
        FansHubService::exportH5CopyDefaultsJs();
        FansHubService::regenerateI18nBundle();
        $this->success('已恢复为默认文案');
    }
}
