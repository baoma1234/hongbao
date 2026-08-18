<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\library\FansHubPayGateway;
use app\common\library\FansHubPayCurlLog;
use think\Response;

/**
 * 充值/提现网关回调与测试页
 */
class Pay extends Api
{
    protected $noNeedLogin = ['*'];
    protected $noNeedRight = '*';

    public function _initialize()
    {
        parent::_initialize();
    }

    /**
     * 充值异步通知
     */
    public function rechargenotify()
    {
        return $this->handleNotify('recharge');
    }

    /**
     * 提现异步通知
     */
    public function withdrawnotify()
    {
        return $this->handleNotify('withdraw');
    }

    /**
     * 代付反查：万汇通 / BS 等平台下单前主动校验订单
     * BS：POST JSON → { merchantOrderNo, code, message, sign }
     */
    public function withdrawverify()
    {
        $channelId = (int)$this->request->param('channel_id', 0);
        $params = array_merge($this->request->get(), $this->request->post());
        $raw = $this->request->getContent();
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $params = array_merge($params, $json);
            }
        }
        $clientIp = (string)$this->request->ip();
        $row = \think\Db::name('fans_pay_channel')->where('id', $channelId)->find();
        $handler = $row ? (string)$row['handler'] : '';
        try {
            if ($handler === 'bs') {
                $body = \app\common\library\FansHubBsGateway::handleWithdrawVerify($channelId, $params, $clientIp);
            } else {
                $body = \app\common\library\FansHubWanhuitongGateway::handleWithdrawVerify($channelId, $params, $clientIp);
            }
            FansHubPayCurlLog::logInbound(FansHubPayCurlLog::SCENE_WITHDRAW, [
                'gateway'  => $handler !== '' ? $handler : 'wanhuitong',
                'action'   => 'withdraw_verify',
                'order_no' => FansHubPayCurlLog::pickOrderNo($params),
                'ip'       => $clientIp,
                'url'      => (string)$this->request->url(true),
                'params'   => $params,
                'raw_body' => is_string($raw) ? $raw : '',
                'result'   => is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$body,
            ]);
            return json($body);
        } catch (\Throwable $e) {
            FansHubPayCurlLog::logInbound(FansHubPayCurlLog::SCENE_WITHDRAW, [
                'gateway'  => $handler !== '' ? $handler : 'wanhuitong',
                'action'   => 'withdraw_verify',
                'order_no' => FansHubPayCurlLog::pickOrderNo($params),
                'ip'       => $clientIp,
                'url'      => (string)$this->request->url(true),
                'params'   => $params,
                'raw_body' => is_string($raw) ? $raw : '',
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 充值同步跳转
     */
    public function rechargereturn()
    {
        return $this->handleReturn('recharge');
    }

    /**
     * 提现同步跳转
     */
    public function withdrawreturn()
    {
        return $this->handleReturn('withdraw');
    }

    /**
     * 测试充值提交页（模拟第三方收银台）— 仅 debug / 显式开启时可用
     */
    public function testsubmit()
    {
        $this->assertPayTestEnabled();
        $params = $this->request->param();
        $channelId = (int)($params['channel_id'] ?? 0);
        if ($channelId <= 0) {
            $channelId = $this->guessChannelIdByOrder($params, 'recharge');
        }
        return $this->renderTestPage('recharge', $channelId, $params);
    }

    /**
     * 测试提现提交页
     */
    public function testwithdrawsubmit()
    {
        $this->assertPayTestEnabled();
        $params = $this->request->param();
        $channelId = (int)($params['channel_id'] ?? 0);
        if ($channelId <= 0) {
            $channelId = $this->guessChannelIdByOrder($params, 'withdraw');
        }
        return $this->renderTestPage('withdraw', $channelId, $params);
    }

    protected function assertPayTestEnabled()
    {
        $debug = (bool)\think\Config::get('app_debug');
        $enabled = false;
        try {
            $enabled = (bool)\app\common\library\FansHubService::config('pay_test_enabled', false);
        } catch (\Throwable $e) {
            $enabled = false;
        }
        if (!$debug && !$enabled) {
            $this->error(__('Page not found'), null, 404);
        }
    }

    protected function handleNotify($type)
    {
        $channelId = (int)$this->request->param('channel_id', 0);
        $params = array_merge($this->request->get(), $this->request->post());
        $raw = $this->request->getContent();
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $params = array_merge($params, $json);
            }
        }
        $scene = $type === 'withdraw' ? FansHubPayCurlLog::SCENE_WITHDRAW : FansHubPayCurlLog::SCENE_RECHARGE;
        $clientIp = (string)$this->request->ip();
        $handler = '';
        try {
            if ($channelId <= 0) {
                throw new \RuntimeException('channel_id missing');
            }
            $row = \think\Db::name('fans_pay_channel')->where('id', $channelId)->find();
            $handler = $row ? (string)$row['handler'] : '';
            if ($handler === 'jiuyuan') {
                $body = $type === 'withdraw'
                    ? \app\common\library\FansHubJiuyuanGateway::handleWithdrawNotify($channelId, $params)
                    : \app\common\library\FansHubJiuyuanGateway::handleRechargeNotify($channelId, $params);
            } elseif ($handler === 'wanhuitong') {
                $body = $type === 'withdraw'
                    ? \app\common\library\FansHubWanhuitongGateway::handleWithdrawNotify($channelId, $params, $clientIp)
                    : \app\common\library\FansHubWanhuitongGateway::handleRechargeNotify($channelId, $params, $clientIp);
            } elseif ($handler === 'bs') {
                $body = $type === 'withdraw'
                    ? \app\common\library\FansHubBsGateway::handleWithdrawNotify($channelId, $params, $clientIp)
                    : \app\common\library\FansHubBsGateway::handleRechargeNotify($channelId, $params, $clientIp);
            } else {
                $body = $type === 'withdraw'
                    ? FansHubPayGateway::handleWithdrawNotify($channelId, $params)
                    : FansHubPayGateway::handleRechargeNotify($channelId, $params);
            }
            FansHubPayCurlLog::logInbound($scene, [
                'gateway'    => $handler !== '' ? $handler : 'merchant',
                'action'     => $type . '_notify',
                'order_no'   => FansHubPayCurlLog::pickOrderNo($params),
                'channel_id' => $channelId,
                'ip'         => $clientIp,
                'url'        => (string)$this->request->url(true),
                'params'     => $params,
                'raw_body'   => is_string($raw) ? $raw : '',
                'result'     => is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return Response::create($body, 'html', 200)->contentType('text/plain', 'utf-8');
        } catch (\Throwable $e) {
            FansHubPayCurlLog::logInbound($scene, [
                'gateway'    => $handler !== '' ? $handler : 'merchant',
                'action'     => $type . '_notify',
                'order_no'   => FansHubPayCurlLog::pickOrderNo($params),
                'channel_id' => $channelId,
                'ip'         => $clientIp,
                'url'        => (string)$this->request->url(true),
                'params'     => $params,
                'raw_body'   => is_string($raw) ? $raw : '',
                'error'      => $e->getMessage(),
            ]);
            return Response::create('FAIL:' . $e->getMessage(), 'html', 200)->contentType('text/plain', 'utf-8');
        }
    }

    protected function handleReturn($type)
    {
        $params = $this->request->param();
        $orderNo = trim((string)($params['order_no'] ?? ''));
        $status = strtolower(trim((string)($params['status'] ?? 'success')));
        $channelId = (int)($params['channel_id'] ?? 0);
        $returnUrl = FansHubPayGateway::defaultReturnUrl();
        if ($channelId > 0) {
            try {
                $row = \think\Db::name('fans_pay_channel')->where('id', $channelId)->find();
                if ($row) {
                    $cfg = FansHubPayGateway::merchantConfig($row);
                    if ($cfg['return_url'] !== '') {
                        $returnUrl = $cfg['return_url'];
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        $label = $type === 'withdraw' ? '提现' : '充值';
        $msg = in_array($status, ['success', 'paid', '1'], true)
            ? ($label . '处理完成，订单号：' . $orderNo)
            : ($label . '未完成，订单号：' . $orderNo);
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '结果</title></head><body style="font-family:sans-serif;padding:24px;text-align:center;">'
            . '<p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="' . htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') . '">返回个人中心</a></p>'
            . '<script>setTimeout(function(){location.href=' . json_encode($returnUrl) . ';},1500);</script>'
            . '</body></html>';
        return Response::create($html, 'html', 200)->contentType('text/html', 'utf-8');
    }

    protected function renderTestPage($type, $channelId, array $params)
    {
        $label = $type === 'withdraw' ? '提现' : '充值';
        $merchantKey = '';
        if ($channelId > 0) {
            $row = \think\Db::name('fans_pay_channel')->where('id', $channelId)->find();
            if ($row) {
                $cfg = FansHubPayGateway::merchantConfig($row);
                $merchantKey = $cfg['merchant_key'];
            }
        }
        $orderNo = (string)($params['order_no'] ?? '');
        $amount = (string)($params['amount'] ?? '0.00');
        $notifyUrl = (string)($params['notify_url'] ?? '');
        $returnUrl = (string)($params['return_url'] ?? FansHubPayGateway::defaultReturnUrl());
        $merchantNo = (string)($params['merchant_no'] ?? '');
        $okPayload = [
            'merchant_no' => $merchantNo,
            'order_no'    => $orderNo,
            'amount'      => $amount,
            'status'      => 'success',
            'trade_no'    => 'TEST' . time(),
            'timestamp'   => (string)time(),
        ];
        $failPayload = [
            'merchant_no' => $merchantNo,
            'order_no'    => $orderNo,
            'amount'      => $amount,
            'status'      => 'failed',
            'message'     => '测试失败',
            'timestamp'   => (string)time(),
        ];
        if ($merchantKey !== '') {
            $okPayload['sign'] = FansHubPayGateway::sign($okPayload, $merchantKey);
            $failPayload['sign'] = FansHubPayGateway::sign($failPayload, $merchantKey);
        }
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>测试' . $label . '网关</title>'
            . '<style>body{font-family:sans-serif;padding:20px;max-width:480px;margin:0 auto;}'
            . '.card{border:1px solid #ddd;border-radius:8px;padding:16px;margin:16px 0;}'
            . 'button{width:100%;padding:12px;margin-top:8px;border:0;border-radius:6px;font-size:16px;}'
            . '.ok{background:#07c160;color:#fff;}.fail{background:#fa5151;color:#fff;}</style></head><body>'
            . '<h3>测试' . $label . '商户收银台</h3>'
            . '<div class="card"><p>商户号：' . htmlspecialchars($merchantNo, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>订单号：' . htmlspecialchars($orderNo, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>金额：￥' . htmlspecialchars($amount, ENT_QUOTES, 'UTF-8') . '</p></div>'
            . $this->buildNotifyForm($notifyUrl, $okPayload, '模拟' . $label . '成功', 'ok')
            . $this->buildNotifyForm($notifyUrl, $failPayload, '模拟' . $label . '失败', 'fail')
            . '<p><a href="' . htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') . '">返回</a></p>'
            . '</body></html>';
        return Response::create($html, 'html', 200)->contentType('text/html', 'utf-8');
    }

    protected function buildNotifyForm($action, array $payload, $label, $class)
    {
        $fields = '';
        foreach ($payload as $k => $v) {
            $fields .= '<input type="hidden" name="' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '" value="'
                . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '">';
        }
        return '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">'
            . $fields . '<button type="submit" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button></form>';
    }

    protected function guessChannelIdByOrder(array $params, $type)
    {
        $orderNo = trim((string)($params['order_no'] ?? ''));
        if ($orderNo === '') {
            return 0;
        }
        $table = $type === 'withdraw' ? 'fans_withdraw_order' : 'fans_recharge_order';
        $row = \think\Db::name($table)->where('order_no', $orderNo)->find();
        return $row ? (int)$row['channel_id'] : 0;
    }
}
