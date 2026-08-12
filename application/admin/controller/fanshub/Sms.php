<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubDagouSms;
use app\common\library\FansHubService;
use app\common\library\FansHubUnaSms;
use think\Config as ThinkConfig;

/**
 * 福利大厅短信配置
 *
 * @icon fa fa-envelope
 */
class Sms extends Backend
{
    protected $noNeedRight = ['index', 'save', 'testdagousms', 'dagoubalance', 'testunisms', 'unabalance'];

    public function index()
    {
        $this->view->assign('config', $this->configForView());
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
        $fields = $this->smsFields();
        $data['sms_mock_enabled'] = false;
        $data['sms_slider_enabled'] = false;
        $data['sms_http_enabled'] = false;
        $data['sms_dagou_enabled'] = false;
        $data['sms_una_enabled'] = false;
        $data['sms_una_use_v2'] = false;
        foreach ($fields as $field) {
            if (!$this->request->has($field, 'post')) {
                continue;
            }
            $value = $this->request->post($field);
            if (in_array($field, ['sms_mock_enabled', 'sms_slider_enabled', 'sms_dagou_enabled', 'sms_una_enabled', 'sms_una_use_v2', 'sms_http_enabled'], true)) {
                $data[$field] = $value ? true : false;
            } elseif (in_array($field, ['sms_slider_min_duration_ms', 'sms_send_interval', 'sms_ip_hourly_max', 'sms_http_timeout', 'sms_dagou_timeout', 'sms_una_timeout'], true)) {
                $data[$field] = (int)$value;
            } elseif ($field === 'sms_slider_pass_ratio') {
                $data[$field] = (float)$value;
            } else {
                $data[$field] = (string)$value;
            }
        }
        if (!FansHubService::saveFanshubConfig($data)) {
            $this->error('保存失败，请检查文件权限');
        }
        $this->success('短信配置已保存');
    }

    public function testdagousms()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $mobile = preg_replace('/\D+/', '', (string)$this->request->post('mobile', ''));
        if (strlen($mobile) !== 11) {
            $this->error('请填写11位中国大陆手机号');
        }
        $backup = ThinkConfig::get('fanshub') ?: [];
        $cfg = $backup;
        foreach (['sms_dagou_gateway', 'sms_dagou_uname', 'sms_dagou_apikey'] as $field) {
            if ($this->request->has($field, 'post')) {
                $cfg[$field] = (string)$this->request->post($field);
            }
        }
        if ($this->request->has('sms_dagou_timeout', 'post')) {
            $cfg['sms_dagou_timeout'] = (int)$this->request->post('sms_dagou_timeout');
        }
        $cfg['sms_dagou_enabled'] = true;
        ThinkConfig::set('fanshub', $cfg);
        try {
            $code = (string)mt_rand(100000, 999999);
            $ok = FansHubDagouSms::send('+86' . $mobile, $mobile, $code);
            if (!$ok) {
                $err = FansHubDagouSms::getLastError();
                $this->error($err !== '' ? ('发送失败：' . $err) : '发送失败');
            }
            $this->success('测试短信已提交，验证码：' . $code . '（请查收手机）');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        } finally {
            ThinkConfig::set('fanshub', $backup);
        }
    }

    public function dagoubalance()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $backup = ThinkConfig::get('fanshub') ?: [];
        $cfg = $backup;
        foreach (['sms_dagou_gateway', 'sms_dagou_uname', 'sms_dagou_apikey'] as $field) {
            if ($this->request->has($field, 'post')) {
                $cfg[$field] = (string)$this->request->post($field);
            }
        }
        if ($this->request->has('sms_dagou_timeout', 'post')) {
            $cfg['sms_dagou_timeout'] = (int)$this->request->post('sms_dagou_timeout');
        }
        $cfg['sms_dagou_enabled'] = true;
        ThinkConfig::set('fanshub', $cfg);
        try {
            $data = FansHubDagouSms::getBalance();
            $this->result($data, 1, '查询成功');
        } catch (\Throwable $e) {
            $detail = $e->getMessage();
            $last = FansHubDagouSms::getLastError();
            if ($last !== '' && strpos($detail, $last) === false) {
                $detail .= ' | ' . $last;
            }
            $this->error($detail);
        } finally {
            ThinkConfig::set('fanshub', $backup);
        }
    }

    public function testunisms()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $mobile = trim((string)$this->request->post('mobile', ''));
        if ($mobile === '' || !preg_match('/^\+?\d{8,15}$/', preg_replace('/\s+/', '', $mobile))) {
            $this->error('请填写国际手机号（E.164，如 +639123456789）');
        }
        $backup = ThinkConfig::get('fanshub') ?: [];
        $cfg = $backup;
        foreach (['sms_una_gateway', 'sms_una_org_code', 'sms_una_md5_key', 'sms_una_content_template', 'sms_una_oa_number', 'sms_una_notify_url'] as $field) {
            if ($this->request->has($field, 'post')) {
                $cfg[$field] = (string)$this->request->post($field);
            }
        }
        if ($this->request->has('sms_una_timeout', 'post')) {
            $cfg['sms_una_timeout'] = (int)$this->request->post('sms_una_timeout');
        }
        if ($this->request->has('sms_una_use_v2', 'post')) {
            $cfg['sms_una_use_v2'] = $this->request->post('sms_una_use_v2') ? true : false;
        }
        $cfg['sms_una_enabled'] = true;
        ThinkConfig::set('fanshub', $cfg);
        try {
            $canonical = FansHubUnaSms::formatMobile($mobile);
            if ($canonical === '') {
                $this->error('手机号格式无效');
            }
            $code = (string)mt_rand(100000, 999999);
            $ok = FansHubUnaSms::send($canonical, $code);
            if (!$ok) {
                $this->error('发送失败，请检查网关、orgCode、MD5Key 及号码格式');
            }
            $this->success('测试短信已提交，验证码：' . $code . '（请查收手机）');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        } finally {
            ThinkConfig::set('fanshub', $backup);
        }
    }

    public function unabalance()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $backup = ThinkConfig::get('fanshub') ?: [];
        $cfg = $backup;
        foreach (['sms_una_gateway', 'sms_una_org_code', 'sms_una_md5_key'] as $field) {
            if ($this->request->has($field, 'post')) {
                $cfg[$field] = (string)$this->request->post($field);
            }
        }
        if ($this->request->has('sms_una_timeout', 'post')) {
            $cfg['sms_una_timeout'] = (int)$this->request->post('sms_una_timeout');
        }
        $cfg['sms_una_enabled'] = true;
        ThinkConfig::set('fanshub', $cfg);
        try {
            $data = FansHubUnaSms::getBalance();
            $this->result($data, 1, '查询成功');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        } finally {
            ThinkConfig::set('fanshub', $backup);
        }
    }

    protected function smsFields()
    {
        return [
            'sms_mock_enabled', 'sms_mock_code',
            'sms_slider_enabled', 'sms_slider_pass_ratio', 'sms_slider_min_duration_ms',
            'sms_send_interval', 'sms_ip_hourly_max',
            'sms_dagou_enabled', 'sms_dagou_gateway', 'sms_dagou_uname', 'sms_dagou_apikey', 'sms_dagou_timeout',
            'sms_una_enabled', 'sms_una_gateway', 'sms_una_org_code', 'sms_una_md5_key',
            'sms_una_content_template', 'sms_una_oa_number', 'sms_una_notify_url', 'sms_una_use_v2', 'sms_una_timeout',
            'sms_http_enabled', 'sms_http_url', 'sms_http_method', 'sms_http_api_key',
            'sms_http_timeout', 'sms_http_template',
        ];
    }

    protected function configForView()
    {
        $config = ThinkConfig::get('fanshub') ?: [];
        if (!is_array($config)) {
            $config = [];
        }
        if (!isset($config['sms_http_method']) || $config['sms_http_method'] === '') {
            $config['sms_http_method'] = 'POST';
        }
        if (empty($config['sms_una_content_template'])) {
            $config['sms_una_content_template'] = 'Your verification code is {code}';
        }
        if (empty($config['sms_http_template'])) {
            $config['sms_http_template'] = '{"mobile":"{mobile}","code":"{code}","country":"{country}"}';
        }
        return $config;
    }
}
