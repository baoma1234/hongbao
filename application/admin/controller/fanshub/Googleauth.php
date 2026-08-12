<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubGoogleAuth;
use app\common\library\FansHubService;
use think\Config as ThinkConfig;
use think\Db;

/**
 * 谷歌验证器（登录验证码替代）
 *
 * @icon fa fa-mobile
 */
class Googleauth extends Backend
{
    protected $noNeedRight = ['index', 'save', 'generate', 'preview'];

    public function index()
    {
        $cfg = ThinkConfig::get('fanshub') ?: [];
        $secret = FansHubGoogleAuth::normalizeSecret($cfg['google_auth_secret'] ?? '');
        $enabled = !empty($cfg['google_auth_login_enabled']);
        $issuer = trim((string)($cfg['google_auth_issuer'] ?? '')) ?: 'FansHub';
        $this->view->assign('enabled', $enabled);
        $this->view->assign('secret', $secret);
        $this->view->assign('issuer', $issuer);
        $this->view->assign('currentCode', $secret !== '' ? FansHubGoogleAuth::getCode($secret) : '');
        $this->view->assign('qrUrl', $secret !== '' ? FansHubGoogleAuth::qrUrl('login', $secret, $issuer) : '');

        $bound = [];
        try {
            $rows = Db::name('fans_account')
                ->alias('a')
                ->join('user u', 'u.id = a.user_id', 'LEFT')
                ->where('a.google_secret', '<>', '')
                ->field('a.user_id,a.google_secret,u.mobile,u.nickname')
                ->order('a.user_id', 'desc')
                ->limit(200)
                ->select();
            foreach ($rows ?: [] as $row) {
                $s = FansHubGoogleAuth::normalizeSecret($row['google_secret'] ?? '');
                $bound[] = [
                    'user_id'  => (int)$row['user_id'],
                    'mobile'   => (string)($row['mobile'] ?? ''),
                    'nickname' => (string)($row['nickname'] ?? ''),
                    'secret'   => $s,
                    'masked'   => $s === '' ? '' : (substr($s, 0, 4) . '****' . substr($s, -4)),
                    'code'     => $s !== '' ? FansHubGoogleAuth::getCode($s) : '',
                ];
            }
        } catch (\Throwable $e) {
            // 列未 patch 时不阻断页面
            $this->view->assign('columnMissing', true);
            $this->view->assign('columnError', $e->getMessage());
        }
        $this->view->assign('boundUsers', $bound);
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
        $data['google_auth_login_enabled'] = $this->request->post('google_auth_login_enabled') ? true : false;
        $secret = FansHubGoogleAuth::normalizeSecret($this->request->post('google_auth_secret', ''));
        $data['google_auth_secret'] = $secret;
        $issuer = trim((string)$this->request->post('google_auth_issuer', 'FansHub'));
        $data['google_auth_issuer'] = $issuer !== '' ? mb_substr($issuer, 0, 64) : 'FansHub';
        if (!FansHubService::saveFanshubConfig($data)) {
            $this->error('保存失败，请检查文件权限');
        }
        $this->success('保存成功');
    }

    public function generate()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $secret = FansHubGoogleAuth::createSecret(16);
        $issuer = trim((string)$this->request->post('issuer', 'FansHub')) ?: 'FansHub';
        $this->success('已生成', null, [
            'secret'       => $secret,
            'current_code' => FansHubGoogleAuth::getCode($secret),
            'qr_url'       => FansHubGoogleAuth::qrUrl('login', $secret, $issuer),
        ]);
    }

    public function preview()
    {
        $secret = FansHubGoogleAuth::normalizeSecret($this->request->get('secret', ''));
        if ($secret === '') {
            $cfg = ThinkConfig::get('fanshub') ?: [];
            $secret = FansHubGoogleAuth::normalizeSecret($cfg['google_auth_secret'] ?? '');
        }
        if ($secret === '') {
            $this->error('密钥为空');
        }
        $issuer = trim((string)$this->request->get('issuer', '')) ?: 'FansHub';
        $this->success('ok', null, [
            'secret'       => $secret,
            'current_code' => FansHubGoogleAuth::getCode($secret),
            'qr_url'       => FansHubGoogleAuth::qrUrl('login', $secret, $issuer),
            'remain'       => 30 - (time() % 30),
        ]);
    }
}
