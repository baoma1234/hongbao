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

    /**
     * 测试：游戏列表
     */
    public function testgamelist()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $gameId = trim((string)$this->request->post('game_id', ''));
        $gameName = trim((string)$this->request->post('game_name', ''));
        $gameType = trim((string)$this->request->post('game_type', ''));

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
            $query = [];
            if ($gameId !== '') {
                $query['game_id'] = (int)$gameId;
            }
            if ($gameName !== '') {
                $query['game_name'] = $gameName;
            }
            if ($gameType !== '') {
                $query['game_type'] = $gameType;
            }
            $ret = FansHubOgGateway::gameList($query);
            $extra = [
                'request'  => FansHubOgGateway::getLastRequest(),
                'response' => FansHubOgGateway::getLastResponse(),
                'data'     => $ret,
            ];
            if (!empty($ret['ok'])) {
                $cnt = is_array($ret['records'] ?? null) ? count($ret['records']) : 0;
                $env = !empty($ret['sandbox']) ? '沙箱' : '正式';
                $this->success(
                    '游戏列表：' . ($ret['rs_code'] ?? '') . ' ' . ($ret['rs_message'] ?? '')
                    . '（' . $env . ' records=' . $cnt . '；注意正式/沙箱 game_id 不同）',
                    null,
                    $extra
                );
            }
            $this->error(
                '游戏列表失败：' . (($ret['rs_code'] ?? '') !== '' ? ($ret['rs_code'] . ' ') : '')
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
     * 测试：限红列表
     */
    public function testbetlimit()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $id = trim((string)$this->request->post('id', ''));

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
            $query = [];
            if ($id !== '') {
                $query['id'] = (int)$id;
            }
            $ret = FansHubOgGateway::betLimit($query);
            $extra = [
                'request'  => FansHubOgGateway::getLastRequest(),
                'response' => FansHubOgGateway::getLastResponse(),
                'data'     => $ret,
            ];
            if (!empty($ret['ok'])) {
                $cnt = is_array($ret['records'] ?? null) ? count($ret['records']) : 0;
                $env = !empty($ret['sandbox']) ? '沙箱' : '正式';
                $lines = [];
                foreach ((array)($ret['records'] ?? []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $lines[] = '#' . ($row['id'] ?? '') . ' ' . ($row['min_limit'] ?? '') . '~' . ($row['max_limit'] ?? '');
                }
                $summary = $lines ? implode('；', array_slice($lines, 0, 8)) : '';
                $this->success(
                    '限红列表：' . ($ret['rs_code'] ?? '') . ' ' . ($ret['rs_message'] ?? '')
                    . '（' . $env . ' records=' . $cnt . '；正式/沙箱 id 不同）'
                    . ($summary !== '' ? ' → ' . $summary : ''),
                    null,
                    $extra
                );
            }
            $this->error(
                '限红列表失败：' . (($ret['rs_code'] ?? '') !== '' ? ($ret['rs_code'] . ' ') : '')
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
     * 测试：进入游戏
     */
    public function testlaunch()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $playerId = trim((string)$this->request->post('player_id', ''));
        $nickname = trim((string)$this->request->post('nickname', ''));
        $gameId = (int)$this->request->post('game_id', 0);
        $betlimit = (int)$this->request->post('betlimit', 0);
        $lang = trim((string)$this->request->post('lang', 'zh'));
        if ($playerId === '') {
            $this->error('请填写 player_id');
        }
        if ($gameId <= 0) {
            $this->error('请填写 game_id');
        }
        if ($betlimit <= 0) {
            $this->error('请填写 betlimit');
        }

        $backup = ThinkConfig::get('fanshub') ?: [];
        $cfg = is_array($backup) ? $backup : [];
        foreach ($this->ogFields() as $field) {
            if ($this->request->has($field, 'post')) {
                $val = $this->request->post($field);
                if (in_array($field, ['og_enabled', 'og_sandbox'], true)) {
                    $cfg[$field] = $val ? true : false;
                } elseif (in_array($field, ['og_timeout', 'og_default_game_id', 'og_default_betlimit'], true)) {
                    $cfg[$field] = (int)$val;
                } else {
                    $cfg[$field] = trim((string)$val);
                }
            }
        }
        $cfg['og_enabled'] = true;
        ThinkConfig::set('fanshub', $cfg);
        try {
            $token = 't' . time() . substr(md5(uniqid('', true)), 0, 10);
            $ret = FansHubOgGateway::launchGame([
                'player_id' => $playerId,
                'nickname'  => $nickname !== '' ? $nickname : $playerId,
                'token'     => $token,
                'game_id'   => $gameId,
                'betlimit'  => $betlimit,
                'lang'      => $lang !== '' ? $lang : 'zh',
            ]);
            $extra = [
                'request'  => FansHubOgGateway::getLastRequest(),
                'response' => FansHubOgGateway::getLastResponse(),
                'data'     => $ret,
            ];
            if (!empty($ret['ok']) && !empty($ret['game_link'])) {
                $this->success(
                    '进游戏成功：' . ($ret['rs_code'] ?? '') . ' → ' . mb_substr((string)$ret['game_link'], 0, 120) . '…',
                    null,
                    $extra
                );
            }
            $this->error(
                '进游戏失败：' . (($ret['rs_code'] ?? '') !== '' ? ($ret['rs_code'] . ' ') : '')
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
     * 测试：玩家余额
     */
    public function testbalance()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $playerId = trim((string)$this->request->post('player_id', ''));
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
                } elseif (in_array($field, ['og_timeout', 'og_default_game_id', 'og_default_betlimit'], true)) {
                    $cfg[$field] = (int)$val;
                } else {
                    $cfg[$field] = trim((string)$val);
                }
            }
        }
        $cfg['og_enabled'] = true;
        ThinkConfig::set('fanshub', $cfg);
        try {
            $ret = FansHubOgGateway::getBalance($playerId);
            $extra = [
                'request'  => FansHubOgGateway::getLastRequest(),
                'response' => FansHubOgGateway::getLastResponse(),
                'data'     => $ret,
            ];
            if (!empty($ret['ok'])) {
                $this->success(
                    '余额：' . ($ret['rs_code'] ?? '') . ' player=' . ($ret['player_id'] ?? '')
                    . ' current_balance=' . ($ret['current_balance'] ?? ''),
                    null,
                    $extra
                );
            }
            $this->error(
                '余额失败：' . (($ret['rs_code'] ?? '') !== '' ? ($ret['rs_code'] . ' ') : '')
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
                } elseif (in_array($field, ['og_default_game_id', 'og_default_betlimit'], true)) {
                    $data[$field] = max(0, (int)$value);
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
            'og_default_game_id',
            'og_default_betlimit',
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
            'og_default_game_id'  => 0,
            'og_default_betlimit' => 0,
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
