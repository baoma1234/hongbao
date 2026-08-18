<?php

namespace app\common\library;

use think\Db;

/**
 * 久远支付 / 代付网关
 *
 * 充值：POST {base}/Pay_AddOrder（type=json → payUrl）
 * 代付：POST {base}/Payment_Dfpay_add.html
 * 签名：非空参数 ASCII 排序 + key=密钥 → MD5 大写
 * 代付 extends：base64(json_encode(...))
 * 异步应答：纯文本 OK
 */
class FansHubJiuyuanGateway
{
    const SIGN_PAY = 'pay_md5sign';
    const SIGN_NOTIFY_PAY = 'sign';
    const SIGN_PAYOUT = 'pay_md5sign';

    public static function config(array $channel)
    {
        $cfg = FansHubWallet::decodeConfigPublic($channel['config'] ?? '');
        $base = rtrim(trim((string)($channel['submit_url'] ?? $cfg['submit_url'] ?? '')), '/');
        return [
            'base_url'     => $base,
            'merchant_no'  => trim((string)($channel['merchant_no'] ?? $cfg['merchant_no'] ?? '')),
            'merchant_key' => trim((string)($channel['merchant_key'] ?? $cfg['merchant_key'] ?? '')),
            // 充值：pay_bankcode，如 932 支付宝H5
            'pay_bankcode' => trim((string)($channel['pay_type'] ?? $cfg['pay_type'] ?? $cfg['pay_bankcode'] ?? '')),
            // 充值：pay_cid；代付：pay_chanel（101 支付宝 / 102 银行卡）
            'pay_channel'  => trim((string)($channel['pay_channel'] ?? $cfg['pay_channel'] ?? $cfg['pay_cid'] ?? '0')),
            'notify_url'   => trim((string)($channel['notify_url'] ?? $cfg['notify_url'] ?? '')),
            'return_url'   => trim((string)($channel['return_url'] ?? $cfg['return_url'] ?? '')),
            'product_name' => trim((string)($channel['product_name'] ?? $cfg['product_name'] ?? '账户充值')),
        ];
    }

    public static function sign(array $params, $merchantKey, $signField = self::SIGN_PAY, array $onlyKeys = null)
    {
        unset($params[$signField], $params['sign'], $params['pay_md5sign']);
        if (isset($params['extends']) && is_array($params['extends'])) {
            $params['extends'] = self::encodeExtends($params['extends']);
        }
        if (is_array($onlyKeys)) {
            $filtered = [];
            foreach ($onlyKeys as $k) {
                if (array_key_exists($k, $params)) {
                    $filtered[$k] = $params[$k];
                }
            }
            $params = $filtered;
        }
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            if (is_bool($v)) {
                $v = $v ? '1' : '0';
            }
            $parts[] = $k . '=' . $v;
        }
        $parts[] = 'key=' . $merchantKey;
        return strtoupper(md5(implode('&', $parts)));
    }

    public static function verifySign(array $params, $merchantKey, $signField = self::SIGN_NOTIFY_PAY, array $onlyKeys = null)
    {
        $sign = strtoupper(trim((string)($params[$signField] ?? $params['sign'] ?? $params['pay_md5sign'] ?? '')));
        if ($sign === '') {
            return false;
        }
        $calcField = self::SIGN_NOTIFY_PAY;
        if (isset($params['pay_md5sign'])) {
            $calcField = self::SIGN_PAYOUT;
        } elseif (isset($params['sign'])) {
            $calcField = self::SIGN_NOTIFY_PAY;
        }
        return hash_equals(self::sign($params, $merchantKey, $calcField, $onlyKeys), $sign);
    }

    public static function encodeExtends($extends)
    {
        if (is_string($extends)) {
            return $extends;
        }
        if (!is_array($extends) || !$extends) {
            return '';
        }
        return base64_encode(json_encode($extends, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 下单充值，返回跳转 payUrl
     */
    public static function buildRechargeSubmit(array $channel, $orderNo, $amount, $userId)
    {
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'recharge');
        $orderNo = self::clipOrderNo($orderNo);
        $params = [
            'pay_memberid'    => $cfg['merchant_no'],
            'pay_orderid'     => $orderNo,
            'pay_applydate'   => date('Y-m-d H:i:s'),
            'pay_bankcode'    => $cfg['pay_bankcode'],
            'pay_cid'         => $cfg['pay_channel'] !== '' ? $cfg['pay_channel'] : '0',
            'pay_notifyurl'   => $cfg['notify_url'],
            'pay_callbackurl' => $cfg['return_url'] ?: FansHubPayGateway::defaultReturnUrl(),
            'pay_amount'      => number_format((float)$amount, 2, '.', ''),
            'pay_attach'      => (string)(int)$userId,
            'pay_productname' => $cfg['product_name'] ?: '账户充值',
            'type'            => 'json',
        ];
        $params['pay_md5sign'] = self::sign($params, $cfg['merchant_key'], self::SIGN_PAY, [
            'pay_amount', 'pay_applydate', 'pay_bankcode', 'pay_callbackurl',
            'pay_memberid', 'pay_notifyurl', 'pay_orderid',
        ]);

        $url = self::endpoint($cfg['base_url'], 'Pay_AddOrder');
        $raw = self::httpPost($url, $params, FansHubPayCurlLog::logMeta(
            FansHubPayCurlLog::SCENE_RECHARGE,
            'jiuyuan',
            $orderNo,
            'Pay_AddOrder'
        ));
        $json = self::decodeJson($raw);
        if (!$json) {
            throw new \RuntimeException('久远下单返回非 JSON：' . mb_substr((string)$raw, 0, 120));
        }
        $status = (string)($json['status'] ?? $json['ststus'] ?? '');
        if ($status !== '1') {
            throw new \RuntimeException((string)($json['msg'] ?? '久远下单失败'));
        }
        $payUrl = trim((string)($json['payUrl'] ?? $json['payurl'] ?? $json['pay_url'] ?? ''));
        if ($payUrl === '') {
            throw new \RuntimeException('久远未返回支付地址');
        }
        return [
            'action'  => 'url',
            'method'  => 'GET',
            'url'     => $payUrl,
            'params'  => $params,
            'message' => (string)($json['msg'] ?? '请完成支付'),
            'gateway' => 'jiuyuan',
        ];
    }

    /**
     * 提交代付（服务端 POST）
     */
    public static function buildWithdrawSubmit(array $channel, $orderNo, $amount, $userId, array $accountInfo = [], $refundOnFail = false)
    {
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'withdraw');
        $orderNo = self::clipOrderNo($orderNo);

        $accountname = trim((string)($accountInfo['accountname'] ?? $accountInfo['name'] ?? $accountInfo['realname'] ?? ''));
        $cardnumber = trim((string)($accountInfo['cardnumber'] ?? $accountInfo['account'] ?? $accountInfo['card'] ?? ''));
        $bankname = trim((string)($accountInfo['bankname'] ?? $accountInfo['bank'] ?? ''));
        $subbranch = trim((string)($accountInfo['subbranch'] ?? ''));
        $province = trim((string)($accountInfo['province'] ?? ''));
        $city = trim((string)($accountInfo['city'] ?? ''));
        $payChanel = trim((string)($accountInfo['pay_chanel'] ?? $accountInfo['pay_channel'] ?? $cfg['pay_channel'] ?? '102'));
        if ($payChanel === '' || $payChanel === '0') {
            $payChanel = '102';
        }
        if ($bankname === '' && $payChanel === '101') {
            $bankname = '支付宝';
        }
        if ($accountname === '' || $cardnumber === '') {
            throw new \RuntimeException('请填写收款人姓名与账号');
        }
        if ($bankname === '') {
            throw new \RuntimeException('请填写银行名称（支付宝代付可填：支付宝）');
        }

        $params = [
            'mchid'        => $cfg['merchant_no'],
            'out_trade_no' => $orderNo,
            'money'        => number_format((float)$amount, 2, '.', ''),
            'pay_chanel'   => $payChanel,
            'bankname'     => $bankname,
            'subbranch'    => $subbranch,
            'accountname'  => $accountname,
            'cardnumber'   => $cardnumber,
            'province'     => $province,
            'city'         => $city,
            'notifyurl'    => $cfg['notify_url'],
        ];
        if (!empty($accountInfo['extends']) && is_array($accountInfo['extends'])) {
            $params['extends'] = self::encodeExtends($accountInfo['extends']);
        }
        // 代付签名按文档示例字段（非空才参与）
        $params['pay_md5sign'] = self::sign($params, $cfg['merchant_key'], self::SIGN_PAYOUT, [
            'accountname', 'bankname', 'cardnumber', 'city', 'extends',
            'mchid', 'money', 'out_trade_no', 'province', 'subbranch',
        ]);

        $url = self::endpoint($cfg['base_url'], 'Payment_Dfpay_add.html');
        $raw = self::httpPost($url, $params, FansHubPayCurlLog::logMeta(
            FansHubPayCurlLog::SCENE_WITHDRAW,
            'jiuyuan',
            $orderNo,
            'Payment_Dfpay_add'
        ));
        $json = self::decodeJson($raw);
        if (!$json) {
            // 部分网关成功时也可能返回非 JSON，记入 remark 仍算已提交
            Db::name('fans_withdraw_order')->where('order_no', $orderNo)->update([
                'status'     => 'processing',
                'remark'     => 'jiuyuan submit raw: ' . mb_substr((string)$raw, 0, 200),
                'updatetime' => time(),
            ]);
            return [
                'action'  => 'submitted',
                'message' => '代付已提交，等待到账通知',
                'gateway' => 'jiuyuan',
                'raw'     => mb_substr((string)$raw, 0, 200),
            ];
        }
        $status = strtolower((string)($json['status'] ?? ''));
        if ($status !== 'success') {
            $failMsg = (string)($json['msg'] ?? '代付提交失败');
            $order = Db::name('fans_withdraw_order')->where('order_no', $orderNo)->find();
            if ($order) {
                FansHubWallet::markWithdrawPayoutFailed($order, $failMsg, $refundOnFail);
            }
            throw new \RuntimeException($failMsg);
        }
        $tradeNo = (string)($json['transaction_id'] ?? '');
        Db::name('fans_withdraw_order')->where('order_no', $orderNo)->update([
            'status'     => 'processing',
            'remark'     => $tradeNo !== '' ? ('jiuyuan:' . $tradeNo) : 'jiuyuan submitted',
            'updatetime' => time(),
        ]);
        return [
            'action'         => 'submitted',
            'message'        => (string)($json['msg'] ?? '代付已提交'),
            'transaction_id' => $tradeNo,
            'gateway'        => 'jiuyuan',
            'status'         => 'processing',
        ];
    }

    public static function handleRechargeNotify($channelId, array $params)
    {
        $channel = self::loadChannel((int)$channelId, 'recharge');
        $cfg = self::config($channel);
        if (!self::verifySign($params, $cfg['merchant_key'], self::SIGN_NOTIFY_PAY)) {
            throw new \RuntimeException('sign error');
        }
        $memberid = trim((string)($params['memberid'] ?? $params['pay_memberid'] ?? ''));
        if ($memberid !== '' && $memberid !== $cfg['merchant_no']) {
            throw new \RuntimeException('merchant mismatch');
        }
        $orderNo = trim((string)($params['orderid'] ?? $params['pay_orderid'] ?? $params['out_trade_no'] ?? ''));
        if ($orderNo === '') {
            throw new \RuntimeException('orderid missing');
        }
        $returncode = trim((string)($params['returncode'] ?? ''));
        $order = Db::name('fans_recharge_order')->where('order_no', $orderNo)->find();
        if (!$order) {
            throw new \RuntimeException('order not found');
        }
        if ((int)$order['channel_id'] !== (int)$channelId) {
            throw new \RuntimeException('channel mismatch');
        }
        if ($order['status'] === 'paid') {
            return 'OK';
        }
        // 以 true_amount 为准
        $payAmount = isset($params['true_amount']) && $params['true_amount'] !== ''
            ? (float)$params['true_amount']
            : (float)($params['amount'] ?? 0);
        $orderAmount = (float)$order['amount'];
        if (abs($payAmount - $orderAmount) > 0.009) {
            throw new \RuntimeException('amount mismatch');
        }
        if ($returncode !== '00') {
            Db::name('fans_recharge_order')->where('id', $order['id'])->update([
                'status'     => 'failed',
                'remark'     => 'returncode=' . $returncode,
                'updatetime' => time(),
            ]);
            return 'OK';
        }
        $now = time();
        Db::startTrans();
        try {
            $fresh = Db::name('fans_recharge_order')->where('id', $order['id'])->lock(true)->find();
            if (!$fresh || $fresh['status'] === 'paid') {
                Db::commit();
                return 'OK';
            }
            $tradeNo = (string)($params['transaction_id'] ?? '');
            Db::name('fans_recharge_order')->where('id', $order['id'])->update([
                'status'     => 'paid',
                'remark'     => $tradeNo !== '' ? ('jiuyuan:' . $tradeNo) : 'jiuyuan paid',
                'updatetime' => $now,
            ]);
            FansHubWallet::creditBalancePublic(
                (int)$order['user_id'],
                (float)$order['amount'],
                'recharge',
                '充值到账 ' . $orderNo,
                (string)$channel['name']
            );
            Db::commit();
            FansHubImCache::bustWallet((int)$order['user_id']);
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return 'OK';
    }

    public static function handleWithdrawNotify($channelId, array $params)
    {
        $channel = self::loadChannel((int)$channelId, 'withdraw');
        $cfg = self::config($channel);
        if (!self::verifySign($params, $cfg['merchant_key'], self::SIGN_PAYOUT)) {
            throw new \RuntimeException('sign error');
        }
        $mchid = trim((string)($params['mchid'] ?? ''));
        if ($mchid !== '' && $mchid !== $cfg['merchant_no']) {
            throw new \RuntimeException('merchant mismatch');
        }
        $orderNo = trim((string)($params['out_trade_no'] ?? ''));
        if ($orderNo === '') {
            throw new \RuntimeException('out_trade_no missing');
        }
        $order = Db::name('fans_withdraw_order')->where('order_no', $orderNo)->find();
        if (!$order) {
            throw new \RuntimeException('order not found');
        }
        if ((int)$order['channel_id'] !== (int)$channelId) {
            throw new \RuntimeException('channel mismatch');
        }
        if (in_array($order['status'], ['paid', 'rejected', 'cancelled'], true)) {
            return 'OK';
        }
        $notifyAmount = number_format((float)($params['amount'] ?? 0), 2, '.', '');
        $orderAmount = number_format((float)$order['amount'], 2, '.', '');
        if ($notifyAmount !== '0.00' && $notifyAmount !== $orderAmount) {
            throw new \RuntimeException('amount mismatch');
        }
        $status = strtolower(trim((string)($params['status'] ?? '')));
        $refCode = trim((string)($params['refCode'] ?? $params['refcode'] ?? ''));
        $tradeNo = (string)($params['transaction_id'] ?? '');
        $msg = (string)($params['refMsg'] ?? $params['msg'] ?? '');
        $now = time();

        // 文档：status=success 且 refCode=1 才算转账成功
        if ($status === 'success' && $refCode === '1') {
            Db::name('fans_withdraw_order')->where('id', $order['id'])->update([
                'status'     => 'paid',
                'remark'     => $tradeNo !== '' ? ('jiuyuan:' . $tradeNo) : ($msg ?: 'jiuyuan paid'),
                'updatetime' => $now,
            ]);
            return 'OK';
        }
        // 明确失败
        if ($status === 'error' || in_array($refCode, ['2', '5', '7'], true)) {
            FansHubWallet::refundWithdrawOrder($order, $msg !== '' ? $msg : ('代付失败 refCode=' . $refCode));
            return 'OK';
        }
        // 处理中等
        Db::name('fans_withdraw_order')->where('id', $order['id'])->update([
            'status'     => 'processing',
            'remark'     => 'refCode=' . $refCode . ' ' . $msg,
            'updatetime' => $now,
        ]);
        return 'OK';
    }

    protected static function loadChannel($channelId, $type)
    {
        $row = Db::name('fans_pay_channel')
            ->where(['id' => (int)$channelId, 'type' => $type])
            ->find();
        if (!$row) {
            throw new \RuntimeException('channel not found');
        }
        return $row;
    }

    protected static function assertConfig(array $cfg, $type)
    {
        foreach (['base_url', 'merchant_no', 'merchant_key', 'notify_url'] as $key) {
            if (trim((string)($cfg[$key] ?? '')) === '') {
                throw new \RuntimeException('久远通道配置不完整：' . $key);
            }
        }
        if ($type === 'recharge' && trim((string)$cfg['pay_bankcode']) === '') {
            throw new \RuntimeException('久远通道未配置支付编码(pay_type/pay_bankcode)，如 932');
        }
        if ($type === 'recharge' && trim((string)$cfg['return_url']) === '') {
            // 允许用默认
        }
    }

    protected static function endpoint($base, $path)
    {
        $base = rtrim((string)$base, '/');
        $path = ltrim((string)$path, '/');
        // 若已填完整接口地址则直接用
        if (stripos($base, 'Pay_AddOrder') !== false || stripos($base, 'Payment_Dfpay') !== false) {
            return $base;
        }
        return $base . '/' . $path;
    }

    protected static function clipOrderNo($orderNo)
    {
        $orderNo = (string)$orderNo;
        if (strlen($orderNo) > 20) {
            return substr($orderNo, 0, 20);
        }
        return $orderNo;
    }

    protected static function httpPost($url, array $params, array $logMeta = [])
    {
        $started = microtime(true);
        $body = http_build_query($params);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        FansHubPayCurlLog::recordServerRequest($logMeta, 'POST', $url, $params, 'form', $raw, $err, $code, $started);
        if ($raw === false) {
            throw new \RuntimeException('久远请求失败：' . $err);
        }
        if ($code >= 400) {
            throw new \RuntimeException('久远HTTP ' . $code . '：' . mb_substr((string)$raw, 0, 120));
        }
        return (string)$raw;
    }

    protected static function decodeJson($raw)
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
        // 偶发 BOM / 前后杂质
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $j = json_decode($m[0], true);
            return is_array($j) ? $j : null;
        }
        return null;
    }
}
