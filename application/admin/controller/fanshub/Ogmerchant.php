<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubService;
use think\Config as ThinkConfig;

/**
 * OG视讯商户配置（三方游戏）
 *
 * @icon fa fa-video-camera
 */
class Ogmerchant extends Backend
{
    protected $noNeedRight = [];

    public function index()
    {
        $this->view->assign('config', $this->configForView());
        $this->view->assign('suggested_callback', $this->suggestedCallbackUrl());
        $this->view->assign('suggested_return', $this->suggestedReturnUrl());
        return $this->view->fetch();
    }

    public function save()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $data = ThinkConfig::get('fanshub') ?: [];
        if (!is_array($data)) {
            $data = [];
        }

        // 复选框未勾选时 POST 无字段，先置默认再读
        $data['og_enabled'] = false;
        $data['og_sandbox'] = false;

        foreach ($this->ogFields() as $field) {
            if (!$this->request->has($field, 'post')) {
                continue;
            }
            $value = $this->request->post($field);
            if (in_array($field, ['og_enabled', 'og_sandbox'], true)) {
                $data[$field] = $value ? true : false;
            } elseif ($field === 'og_timeout') {
                $data[$field] = max(3, min(120, (int)$value));
            } else {
                $data[$field] = trim((string)$value);
            }
        }

        // 生产短信开关不可被本页改坏（本页只写 og_*，但整文件回写）
        $data['sms_mock_enabled'] = false;
        if (!array_key_exists('sms_dagou_enabled', $data)) {
            $data['sms_dagou_enabled'] = true;
        }
        if (!array_key_exists('sms_una_enabled', $data)) {
            $data['sms_una_enabled'] = true;
        }

        if (!FansHubService::saveFanshubConfig($data)) {
            $this->error('保存失败，请检查文件权限');
        }
        $this->success('OG视讯商户配置已保存');
    }

    /**
     * @return string[]
     */
    protected function ogFields()
    {
        return [
            'og_enabled',
            'og_sandbox',
            'og_merchant_code',
            'og_agent_id',
            'og_api_key',
            'og_api_secret',
            'og_api_base_url',
            'og_sandbox_base_url',
            'og_currency',
            'og_language',
            'og_callback_url',
            'og_return_url',
            'og_timeout',
            'og_remark',
        ];
    }

    protected function configForView()
    {
        $config = ThinkConfig::get('fanshub') ?: [];
        if (!is_array($config)) {
            $config = [];
        }
        $defaults = [
            'og_enabled'          => false,
            'og_sandbox'          => true,
            'og_merchant_code'    => '',
            'og_agent_id'         => '',
            'og_api_key'          => '',
            'og_api_secret'       => '',
            'og_api_base_url'     => '',
            'og_sandbox_base_url' => '',
            'og_currency'         => 'CNY',
            'og_language'         => 'zh',
            'og_callback_url'     => '',
            'og_return_url'       => '',
            'og_timeout'          => 15,
            'og_remark'           => '',
        ];
        foreach ($defaults as $k => $v) {
            if (!array_key_exists($k, $config)) {
                $config[$k] = $v;
            }
        }
        return $config;
    }

    protected function siteBase()
    {
        $cfg = ThinkConfig::get('fanshub') ?: [];
        $base = rtrim((string)($cfg['invite_base_url'] ?? ''), '/');
        if ($base === '') {
            $base = rtrim((string)$this->request->domain(), '/');
        }
        return $base;
    }

    protected function suggestedCallbackUrl()
    {
        return $this->siteBase() . '/api/og/callback';
    }

    protected function suggestedReturnUrl()
    {
        $cfg = ThinkConfig::get('fanshub') ?: [];
        $entry = trim((string)($cfg['h5_entry_path'] ?? '999'), '/');
        return $this->siteBase() . '/' . $entry . '/';
    }
}
