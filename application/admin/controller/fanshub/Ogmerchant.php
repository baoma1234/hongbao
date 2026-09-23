<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubOgGateway;
use app\common\library\FansHubService;
use think\Config as ThinkConfig;

/**
 * OG视讯商户配置（三方游戏 · 转账钱包）
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

    /**
     * 测试：注册玩家（转账钱包）
     */
    public function testregister()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $playerId = trim((string)$this->request->post('player_id', ''));
        $nickname = trim((string)$this->request->post('nickname', ''));
        if ($playerId === '') {
            $this->error('请填写 player_id');
        }

        $backup = ThinkConfig::get('fanshub') ?: [];
        $cfg = is_array($backup) ? $backup : [];
        foreach ($this->ogFields() as $field) {
            if ($this->request->has($field, 'post')) {
                $val = $this->request->post($field);
                if (in_array($field, ['og_enabled', 'og_sandbox'], true)) {
                    $cfg[$field] = $val ? true : false;
                } elseif ($field === 'og_timeout') {
                    $cfg[$field] = max(3, min(120, (int)$val));
                } else {
                    $cfg[$field] = trim((string)$val);
                }
            }
        }
        $cfg['og_enabled'] = true;
        ThinkConfig::set('fanshub', $cfg);
        try {
            $ret = FansHubOgGateway::registerPlayer($playerId, $nickname !== '' ? $nickname : null);
            $extra = [
                'request'  => FansHubOgGateway::getLastRequest(),
                'response' => FansHubOgGateway::getLastResponse(),
            ];
            if (!empty($ret['ok'])) {
                $this->success(
                    '注册结果：' . ($ret['rs_code'] ?? '') . ' ' . ($ret['rs_message'] ?? '')
                    . '（player_id=' . ($ret['player_id'] ?? '') . '）',
                    null,
                    $extra
                );
            }
            $this->error(
                '注册失败：' . (($ret['rs_code'] ?? '') !== '' ? ($ret['rs_code'] . ' ') : '')
                . ($ret['rs_message'] ?? FansHubOgGateway::getLastError() ?: 'unknown'),
                null,
                $extra
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        } finally {
            ThinkConfig::set('fanshub', $backup);
        }
    }

    /**
     * 测试：玩家转账·存入（不扣本站钱包，仅调 OG）
     */
    public function testdeposit()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $playerId = trim((string)$this->request->post('player_id', ''));
        $amount = $this->request->post('transfer_amount', $this->request->post('amount', 0));
        $txid = trim((string)$this->request->post('transaction_id', ''));
        if ($playerId === '') {
            $this->error('请填写 player_id');
        }
        if ((float)$amount <= 0) {
            $this->error('请填写 transfer_amount');
        }
        if ($txid === '') {
            $txid = 't' . time() . substr(md5(uniqid('', true)), 0, 8);
        }

        $backup = ThinkConfig::get('fanshub') ?: [];
        $cfg = is_array($backup) ? $backup : [];
        foreach ($this->ogFields() as $field) {
            if ($this->request->has($field, 'post')) {
                $val = $this->request->post($field);
                if (in_array($field, ['og_enabled', 'og_sandbox'], true)) {
                    $cfg[$field] = $val ? true : false;
                } elseif ($field === 'og_timeout') {
                    $cfg[$field] = max(3, min(120, (int)$val));
                } else {
                    $cfg[$field] = trim((string)$val);
                }
            }
        }
        $cfg['og_enabled'] = true;
        ThinkConfig::set('fanshub', $cfg);
        try {
            $ret = FansHubOgGateway::deposit($playerId, $amount, $txid);
            $extra = [
                'request'  => FansHubOgGateway::getLastRequest(),
                'response' => FansHubOgGateway::getLastResponse(),
            ];
            if (!empty($ret['ok'])) {
                $this->success(
                    '存入结果：' . ($ret['rs_code'] ?? '') . ' ' . ($ret['rs_message'] ?? '')
                    . '（txid=' . ($ret['transaction_id'] ?? '') . ' amount=' . ($ret['transfer_amount'] ?? '') . '）',
                    null,
                    $extra
                );
            }
            $this->error(
                '存入失败：' . (($ret['rs_code'] ?? '') !== '' ? ($ret['rs_code'] . ' ') : '')
                . ($ret['rs_message'] ?? FansHubOgGateway::getLastError() ?: 'unknown'),
                null,
                $extra
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        } finally {
            ThinkConfig::set('fanshub', $backup);
        }
    }

    /**
     * 测试：玩家转账·提出（不加本站红宝，仅调 OG）
     */
    public function testwithdraw()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $playerId = trim((string)$this->request->post('player_id', ''));
        $amount = $this->request->post('transfer_amount', $this->request->post('amount', 0));
        $txid = trim((string)$this->request->post('transaction_id', ''));
        if ($playerId === '') {
            $this->error('请填写 player_id');
        }
        if ((float)$amount <= 0) {
            $this->error('请填写 transfer_amount');
        }
        if ($txid === '') {
            $txid = 'w' . time() . substr(md5(uniqid('', true)), 0, 8);
        }

        $backup = ThinkConfig::get('fanshub') ?: [];
        $cfg = is_array($backup) ? $backup : [];
        foreach ($this->ogFields() as $field) {
            if ($this->request->has($field, 'post')) {
                $val = $this->request->post($field);
                if (in_array($field, ['og_enabled', 'og_sandbox'], true)) {
                    $cfg[$field] = $val ? true : false;
                } elseif ($field === 'og_timeout') {
                    $cfg[$field] = max(3, min(120, (int)$val));
                } else {
                    $cfg[$field] = trim((string)$val);
                }
            }
        }
        $cfg['og_enabled'] = true;
        ThinkConfig::set('fanshub', $cfg);
        try {
            $ret = FansHubOgGateway::withdraw($playerId, $amount, $txid);
            $extra = [
                'request'  => FansHubOgGateway::getLastRequest(),
                'response' => FansHubOgGateway::getLastResponse(),
            ];
            if (!empty($ret['ok'])) {
                $this->success(
                    '提出结果：' . ($ret['rs_code'] ?? '') . ' ' . ($ret['rs_message'] ?? '')
                    . '（txid=' . ($ret['transaction_id'] ?? '')
                    . ' amount=' . ($ret['transfer_amount'] ?? '')
                    . ' balance=' . ($ret['balance'] ?? '') . '）',
                    null,
                    $extra
                );
            }
            $this->error(
                '提出失败：' . (($ret['rs_code'] ?? '') !== '' ? ($ret['rs_code'] . ' ') : '')
                . ($ret['rs_message'] ?? FansHubOgGateway::getLastError() ?: 'unknown'),
                null,
                $extra
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        } finally {
            ThinkConfig::set('fanshub', $backup);
        }
    }

    /**
     * 测试：转账历史（仅打 OG）
     */
    public function testhistory()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $playerId = trim((string)$this->request->post('player_id', ''));
        $fetchId = (int)$this->request->post('fetch_id', 1);
        $limit = (int)$this->request->post('limit', 100);
        $txid = trim((string)$this->request->post('transaction_id', ''));

        $backup = ThinkConfig::get('fanshub') ?: [];
        $cfg = is_array($backup) ? $backup : [];
        foreach ($this->ogFields() as $field) {
            if ($this->request->has($field, 'post')) {
                $val = $this->request->post($field);
                if (in_array($field, ['og_enabled', 'og_sandbox'], true)) {
                    $cfg[$field] = $val ? true : false;
                } elseif ($field === 'og_timeout') {
                    $cfg[$field] = max(3, min(120, (int)$val));
                } else {
                    $cfg[$field] = trim((string)$val);
                }
            }
        }
        $cfg['og_enabled'] = true;
        ThinkConfig::set('fanshub', $cfg);
        try {
            $query = [
                'fetch_id' => $fetchId > 0 ? $fetchId : 1,
                'limit'    => $limit > 0 ? $limit : 100,
            ];
            if ($playerId !== '') {
                $query['player_id'] = $playerId;
            }
            if ($txid !== '') {
                $query['transaction_id'] = $txid;
            }
            $ret = FansHubOgGateway::transferHistory($query);
            $extra = [
                'request'  => FansHubOgGateway::getLastRequest(),
                'response' => FansHubOgGateway::getLastResponse(),
                'data'     => $ret,
            ];
            if (!empty($ret['ok'])) {
                $cnt = is_array($ret['records'] ?? null) ? count($ret['records']) : 0;
                $this->success(
                    '历史：' . ($ret['rs_code'] ?? '') . ' ' . ($ret['rs_message'] ?? '')
                    . '（records=' . $cnt . ' last_fetch_id=' . ($ret['last_fetch_id'] ?? 0) . '）',
                    null,
                    $extra
                );
            }
            $this->error(
                '历史失败：' . (($ret['rs_code'] ?? '') !== '' ? ($ret['rs_code'] . ' ') : '')
                . ($ret['rs_message'] ?? FansHubOgGateway::getLastError() ?: 'unknown'),
                null,
                $extra
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        } finally {
            ThinkConfig::set('fanshub', $backup);
        }
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
