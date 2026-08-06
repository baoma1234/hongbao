<?php

namespace app\common\library;

use think\Db;

/**
 * 万汇通 / wanhuipay（RSA2 / SHA256withRSA）
 *
 * 网关：
 *  支付 https://api.wanhuimoney.com/api/payment/create
 *  查询 https://api.wanhuimoney.com/api/payment/query
 *  代付 https://api.wanhuimoney.com/api/withdraw/create
 *  代付查询 https://api.wanhuimoney.com/api/withdraw/query
 *  代付反查：商户后台配置 URL → 本站 /api/pay/withdrawverify
 *  商户余额 https://api.wanhuimoney.com/api/withdraw/balance
 * 回调 IP：18.162.71.242, 95.40.141.160
 *
 * 商户→网关：商户私钥签名；网关→商户回调：平台公钥验签
 * 签名串：非空业务参数（排除 sign）→ ksort → urldecode(http_build_query)
 * 架构：总商户(fans_pay_merchant) 存凭证；充/提通道只挂 merchant_id + payment_channel
 */
class FansHubWanhuitongGateway
{
    const GATEWAY_NAME = 'wanhuipay';

    public static function defaultEndpoints()
    {
        return [
            'submit_url'         => 'https://api.wanhuimoney.com/api/payment/create',
            'query_url'          => 'https://api.wanhuimoney.com/api/payment/query',
            'withdraw_url'       => 'https://api.wanhuimoney.com/api/withdraw/create',
            'withdraw_query_url' => 'https://api.wanhuimoney.com/api/withdraw/query',
            'balance_url'        => 'https://api.wanhuimoney.com/api/withdraw/balance',
            'callback_ips'       => ['18.162.71.242', '95.40.141.160'],
        ];
    }

    /**
     * 钱包通道编码 => 显示名（官方标准钱包，不含快捷）
     */
    public static function paymentChannels()
    {
        return [
            'Kdou'      => 'K豆钱包',
            'Abpay'     => 'AB闪付',
            'Cbi'       => 'C币钱包',
            'Jdpay'     => 'JD钱包',
            'Sanliuwu'  => '365钱包',
            'Hdpay'     => 'HD钱包',
            'Mbpay'     => 'M币钱包',
            'Qianneng'  => '钱能钱包',
            'Fpay'      => 'FPAY钱包',
            'Jiubaba'   => '988PAY',
            'Balingba'  => '808PAY',
            'Ersansi'   => '234PAY',
            'Vippay'    => 'VIP钱包',
            'Upay'      => 'Upay',
            'Okpay'     => 'OKPAY',
            'Topay'     => 'TOPAY',
            'Gopay'     => 'GOPAY',
            'Nopay'     => 'NO钱包',
            'Goubaopay' => '购宝钱包',
            'Agpay'     => 'AG钱包',
            'Wanbi'     => '万币',
            'Biqu'      => 'Biqu钱包',
            'Bobi'      => '波币钱包',
            'Mpay'      => 'Mpay钱包',
        ];
    }

    /** 快捷通道（充值侧可选） */
    public static function quickPaymentChannels()
    {
        return [
            'Fpay_quick'      => 'FPAY快捷支付',
            'Nopay_quick'     => 'NO快捷支付',
            'Qianneng_quick'  => '钱能快捷支付',
            'Goubaopay_quick' => '购宝快捷支付',
            'Upay_quick'      => 'Upay快捷支付',
            'Abpay_quick'     => 'ABpay快捷支付',
        ];
    }

    public static function allPaymentChannels()
    {
        return self::paymentChannels() + self::quickPaymentChannels();
    }

    public static function walletLabel($code)
    {
        $all = self::allPaymentChannels();
        $code = trim((string)$code);
        return $all[$code] ?? $code;
    }

    /** 文档：部分钱包代付必填 out_user_id */
    public static function needsOutUserId($code)
    {
        $base = preg_replace('/_quick$/', '', trim((string)$code));
        return in_array($base, ['Nopay', 'Goubaopay'], true);
    }

    public static function loadMerchant($merchantId)
    {
        $merchantId = (int)$merchantId;
        if ($merchantId <= 0) {
            return null;
        }
        return Db::name('fans_pay_merchant')->where('id', $merchantId)->find() ?: null;
    }

    public static function findMerchantByNo($merchantNo)
    {
        $merchantNo = trim((string)$merchantNo);
        if ($merchantNo === '') {
            return null;
        }
        return Db::name('fans_pay_merchant')
            ->where('gateway', 'wanhuitong')
            ->where('merchant_no', $merchantNo)
            ->find() ?: null;
    }

    /**
     * 为总商户确保某钱包的充/提通道存在
     * @return array{created:bool,id:int,channel:array}
     */
    public static function ensureWalletChannel(array $merchant, $walletCode, $type, array $opts = [])
    {
        $walletCode = trim((string)$walletCode);
        $type = $type === 'withdraw' ? 'withdraw' : 'recharge';
        $label = self::walletLabel($walletCode);
        if ($label === $walletCode && !isset(self::allPaymentChannels()[$walletCode])) {
            throw new \RuntimeException('未知钱包通道编码: ' . $walletCode);
        }
        $merchantId = (int)($merchant['id'] ?? 0);
        $merchantNo = trim((string)($merchant['merchant_no'] ?? ''));
        if ($merchantId <= 0 || $merchantNo === '') {
            throw new \RuntimeException('总商户无效');
        }

        $exist = Db::name('fans_pay_channel')
            ->where('handler', 'wanhuitong')
            ->where('type', $type)
            ->where('pay_channel', $walletCode)
            ->where('merchant_id', $merchantId)
            ->find();
        if ($exist) {
            return ['created' => false, 'id' => (int)$exist['id'], 'channel' => $exist];
        }

        $defaults = self::defaultEndpoints();
        $status = ($opts['status'] ?? 'hidden') === 'normal' ? 'normal' : 'hidden';
        $name = trim((string)($opts['name'] ?? ''));
        if ($name === '') {
            $name = $label . ($type === 'withdraw' ? '代付' : '充值');
        }
        $site = rtrim(trim((string)($merchant['site'] ?? '')), '/');
        $returnUrl = $site !== ''
            ? ($site . '/999/#/pages/wallet/wallet')
            : FansHubPayGateway::defaultReturnUrl();
        $ips = trim((string)($merchant['callback_ips'] ?? ''));
        $ipList = $ips !== '' ? preg_split('/[\s,;]+/', $ips, -1, PREG_SPLIT_NO_EMPTY) : $defaults['callback_ips'];

        $cfg = [
            'gateway'             => 'wanhuitong',
            'merchant_id'         => $merchantId,
            'merchant_no'         => $merchantNo,
            'payment_channel'     => $walletCode,
            'pay_channel'         => $walletCode,
            'submit_url'          => $type === 'withdraw' ? $defaults['withdraw_url'] : $defaults['submit_url'],
            'query_url'           => $defaults['query_url'],
            'withdraw_url'        => $defaults['withdraw_url'],
            'withdraw_query_url'  => $defaults['withdraw_query_url'],
            'balance_url'         => $defaults['balance_url'],
            'callback_ips'        => array_values($ipList),
            'notify_ack'          => 'SUCCESS',
            'return_url'          => $returnUrl,
            'product_name'        => $type === 'withdraw' ? '账户提现' : '账户充值',
            'withdraw_type'       => '2',
            // 密钥不落通道：运行时从总商户读取
        ];

        $now = time();
        $iconPath = '/assets/img/wallets/' . $walletCode . '.png';
        $publicRoot = (defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__, 3) . DIRECTORY_SEPARATOR)) . 'public';
        $iconFile = $publicRoot . str_replace('/', DIRECTORY_SEPARATOR, $iconPath);
        if (!is_file($iconFile)) {
            $iconPath = '';
        }
        $row = [
            'type'         => $type,
            'name'         => $name,
            'icon'         => $iconPath,
            'tip'          => '',
            'handler'      => 'wanhuitong',
            'merchant_id'  => $merchantId,
            'submit_url'   => $cfg['submit_url'],
            'merchant_no'  => $merchantNo,
            'merchant_key' => '',
            'pay_type'     => '',
            'pay_channel'  => $walletCode,
            'notify_url'   => '',
            'return_url'   => $returnUrl,
            'product_name' => $cfg['product_name'],
            'config'       => json_encode($cfg, JSON_UNESCAPED_UNICODE),
            'min_amount'   => $type === 'withdraw' ? 50 : 10,
            'max_amount'   => 50000,
            'weigh'        => 100,
            'status'       => $status,
            'createtime'   => $now,
            'updatetime'   => $now,
        ];
        $id = (int)Db::name('fans_pay_channel')->insertGetId($row);
        $action = $type === 'withdraw' ? 'withdrawnotify' : 'rechargenotify';
        $notify = ($site !== '' ? $site : '') . '/api/pay/' . $action . '?channel_id=' . $id;
        if ($site === '') {
            $notify = '/api/pay/' . $action . '?channel_id=' . $id;
        }
        $cfg['notify_url'] = $notify;
        Db::name('fans_pay_channel')->where('id', $id)->update([
            'notify_url' => $notify,
            'config'     => json_encode($cfg, JSON_UNESCAPED_UNICODE),
            'updatetime' => $now,
        ]);
        $row['id'] = $id;
        $row['notify_url'] = $notify;
        $row['config'] = json_encode($cfg, JSON_UNESCAPED_UNICODE);
        return ['created' => true, 'id' => $id, 'channel' => $row];
    }

    public static function config(array $channel)
    {
        $cfg = FansHubWallet::decodeConfigPublic($channel['config'] ?? '');
        $defaults = self::defaultEndpoints();
        $merchantId = (int)($channel['merchant_id'] ?? $cfg['merchant_id'] ?? 0);
        $merchant = $merchantId > 0 ? self::loadMerchant($merchantId) : null;
        if (!$merchant) {
            $mNo = trim((string)($channel['merchant_no'] ?? $cfg['merchant_no'] ?? ''));
            if ($mNo !== '') {
                $merchant = self::findMerchantByNo($mNo);
                if ($merchant) {
                    $merchantId = (int)$merchant['id'];
                }
            }
        }

        $submit = trim((string)($channel['submit_url'] ?? $cfg['submit_url'] ?? ''));
        $query = trim((string)($cfg['query_url'] ?? ''));
        $withdraw = trim((string)($cfg['withdraw_url'] ?? ''));
        $withdrawQuery = trim((string)($cfg['withdraw_query_url'] ?? ''));
        $balanceUrl = trim((string)($cfg['balance_url'] ?? ''));

        $ips = $cfg['callback_ips'] ?? null;
        if ($merchant && !empty($merchant['callback_ips'])) {
            $ips = $merchant['callback_ips'];
        }
        if ($ips === null) {
            $ips = $defaults['callback_ips'];
        }
        if (is_string($ips)) {
            $ips = preg_split('/[\s,;]+/', $ips, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($ips) || !$ips) {
            $ips = $defaults['callback_ips'];
        }

        $merchantNo = trim((string)($channel['merchant_no'] ?? $cfg['merchant_no'] ?? ''));
        $privateKey = trim((string)($cfg['private_key'] ?? $cfg['merchant_private_key'] ?? $channel['merchant_key'] ?? ''));
        $platformPub = trim((string)($cfg['platform_public_key'] ?? $cfg['public_key'] ?? ''));
        if ($merchant) {
            if ($merchantNo === '') {
                $merchantNo = trim((string)$merchant['merchant_no']);
            }
            if (trim((string)$merchant['private_key']) !== '') {
                $privateKey = trim((string)$merchant['private_key']);
            }
            if (trim((string)$merchant['platform_public_key']) !== '') {
                $platformPub = trim((string)$merchant['platform_public_key']);
            }
        }

        return [
            'submit_url'          => $submit !== '' ? $submit : $defaults['submit_url'],
            'query_url'           => $query !== '' ? $query : $defaults['query_url'],
            'withdraw_url'        => $withdraw !== '' ? $withdraw : $defaults['withdraw_url'],
            'withdraw_query_url'  => $withdrawQuery !== '' ? $withdrawQuery : $defaults['withdraw_query_url'],
            'balance_url'         => $balanceUrl !== '' ? $balanceUrl : $defaults['balance_url'],
            'merchant_id'         => $merchantId,
            'merchant_no'         => $merchantNo,
            'payment_channel'     => trim((string)($channel['pay_channel'] ?? $cfg['payment_channel'] ?? $cfg['pay_channel'] ?? 'Bobi')),
            'pay_type'            => trim((string)($channel['pay_type'] ?? $cfg['pay_type'] ?? '')),
            'private_key'         => $privateKey,
            'platform_public_key' => $platformPub,
            'notify_url'          => trim((string)($channel['notify_url'] ?? $cfg['notify_url'] ?? '')),
            'return_url'          => trim((string)($channel['return_url'] ?? $cfg['return_url'] ?? '')),
            'product_name'        => trim((string)($channel['product_name'] ?? $cfg['product_name'] ?? '账户充值')),
            'notify_ack'          => trim((string)($cfg['notify_ack'] ?? 'SUCCESS')),
            'callback_ips'        => array_values(array_filter(array_map('trim', $ips))),
            'skip_ip_check'       => !empty($cfg['skip_ip_check']),
            'withdraw_type'       => trim((string)($cfg['withdraw_type'] ?? '2')) ?: '2',
            'extra_password'      => trim((string)($cfg['extra_password'] ?? '')),
        ];
    }

    /**
     * 组装待签名字符串（与文档一致）
     */
    public static function buildSignString(array $params)
    {
        unset($params['sign']);
        foreach ($params as $k => $v) {
            if (is_array($v) || is_object($v)) {
                $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($v)) {
                $params[$k] = $v ? '1' : '0';
            } else {
                $params[$k] = (string)$v;
            }
        }
        $params = array_filter($params, function ($v) {
            return $v !== null && $v !== '';
        });
        ksort($params);
        return urldecode(http_build_query($params));
    }

    public static function normalizePem($key, $type = 'private')
    {
        $key = trim((string)$key);
        if ($key === '') {
            return '';
        }
        $key = str_replace(["\r\n", "\r"], "\n", $key);
        if (strpos($key, 'BEGIN') !== false) {
            return $key;
        }
        $body = preg_replace('/\s+/', '', $key);
        $body = chunk_split($body, 64, "\n");
        if ($type === 'public') {
            return "-----BEGIN PUBLIC KEY-----\n" . $body . "-----END PUBLIC KEY-----";
        }
        // 兼容 PKCS#8 / PKCS#1：无头时按 PRIVATE KEY 处理
        if (stripos($key, 'RSA PRIVATE') !== false) {
            return $key;
        }
        return "-----BEGIN PRIVATE KEY-----\n" . $body . "-----END PRIVATE KEY-----";
    }

    public static function sign(array $params, $privateKey)
    {
        $signString = self::buildSignString($params);
        $pem = self::normalizePem($privateKey, 'private');
        $res = openssl_pkey_get_private($pem);
        if ($res === false) {
            throw new \RuntimeException('万汇通商户私钥无效，请检查 PEM');
        }
        $ok = openssl_sign($signString, $signature, $res, OPENSSL_ALGO_SHA256);
        if (is_resource($res)) {
            @openssl_free_key($res);
        }
        if (!$ok) {
            throw new \RuntimeException('万汇通 RSA2 签名失败');
        }
        return base64_encode($signature);
    }

    public static function verify(array $params, $platformPublicKey)
    {
        $sign = trim((string)($params['sign'] ?? ''));
        if ($sign === '' || $platformPublicKey === '') {
            return false;
        }
        $signString = self::buildSignString($params);
        $pem = self::normalizePem($platformPublicKey, 'public');
        $res = openssl_pkey_get_public($pem);
        if ($res === false) {
            return false;
        }
        $ok = openssl_verify($signString, base64_decode($sign), $res, OPENSSL_ALGO_SHA256);
        if (is_resource($res)) {
            @openssl_free_key($res);
        }
        return $ok === 1;
    }

    public static function nonceStr($len = 16)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $out = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }

    protected static function assertConfig(array $cfg, $scene = 'recharge')
    {
        if ($cfg['merchant_no'] === '') {
            throw new \RuntimeException('wanhuipay 未配置商户号');
        }
        if ($cfg['private_key'] === '') {
            throw new \RuntimeException('wanhuipay 未配置商户私钥（config.private_key）');
        }
        if ($scene === 'recharge' && $cfg['notify_url'] === '') {
            throw new \RuntimeException('wanhuipay 未配置异步通知 notify_url');
        }
        if ($scene === 'notify' && $cfg['platform_public_key'] === '') {
            throw new \RuntimeException('wanhuipay 未配置平台公钥（config.platform_public_key）');
        }
        if ($scene !== 'query' && $cfg['payment_channel'] === '') {
            throw new \RuntimeException('wanhuipay 未配置通道编码 payment_channel（如 Bobi）');
        }
    }

    public static function assertCallbackIp($clientIp, array $cfg)
    {
        if (!empty($cfg['skip_ip_check'])) {
            return;
        }
        $clientIp = trim((string)$clientIp);
        $allow = $cfg['callback_ips'] ?? [];
        if (!$allow) {
            return;
        }
        if ($clientIp === '' || !in_array($clientIp, $allow, true)) {
            throw new \RuntimeException('callback ip denied: ' . $clientIp);
        }
    }

    /**
     * 商户余额查询：POST /api/withdraw/balance
     * @return array 完整响应；成功时 data 含 balance / frozen_balance / total_balance / usdt_balance
     */
    public static function queryBalance(array $channel)
    {
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'query');
        $params = [
            'merchant_no' => $cfg['merchant_no'],
            'timestamp'   => date('Y-m-d H:i:s'),
            'nonce_str'   => self::nonceStr(32),
        ];
        $params['sign'] = self::sign($params, $cfg['private_key']);
        $raw = self::httpPostJson($cfg['balance_url'], $params);
        $json = self::decodeJson($raw);
        if (!$json) {
            throw new \RuntimeException('wanhuipay 余额查询返回非 JSON：' . mb_substr((string)$raw, 0, 160));
        }
        if ((int)($json['code'] ?? 0) !== 200) {
            throw new \RuntimeException((string)($json['message'] ?? '余额查询失败') . ' (code=' . ($json['code'] ?? '') . ')');
        }
        return $json;
    }

    /**
     * 按通道 ID 查余额（充值/代付通道均可，同一商户号）
     */
    public static function queryBalanceByChannelId($channelId)
    {
        $row = Db::name('fans_pay_channel')->where('id', (int)$channelId)->find();
        if (!$row) {
            throw new \RuntimeException('channel not found');
        }
        if (($row['handler'] ?? '') !== 'wanhuitong') {
            throw new \RuntimeException('仅支持 wanhuipay 通道');
        }
        return self::queryBalance($row);
    }

    /**
     * 支付查询：POST /api/payment/query
     * 注意：order_no 为平台订单号（下单返回 data.order_no），不是商户单号
     */
    public static function queryPayment(array $channel, $platformOrderNo)
    {
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'query');
        $platformOrderNo = trim((string)$platformOrderNo);
        if ($platformOrderNo === '') {
            throw new \RuntimeException('wanhuipay 查询需平台订单号 order_no');
        }
        // 文档字段：merchant_no / timestamp / order_no / nonce_str / sign（无 sign_type）
        $params = [
            'merchant_no' => $cfg['merchant_no'],
            'timestamp'   => date('Y-m-d H:i:s'),
            'order_no'    => $platformOrderNo,
            'nonce_str'   => self::nonceStr(32),
        ];
        $params['sign'] = self::sign($params, $cfg['private_key']);
        $raw = self::httpPostJson($cfg['query_url'], $params);
        $json = self::decodeJson($raw);
        if (!$json) {
            throw new \RuntimeException('wanhuipay 查询返回非 JSON：' . mb_substr((string)$raw, 0, 160));
        }
        if ((int)($json['code'] ?? 0) !== 200) {
            throw new \RuntimeException((string)($json['message'] ?? '查询失败') . ' (code=' . ($json['code'] ?? '') . ')');
        }
        return $json;
    }

    /**
     * 从本地充值单 remark（wanhuipay:Pxxxx）解析平台单号后查询
     */
    public static function queryPaymentByMerchantOrder(array $channel, $merchantOrderNo)
    {
        $merchantOrderNo = self::clipOrderNo($merchantOrderNo);
        $order = Db::name('fans_recharge_order')->where('order_no', $merchantOrderNo)->find();
        if (!$order) {
            throw new \RuntimeException('本地充值单不存在');
        }
        $platformNo = self::extractPlatformOrderNo((string)($order['remark'] ?? ''));
        if ($platformNo === '') {
            throw new \RuntimeException('本地单尚未保存平台订单号，无法查询');
        }
        return self::queryPayment($channel, $platformNo);
    }

    /**
     * 主动查询并同步本地充值单（data.status：0初始 1成功 2失败）
     * @return string paid|failed|pending|unchanged
     */
    public static function syncRechargeFromQuery($channelId, $merchantOrderNo)
    {
        $channel = self::loadChannel((int)$channelId, 'recharge');
        $order = Db::name('fans_recharge_order')->where('order_no', self::clipOrderNo($merchantOrderNo))->find();
        if (!$order) {
            throw new \RuntimeException('order not found');
        }
        if ($order['status'] === 'paid') {
            return 'unchanged';
        }
        $json = self::queryPaymentByMerchantOrder($channel, $merchantOrderNo);
        $data = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : [];
        $status = (int)($data['status'] ?? -1);
        if ($status === 1) {
            $payAmount = (float)($data['amount'] ?? 0);
            if ($payAmount > 0 && abs($payAmount - (float)$order['amount']) > 0.009) {
                throw new \RuntimeException('amount mismatch');
            }
            $now = time();
            Db::startTrans();
            try {
                $fresh = Db::name('fans_recharge_order')->where('id', $order['id'])->lock(true)->find();
                if (!$fresh || $fresh['status'] === 'paid') {
                    Db::commit();
                    return 'unchanged';
                }
                $tradeNo = (string)($data['order_no'] ?? '');
                Db::name('fans_recharge_order')->where('id', $order['id'])->update([
                    'status'     => 'paid',
                    'remark'     => $tradeNo !== '' ? ('wanhuipay:' . $tradeNo) : (string)$fresh['remark'],
                    'updatetime' => $now,
                ]);
                FansHubWallet::creditBalancePublic(
                    (int)$order['user_id'],
                    (float)$order['amount'],
                    'recharge',
                    '充值到账 ' . $order['order_no'],
                    (string)$channel['name']
                );
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
            return 'paid';
        }
        if ($status === 2) {
            Db::name('fans_recharge_order')->where('id', $order['id'])->update([
                'status'     => 'failed',
                'remark'     => 'wanhuipay query status=2',
                'updatetime' => time(),
            ]);
            return 'failed';
        }
        return 'pending';
    }

    public static function extractPlatformOrderNo($remark)
    {
        $remark = trim((string)$remark);
        if (preg_match('/wanhuipay:([A-Za-z0-9_\-]+)/', $remark, $m)) {
            return $m[1];
        }
        if (preg_match('/wanhuitong:([A-Za-z0-9_\-]+)/', $remark, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * 代付查询：POST /api/withdraw/query
     * 注意：order_no 为平台订单号（下单返回 data.order_no），不是商户单号
     */
    public static function queryWithdraw(array $channel, $platformOrderNo)
    {
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'query');
        $platformOrderNo = trim((string)$platformOrderNo);
        if ($platformOrderNo === '') {
            throw new \RuntimeException('平台订单号 order_no 为空');
        }
        $params = [
            'merchant_no' => $cfg['merchant_no'],
            'timestamp'   => date('Y-m-d H:i:s'),
            'order_no'    => $platformOrderNo,
            'nonce_str'   => self::nonceStr(32),
        ];
        $params['sign'] = self::sign($params, $cfg['private_key']);
        $raw = self::httpPostJson($cfg['withdraw_query_url'], $params);
        $json = self::decodeJson($raw);
        if (!$json) {
            throw new \RuntimeException('wanhuipay 代付查询返回非 JSON：' . mb_substr((string)$raw, 0, 160));
        }
        if ((int)($json['code'] ?? 0) !== 200) {
            throw new \RuntimeException((string)($json['message'] ?? '代付查询失败') . ' (code=' . ($json['code'] ?? '') . ')');
        }
        return $json;
    }

    /**
     * 从本地提现单 remark（wanhuipay:Wxxxx）解析平台单号后查询
     */
    public static function queryWithdrawByMerchantOrder(array $channel, $merchantOrderNo)
    {
        $merchantOrderNo = self::clipOrderNo($merchantOrderNo);
        $order = Db::name('fans_withdraw_order')->where('order_no', $merchantOrderNo)->find();
        if (!$order) {
            throw new \RuntimeException('本地提现单不存在');
        }
        $platformNo = self::extractPlatformOrderNo((string)($order['remark'] ?? ''));
        if ($platformNo === '') {
            throw new \RuntimeException('本地单尚未保存平台订单号，无法查询');
        }
        return self::queryWithdraw($channel, $platformNo);
    }

    /**
     * 主动查询并同步本地提现单（data.status：0初始 1成功 2失败）
     * @return string paid|failed|pending|unchanged
     */
    public static function syncWithdrawFromQuery($channelId, $merchantOrderNo)
    {
        $channel = self::loadChannel((int)$channelId, 'withdraw');
        $order = Db::name('fans_withdraw_order')->where('order_no', self::clipOrderNo($merchantOrderNo))->find();
        if (!$order) {
            throw new \RuntimeException('order not found');
        }
        if (in_array($order['status'], ['paid', 'rejected', 'cancelled'], true)) {
            return 'unchanged';
        }
        $json = self::queryWithdrawByMerchantOrder($channel, $merchantOrderNo);
        $data = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : [];
        $status = (int)($data['status'] ?? -1);
        $platformNo = trim((string)($data['order_no'] ?? ''));
        $fee = (string)($data['fee'] ?? '');
        $actual = (string)($data['actual_amount'] ?? '');

        if ($status === 1) {
            $payAmount = (float)($data['amount'] ?? 0);
            if ($payAmount > 0 && abs($payAmount - (float)$order['amount']) > 0.009) {
                throw new \RuntimeException('amount mismatch');
            }
            $remark = $platformNo !== '' ? ('wanhuipay:' . $platformNo) : (string)$order['remark'];
            if ($fee !== '' || $actual !== '') {
                $remark .= ' fee=' . $fee . ' actual=' . $actual;
            }
            Db::name('fans_withdraw_order')->where('id', $order['id'])->update([
                'status'     => 'paid',
                'remark'     => mb_substr($remark, 0, 250),
                'updatetime' => time(),
            ]);
            return 'paid';
        }
        if ($status === 2) {
            FansHubWallet::refundWithdrawOrder($order, 'wanhuipay query status=2');
            return 'failed';
        }
        return 'pending';
    }

    /**
     * 充值下单：POST /api/payment/create（字段以官方文档为准）
     */
    public static function buildRechargeSubmit(array $channel, $orderNo, $amount, $userId)
    {
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'recharge');
        $orderNo = self::clipOrderNo($orderNo);
        $userId = (int)$userId;

        $notifyUrl = self::absoluteUrl($cfg['notify_url']);
        if ($notifyUrl === '' || strpos($notifyUrl, 'http') !== 0) {
            throw new \RuntimeException('wanhuipay notify_url 需为公网绝对地址，请在通道填写完整 https://域名/api/pay/rechargenotify?channel_id=xx');
        }

        // 仅提交文档字段，避免多余参数导致对方验签失败
        $params = [
            'merchant_no'       => $cfg['merchant_no'],
            'merchant_order_no' => $orderNo,
            'amount'            => number_format((float)$amount, 2, '.', ''),
            'notify_url'        => $notifyUrl,
            'timestamp'         => date('Y-m-d H:i:s'),
            'nonce_str'         => self::nonceStr(32),
            'sign_type'         => 'RSA2',
            'payment_channel'   => $cfg['payment_channel'],
            'attach'            => $orderNo,
            'out_user_id'       => (string)$userId,
        ];
        $realName = self::resolveRealName($userId);
        if ($realName !== '') {
            $params['real_name'] = $realName;
        }
        // Abpay 等要求带 extra；user_id 必须是字符串，否则对方返回 code=1013
        $extra = ['user_id' => (string)$userId];
        $payPwd = trim((string)($cfg['extra_password'] ?? ''));
        if ($payPwd !== '') {
            $extra['password'] = $payPwd;
        }
        $params['extra'] = $extra;

        // 签名时 extra 会先 JSON_ENCODE；HTTP body 仍传 object
        $params['sign'] = self::sign($params, $cfg['private_key']);

        $raw = self::httpPostJson($cfg['submit_url'], $params, FansHubPayCurlLog::logMeta(
            FansHubPayCurlLog::SCENE_RECHARGE,
            'wanhuitong',
            $orderNo,
            'payment_create'
        ));
        $json = self::decodeJson($raw);
        if (!$json) {
            throw new \RuntimeException('wanhuipay 下单返回非 JSON：' . mb_substr((string)$raw, 0, 160));
        }

        $code = $json['code'] ?? null;
        if ((int)$code !== 200) {
            $msg = (string)($json['message'] ?? $json['msg'] ?? 'wanhuipay 下单失败');
            throw new \RuntimeException($msg . ' (code=' . $code . ')');
        }

        $payUrl = self::pickPayUrl($json);
        if ($payUrl === '') {
            throw new \RuntimeException('wanhuipay 未返回支付地址（data.pay_url）');
        }

        $data = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : [];
        $platformNo = (string)($data['order_no'] ?? '');
        if ($platformNo !== '') {
            try {
                Db::name('fans_recharge_order')->where('order_no', $orderNo)->update([
                    'remark'     => 'wanhuipay:' . $platformNo,
                    'updatetime' => time(),
                ]);
            } catch (\Throwable $e) {
            }
        }

        return [
            'action'             => 'url',
            'method'             => 'GET',
            'url'                => $payUrl,
            'params'             => $params,
            'message'            => (string)($json['message'] ?? '请完成支付'),
            'gateway'            => 'wanhuitong',
            'brand'              => self::GATEWAY_NAME,
            'platform_order_no'  => $platformNo,
            'raw'                => $json,
        ];
    }

    /**
     * 代付创建：POST /api/withdraw/create
     * withdraw_type=2 钱包代付；=1 三方（微信/支付宝/银行卡）
     */
    public static function buildWithdrawSubmit(array $channel, $orderNo, $amount, $userId, array $accountInfo = [])
    {
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'recharge');
        $withdrawUrl = $cfg['withdraw_url'];
        if ($withdrawUrl === '') {
            throw new \RuntimeException('wanhuipay 未配置代付地址');
        }
        $orderNo = self::clipOrderNo($orderNo);
        $userId = (int)$userId;

        $notifyUrl = self::absoluteUrl($cfg['notify_url']);
        if ($notifyUrl === '' || strpos($notifyUrl, 'http') !== 0) {
            throw new \RuntimeException('wanhuipay 代付 notify_url 需为公网绝对地址');
        }

        $realName = trim((string)($accountInfo['accountname'] ?? $accountInfo['name'] ?? $accountInfo['realname'] ?? $accountInfo['real_name'] ?? ''));
        $address = trim((string)($accountInfo['account_or_address'] ?? $accountInfo['cardnumber'] ?? $accountInfo['account'] ?? $accountInfo['card'] ?? ''));
        $bankName = trim((string)($accountInfo['bankname'] ?? $accountInfo['bank'] ?? $accountInfo['bank_name'] ?? ''));
        $bankAccountNo = trim((string)($accountInfo['bank_account_no'] ?? $accountInfo['cardnumber'] ?? $accountInfo['account'] ?? ''));
        $bankAccountName = trim((string)($accountInfo['bank_account_name'] ?? $accountInfo['accountname'] ?? $accountInfo['name'] ?? $realName));

        $withdrawType = trim((string)($accountInfo['withdraw_type'] ?? $cfg['withdraw_type'] ?? '2'));
        if ($withdrawType !== '1') {
            $withdrawType = '2';
        }

        if ($address === '') {
            throw new \RuntimeException($withdrawType === '2'
                ? '请填写钱包地址（account_or_address）'
                : '请填写收款账号');
        }

        // 仅提交文档字段
        $params = [
            'merchant_no'         => $cfg['merchant_no'],
            'merchant_order_no'   => $orderNo,
            'amount'              => number_format((float)$amount, 2, '.', ''),
            'notify_url'          => $notifyUrl,
            'timestamp'           => date('Y-m-d H:i:s'),
            'nonce_str'           => self::nonceStr(32),
            'sign_type'           => 'RSA2',
            'payment_channel'     => $cfg['payment_channel'],
            'withdraw_type'       => $withdrawType,
            'account_or_address'  => $address,
            'attach'              => $orderNo,
            'out_user_id'         => (string)$userId,
        ];
        if ($realName === '') {
            $realName = self::resolveRealName($userId);
        }
        if ($realName !== '') {
            $params['real_name'] = $realName;
        }
        // 三方支付必传银行卡/微信支付宝字段
        if ($withdrawType === '1') {
            if ($bankAccountNo === '' || $bankAccountName === '' || $bankName === '') {
                throw new \RuntimeException('三方代付需填写 bank_account_no / bank_account_name / bank_name');
            }
            $params['bank_account_no'] = $bankAccountNo;
            $params['bank_account_name'] = $bankAccountName;
            $params['bank_name'] = $bankName;
        }

        $params['sign'] = self::sign($params, $cfg['private_key']);

        $raw = self::httpPostJson($withdrawUrl, $params, FansHubPayCurlLog::logMeta(
            FansHubPayCurlLog::SCENE_WITHDRAW,
            'wanhuitong',
            $orderNo,
            'withdraw_create'
        ));
        $json = self::decodeJson($raw);
        if (!$json) {
            throw new \RuntimeException('wanhuipay 代付返回非 JSON：' . mb_substr((string)$raw, 0, 160));
        }

        $code = $json['code'] ?? null;
        if ((int)$code !== 200) {
            $order = Db::name('fans_withdraw_order')->where('order_no', $orderNo)->find();
            if ($order) {
                FansHubWallet::refundWithdrawOrder($order, (string)($json['message'] ?? $json['msg'] ?? '代付提交失败'));
            }
            throw new \RuntimeException((string)($json['message'] ?? $json['msg'] ?? '代付提交失败') . ' (code=' . $code . ')');
        }

        $data = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : [];
        $platformNo = (string)($data['order_no'] ?? '');
        $fee = (string)($data['fee'] ?? '');
        $actual = (string)($data['actual_amount'] ?? '');
        $remark = $platformNo !== '' ? ('wanhuipay:' . $platformNo) : 'wanhuipay submitted';
        if ($fee !== '' || $actual !== '') {
            $remark .= ' fee=' . $fee . ' actual=' . $actual;
        }
        Db::name('fans_withdraw_order')->where('order_no', $orderNo)->update([
            'status'     => 'processing',
            'remark'     => mb_substr($remark, 0, 250),
            'updatetime' => time(),
        ]);

        return [
            'action'            => 'submitted',
            'message'           => (string)($json['message'] ?? '代付已提交'),
            'gateway'           => 'wanhuitong',
            'brand'             => self::GATEWAY_NAME,
            'status'            => 'processing',
            'platform_order_no' => $platformNo,
            'fee'               => $fee,
            'actual_amount'     => $actual,
            'raw'               => $json,
        ];
    }

    /**
     * 支付回调：POST notify_url
     * status：0初始 1成功 2失败；应答纯文本 SUCCESS（幂等）
     */
    public static function handleRechargeNotify($channelId, array $params, $clientIp = '')
    {
        $channel = self::loadChannel((int)$channelId, 'recharge');
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'notify');
        self::assertCallbackIp($clientIp, $cfg);
        $params = self::normalizeNotifyParams($params);
        if (!self::verify($params, $cfg['platform_public_key'])) {
            throw new \RuntimeException('sign error');
        }
        $ack = $cfg['notify_ack'] !== '' ? $cfg['notify_ack'] : 'SUCCESS';

        $merchantNo = trim((string)($params['merchant_no'] ?? ''));
        if ($merchantNo !== '' && $merchantNo !== $cfg['merchant_no']) {
            throw new \RuntimeException('merchant mismatch');
        }

        // 本地单号 = 商户订单号（勿误用平台 order_no）
        $merchantOrderNo = trim((string)($params['merchant_order_no'] ?? ''));
        if ($merchantOrderNo === '') {
            throw new \RuntimeException('merchant_order_no missing');
        }
        $platformOrderNo = trim((string)($params['order_no'] ?? ''));

        $order = Db::name('fans_recharge_order')->where('order_no', $merchantOrderNo)->find();
        if (!$order) {
            throw new \RuntimeException('order not found');
        }
        if ((int)$order['channel_id'] !== (int)$channelId) {
            throw new \RuntimeException('channel mismatch');
        }
        if ($order['status'] === 'paid') {
            return $ack;
        }

        $payAmount = (float)($params['amount'] ?? 0);
        if (abs($payAmount - (float)$order['amount']) > 0.009) {
            throw new \RuntimeException('amount mismatch');
        }

        $status = (int)($params['status'] ?? -1);
        // 0=初始：确认收到，不改本地支付态
        if ($status === 0) {
            if ($platformOrderNo !== '') {
                Db::name('fans_recharge_order')->where('id', $order['id'])->update([
                    'remark'     => 'wanhuipay:' . $platformOrderNo,
                    'updatetime' => time(),
                ]);
            }
            return $ack;
        }
        // 2=失败
        if ($status === 2) {
            Db::name('fans_recharge_order')->where('id', $order['id'])->update([
                'status'     => 'failed',
                'remark'     => $platformOrderNo !== ''
                    ? ('wanhuipay:' . $platformOrderNo . ' failed')
                    : 'wanhuipay status=2',
                'updatetime' => time(),
            ]);
            return $ack;
        }
        if ($status !== 1) {
            throw new \RuntimeException('unknown status=' . $status);
        }

        $now = time();
        Db::startTrans();
        try {
            $fresh = Db::name('fans_recharge_order')->where('id', $order['id'])->lock(true)->find();
            if (!$fresh || $fresh['status'] === 'paid') {
                Db::commit();
                return $ack;
            }
            Db::name('fans_recharge_order')->where('id', $order['id'])->update([
                'status'     => 'paid',
                'remark'     => $platformOrderNo !== ''
                    ? ('wanhuipay:' . $platformOrderNo)
                    : 'wanhuipay paid',
                'updatetime' => $now,
            ]);
            FansHubWallet::creditBalancePublic(
                (int)$order['user_id'],
                (float)$order['amount'],
                'recharge',
                '充值到账 ' . $merchantOrderNo,
                (string)$channel['name']
            );
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return $ack;
    }

    /**
     * 代付回调：POST 商户 notify_url
     * 字段：merchant_no, merchant_order_no, order_no, amount, actual_amount, fee, status, attach, complete_time, sign
     * status：0初始 1成功 2失败；应答纯文本 SUCCESS（幂等，成功后不再重发）
     */
    public static function handleWithdrawNotify($channelId, array $params, $clientIp = '')
    {
        $channel = self::loadChannel((int)$channelId, 'withdraw');
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'notify');
        self::assertCallbackIp($clientIp, $cfg);
        $params = self::normalizeNotifyParams($params);
        if (!self::verify($params, $cfg['platform_public_key'])) {
            throw new \RuntimeException('sign error');
        }
        $ack = $cfg['notify_ack'] !== '' ? $cfg['notify_ack'] : 'SUCCESS';

        $merchantNo = trim((string)($params['merchant_no'] ?? ''));
        if ($merchantNo !== '' && $merchantNo !== $cfg['merchant_no']) {
            throw new \RuntimeException('merchant mismatch');
        }

        // 本地单号 = 商户订单号（勿误用平台 order_no）
        $merchantOrderNo = trim((string)($params['merchant_order_no'] ?? ''));
        if ($merchantOrderNo === '') {
            throw new \RuntimeException('merchant_order_no missing');
        }
        $platformOrderNo = trim((string)($params['order_no'] ?? ''));
        $fee = trim((string)($params['fee'] ?? ''));
        $actual = trim((string)($params['actual_amount'] ?? ''));

        $order = Db::name('fans_withdraw_order')->where('order_no', $merchantOrderNo)->find();
        if (!$order) {
            throw new \RuntimeException('order not found');
        }
        if ((int)$order['channel_id'] !== (int)$channelId) {
            throw new \RuntimeException('channel mismatch');
        }
        if (in_array($order['status'], ['paid', 'rejected', 'cancelled'], true)) {
            return $ack;
        }

        $payAmount = (float)($params['amount'] ?? 0);
        if ($payAmount > 0 && abs($payAmount - (float)$order['amount']) > 0.009) {
            throw new \RuntimeException('amount mismatch');
        }

        $status = (int)($params['status'] ?? -1);
        $now = time();

        // 0=初始：确认收到，不改终态
        if ($status === 0) {
            if ($platformOrderNo !== '') {
                $remark = 'wanhuipay:' . $platformOrderNo;
                if ($fee !== '' || $actual !== '') {
                    $remark .= ' fee=' . $fee . ' actual=' . $actual;
                }
                Db::name('fans_withdraw_order')->where('id', $order['id'])->update([
                    'remark'     => mb_substr($remark, 0, 250),
                    'updatetime' => $now,
                ]);
            }
            return $ack;
        }

        // 2=失败：退回红宝
        if ($status === 2) {
            FansHubWallet::refundWithdrawOrder(
                $order,
                $platformOrderNo !== ''
                    ? ('wanhuipay:' . $platformOrderNo . ' failed')
                    : 'wanhuipay status=2'
            );
            return $ack;
        }

        if ($status !== 1) {
            throw new \RuntimeException('unknown status=' . $status);
        }

        // 1=成功
        $remark = $platformOrderNo !== '' ? ('wanhuipay:' . $platformOrderNo) : 'wanhuipay paid';
        if ($fee !== '' || $actual !== '') {
            $remark .= ' fee=' . $fee . ' actual=' . $actual;
        }
        if (!empty($params['complete_time'])) {
            $remark .= ' at=' . trim((string)$params['complete_time']);
        }
        Db::name('fans_withdraw_order')->where('id', $order['id'])->update([
            'status'     => 'paid',
            'remark'     => mb_substr($remark, 0, 250),
            'updatetime' => $now,
        ]);
        return $ack;
    }

    /**
     * 代付反查：平台 POST 商户反查地址，确认订单来源合法
     * 验签通过且订单存在、金额/收款账号一致、状态为等待代付 → exists=true, status=pending
     *
     * @param int   $channelId 可选；为 0 时按 merchant_no 匹配 wanhuitong 代付通道
     * @return array 直接作为 JSON 响应体
     */
    public static function handleWithdrawVerify($channelId, array $params, $clientIp = '')
    {
        $params = self::normalizeNotifyParams($params);
        $merchantNo = trim((string)($params['merchant_no'] ?? ''));
        $merchantOrderNo = trim((string)($params['merchant_order_no'] ?? ''));
        $amount = number_format((float)($params['amount'] ?? 0), 2, '.', '');
        $address = trim((string)($params['account_or_address'] ?? ''));

        $fail = function ($message, $exists = false, $status = '') use ($merchantOrderNo) {
            $data = [
                'merchant_order_no' => $merchantOrderNo,
                'exists'            => (bool)$exists,
            ];
            if ($status !== '') {
                $data['status'] = $status;
            }
            return [
                'code'    => '400',
                'message' => (string)$message,
                'data'    => $data,
            ];
        };

        if ($merchantNo === '' || $merchantOrderNo === '') {
            return $fail('merchant_no or merchant_order_no missing');
        }

        try {
            $channel = null;
            if ((int)$channelId > 0) {
                $channel = self::loadChannel((int)$channelId, 'withdraw');
            } else {
                $channel = self::findWithdrawChannelByMerchantNo($merchantNo);
            }
            if (!$channel) {
                return $fail('channel not found');
            }
            $cfg = self::config($channel);
            self::assertConfig($cfg, 'notify');
            self::assertCallbackIp($clientIp, $cfg);

            if ($cfg['merchant_no'] !== '' && $merchantNo !== $cfg['merchant_no']) {
                return $fail('merchant mismatch');
            }
            if (!self::verify($params, $cfg['platform_public_key'])) {
                return $fail('sign error');
            }

            $order = Db::name('fans_withdraw_order')->where('order_no', $merchantOrderNo)->find();
            if (!$order) {
                return [
                    'code'    => '200',
                    'message' => 'SUCCESS',
                    'data'    => [
                        'merchant_order_no' => $merchantOrderNo,
                        'exists'            => false,
                        'status'            => 'not_found',
                    ],
                ];
            }
            if ((int)$order['channel_id'] !== (int)$channel['id']) {
                return $fail('channel mismatch');
            }

            $orderAmount = number_format((float)$order['amount'], 2, '.', '');
            if ($amount !== '0.00' && $amount !== $orderAmount) {
                return [
                    'code'    => '200',
                    'message' => 'SUCCESS',
                    'data'    => [
                        'merchant_order_no' => $merchantOrderNo,
                        'exists'            => false,
                        'status'            => 'amount_mismatch',
                    ],
                ];
            }

            $localAddress = self::extractWithdrawAddress($order);
            if ($address !== '' && $localAddress !== '' && strcasecmp($address, $localAddress) !== 0) {
                return [
                    'code'    => '200',
                    'message' => 'SUCCESS',
                    'data'    => [
                        'merchant_order_no' => $merchantOrderNo,
                        'exists'            => false,
                        'status'            => 'address_mismatch',
                    ],
                ];
            }

            $localStatus = (string)$order['status'];
            // 仅等待代付视为反查通过（文档示例 status=pending）
            if (in_array($localStatus, ['pending', 'processing'], true)) {
                return [
                    'code'    => '200',
                    'message' => 'SUCCESS',
                    'data'    => [
                        'merchant_order_no' => $merchantOrderNo,
                        'exists'            => true,
                        'status'            => 'pending',
                    ],
                ];
            }

            // 已终态：存在但非等待代付，平台应拒绝继续代付
            $mapped = 'failed';
            if ($localStatus === 'paid') {
                $mapped = 'paid';
            } elseif (in_array($localStatus, ['rejected', 'cancelled'], true)) {
                $mapped = 'failed';
            }
            return [
                'code'    => '200',
                'message' => 'SUCCESS',
                'data'    => [
                    'merchant_order_no' => $merchantOrderNo,
                    'exists'            => true,
                    'status'            => $mapped,
                ],
            ];
        } catch (\Throwable $e) {
            return $fail($e->getMessage());
        }
    }

    /**
     * 按商户号查找万汇通代付通道（反查 URL 可不带 channel_id）
     */
    public static function findWithdrawChannelByMerchantNo($merchantNo)
    {
        $merchantNo = trim((string)$merchantNo);
        if ($merchantNo === '') {
            return null;
        }
        $rows = Db::name('fans_pay_channel')
            ->where('handler', 'wanhuitong')
            ->where('type', 'withdraw')
            ->select();
        if (!$rows) {
            return null;
        }
        foreach ($rows as $row) {
            $rowMerchant = trim((string)($row['merchant_no'] ?? ''));
            if ($rowMerchant === $merchantNo) {
                return $row;
            }
            $cfg = FansHubWallet::decodeConfigPublic($row['config'] ?? '');
            if (trim((string)($cfg['merchant_no'] ?? '')) === $merchantNo) {
                return $row;
            }
        }
        return null;
    }

    /**
     * 从提现单 account_info 还原钱包地址/收款账号
     */
    protected static function extractWithdrawAddress(array $order)
    {
        $info = [];
        if (!empty($order['account_info'])) {
            if (is_array($order['account_info'])) {
                $info = $order['account_info'];
            } else {
                $decoded = json_decode((string)$order['account_info'], true);
                if (is_array($decoded)) {
                    $info = $decoded;
                }
            }
        }
        return trim((string)(
            $info['account_or_address']
            ?? $info['cardnumber']
            ?? $info['account']
            ?? $info['card']
            ?? $info['bank_account_no']
            ?? ''
        ));
    }

    /**
     * 默认反查地址（填到 wanhuipay 商户后台）
     */
    public static function defaultWithdrawVerifyUrl($channelId = 0)
    {
        $origin = FansHubPayGateway::siteOrigin();
        $path = '/api/pay/withdrawverify';
        if ((int)$channelId > 0) {
            $path .= '?channel_id=' . (int)$channelId;
        }
        if ($origin === '') {
            return $path;
        }
        return rtrim($origin, '/') . $path;
    }

    protected static function isBizSuccess(array $json)
    {
        $code = $json['code'] ?? $json['status'] ?? $json['ret'] ?? null;
        // 官方支付下单：code=200 成功
        if ((int)$code === 200) {
            return true;
        }
        $codeStr = strtolower((string)$code);
        return in_array($codeStr, ['0', '1', 'success', 'ok', 'true'], true)
            || $code === 0
            || $code === 1
            || $code === true;
    }

    protected static function pickPayUrl(array $json)
    {
        $data = isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;
        foreach (['pay_url', 'payUrl', 'payment_url', 'url', 'cashier_url', 'h5_url'] as $k) {
            $v = trim((string)($data[$k] ?? $json[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    protected static function resolveRealName($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return '';
        }
        try {
            $row = Db::name('user')->where('id', $userId)->field('nickname,username')->find();
            if (!$row) {
                return '';
            }
            $name = trim((string)($row['nickname'] ?? ''));
            if ($name === '') {
                $name = trim((string)($row['username'] ?? ''));
            }
            return $name;
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected static function isNotifyPaid(array $params)
    {
        // 官方：status 数字 1=成功；兼容字符串
        if (isset($params['status']) && (int)$params['status'] === 1) {
            return true;
        }
        $status = strtolower(trim((string)(
            $params['status']
            ?? $params['trade_status']
            ?? $params['pay_status']
            ?? ''
        )));
        return in_array($status, ['success', 'paid', '1', '00', 'ok', 'completed'], true);
    }

    protected static function isNotifyFailed(array $params)
    {
        if (isset($params['status']) && (int)$params['status'] === 2) {
            return true;
        }
        $status = strtolower(trim((string)(
            $params['status']
            ?? $params['trade_status']
            ?? $params['pay_status']
            ?? ''
        )));
        return in_array($status, ['failed', 'fail', 'error', 'rejected', '2', 'closed'], true);
    }

    protected static function normalizeNotifyParams(array $params)
    {
        // JSON body 已合并进 params；若还有 data 包一层则摊平业务字段
        if (isset($params['data']) && is_array($params['data'])) {
            $params = array_merge($params['data'], $params);
            unset($params['data']);
        }
        return $params;
    }

    protected static function loadChannel($channelId, $type)
    {
        $row = Db::name('fans_pay_channel')->where('id', (int)$channelId)->find();
        if (!$row) {
            throw new \RuntimeException('channel not found');
        }
        if (($row['handler'] ?? '') !== 'wanhuitong') {
            throw new \RuntimeException('handler mismatch');
        }
        if ($type && ($row['type'] ?? '') !== $type) {
            // 允许同通道复用时不强制；仍记录
        }
        return $row;
    }

    protected static function absoluteUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
            return $url;
        }
        $origin = FansHubPayGateway::siteOrigin();
        if ($origin === '') {
            try {
                $base = trim((string)(FansHubService::config('invite_base_url') ?? ''));
                if ($base !== '') {
                    $origin = rtrim($base, '/');
                }
            } catch (\Throwable $e) {
            }
        }
        if ($origin === '') {
            return $url;
        }
        return rtrim($origin, '/') . '/' . ltrim($url, '/');
    }

    protected static function clipOrderNo($orderNo)
    {
        $orderNo = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$orderNo);
        if (strlen($orderNo) > 64) {
            $orderNo = substr($orderNo, 0, 64);
        }
        return $orderNo;
    }

    protected static function decodeJson($raw)
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $raw = trim($raw);
        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    protected static function httpPostJson($url, array $params, array $logMeta = [])
    {
        return FansHubPayCurlLog::postJson($url, $params, $logMeta, ['error_prefix' => '万汇通请求失败']);
    }

    protected static function httpPostForm($url, array $params, array $logMeta = [])
    {
        return FansHubPayCurlLog::postForm($url, $params, $logMeta, ['error_prefix' => '万汇通请求失败']);
    }
}
