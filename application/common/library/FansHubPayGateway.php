<?php

namespace app\common\library;

use think\Db;
use think\Request;

/**
 * 充值/提现商户网关（签名、提交、回调）
 */
class FansHubPayGateway
{
    public static function merchantConfig(array $channel)
    {
        $cfg = FansHubWallet::decodeConfigPublic($channel['config'] ?? '');
        return [
            'submit_url'    => trim((string)($channel['submit_url'] ?? $cfg['submit_url'] ?? '')),
            'merchant_no'   => trim((string)($channel['merchant_no'] ?? $cfg['merchant_no'] ?? '')),
            'merchant_key'  => trim((string)($channel['merchant_key'] ?? $cfg['merchant_key'] ?? '')),
            'pay_type'      => trim((string)($channel['pay_type'] ?? $cfg['pay_type'] ?? '')),
            'pay_channel'   => trim((string)($channel['pay_channel'] ?? $cfg['pay_channel'] ?? '')),
            'notify_url'    => trim((string)($channel['notify_url'] ?? $cfg['notify_url'] ?? '')),
            'return_url'    => trim((string)($channel['return_url'] ?? $cfg['return_url'] ?? '')),
            'product_name'  => trim((string)($channel['product_name'] ?? $cfg['product_name'] ?? '账户充值')),
        ];
    }

    public static function defaultNotifyUrl($channelId, $type)
    {
        $type = $type === 'withdraw' ? 'withdraw' : 'recharge';
        $action = $type === 'withdraw' ? 'withdrawnotify' : 'rechargenotify';
        return rtrim(self::siteOrigin(), '/') . '/api/pay/' . $action . '?channel_id=' . (int)$channelId;
    }

    public static function defaultReturnUrl()
    {
        $cfg = FansHubService::config();
        $h5 = trim((string)($cfg['h5_entry_path'] ?? '999'), '/');
        if ($h5 === '') {
            $h5 = '999';
        }
        // uni-999 钱包页；旧 888 仍可用 #profile
        $hash = ($h5 === '888') ? '#profile' : '#/pages/wallet/wallet';
        return rtrim(self::siteOrigin(), '/') . '/' . $h5 . '/' . $hash;
    }

    public static function defaultTestSubmitUrl($type)
    {
        $type = $type === 'withdraw' ? 'withdraw' : 'recharge';
        $action = $type === 'withdraw' ? 'testwithdrawsubmit' : 'testsubmit';
        return rtrim(self::siteOrigin(), '/') . '/api/pay/' . $action;
    }

    public static function siteOrigin()
    {
        try {
            $req = Request::instance();
            if ($req && $req->domain()) {
                return rtrim((string)$req->domain(), '/');
            }
        } catch (\Throwable $e) {
        }
        $cfg = FansHubService::config();
        $base = trim((string)($cfg['invite_base_url'] ?? ''));
        if ($base !== '') {
            return rtrim($base, '/');
        }
        return '';
    }

    public static function sign(array $params, $merchantKey)
    {
        unset($params['sign']);
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $parts[] = $k . '=' . $v;
        }
        $parts[] = 'key=' . $merchantKey;
        return strtoupper(md5(implode('&', $parts)));
    }

    public static function verifySign(array $params, $merchantKey)
    {
        $sign = strtoupper(trim((string)($params['sign'] ?? '')));
        if ($sign === '') {
            return false;
        }
        return hash_equals(self::sign($params, $merchantKey), $sign);
    }

    public static function buildRechargeSubmit(array $channel, $orderNo, $amount, $userId)
    {
        $cfg = self::merchantConfig($channel);
        self::assertMerchantConfig($cfg, 'recharge');
        $params = [
            'merchant_no'  => $cfg['merchant_no'],
            'order_no'     => (string)$orderNo,
            'amount'       => number_format((float)$amount, 2, '.', ''),
            'pay_type'     => $cfg['pay_type'],
            'pay_channel'  => $cfg['pay_channel'],
            'notify_url'   => $cfg['notify_url'],
            'return_url'   => $cfg['return_url'],
            'product_name' => $cfg['product_name'] ?: '账户充值',
            'user_id'      => (string)(int)$userId,
            'timestamp'    => (string)time(),
        ];
        $params['sign'] = self::sign($params, $cfg['merchant_key']);
        $submitUrl = $cfg['submit_url'];
        if (self::isInternalTestSubmit($submitUrl)) {
            return [
                'action' => 'url',
                'method' => 'GET',
                'url'    => self::appendQuery($submitUrl, $params),
                'params' => $params,
            ];
        }
        return [
            'action' => 'form',
            'method' => 'POST',
            'url'    => $submitUrl,
            'params' => $params,
        ];
    }

    public static function buildWithdrawSubmit(array $channel, $orderNo, $amount, $userId, array $accountInfo = [])
    {
        $cfg = self::merchantConfig($channel);
        self::assertMerchantConfig($cfg, 'withdraw');
        $params = [
            'merchant_no'  => $cfg['merchant_no'],
            'order_no'     => (string)$orderNo,
            'amount'       => number_format((float)$amount, 2, '.', ''),
            'pay_type'     => $cfg['pay_type'],
            'pay_channel'  => $cfg['pay_channel'],
            'notify_url'   => $cfg['notify_url'],
            'return_url'   => $cfg['return_url'],
            'product_name' => $cfg['product_name'] ?: '账户提现',
            'user_id'      => (string)(int)$userId,
            'account_info' => json_encode($accountInfo, JSON_UNESCAPED_UNICODE),
            'timestamp'    => (string)time(),
        ];
        $params['sign'] = self::sign($params, $cfg['merchant_key']);
        $submitUrl = $cfg['submit_url'];
        if (self::isInternalTestSubmit($submitUrl)) {
            return [
                'action' => 'url',
                'method' => 'GET',
                'url'    => self::appendQuery($submitUrl, $params),
                'params' => $params,
            ];
        }
        return [
            'action' => 'form',
            'method' => 'POST',
            'url'    => $submitUrl,
            'params' => $params,
        ];
    }

    public static function handleRechargeNotify($channelId, array $params)
    {
        $channel = self::loadChannel((int)$channelId, 'recharge');
        $cfg = self::merchantConfig($channel);
        if (!self::verifySign($params, $cfg['merchant_key'])) {
            throw new \RuntimeException('sign error');
        }
        if ((string)($params['merchant_no'] ?? '') !== $cfg['merchant_no']) {
            throw new \RuntimeException('merchant_no mismatch');
        }
        $orderNo = trim((string)($params['order_no'] ?? ''));
        $status = strtolower(trim((string)($params['status'] ?? '')));
        if ($orderNo === '') {
            throw new \RuntimeException('order_no missing');
        }
        $order = Db::name('fans_recharge_order')->where('order_no', $orderNo)->find();
        if (!$order) {
            throw new \RuntimeException('order not found');
        }
        if ((int)$order['channel_id'] !== (int)$channelId) {
            throw new \RuntimeException('channel mismatch');
        }
        if ($order['status'] === 'paid') {
            return 'SUCCESS';
        }
        $amount = number_format((float)$order['amount'], 2, '.', '');
        $notifyAmount = number_format((float)($params['amount'] ?? 0), 2, '.', '');
        if ($notifyAmount !== $amount) {
            throw new \RuntimeException('amount mismatch');
        }
        if (!in_array($status, ['success', 'paid', '1'], true)) {
            Db::name('fans_recharge_order')->where('id', $order['id'])->update([
                'status'     => 'failed',
                'remark'     => (string)($params['message'] ?? '支付失败'),
                'updatetime' => time(),
            ]);
            return 'SUCCESS';
        }
        $now = time();
        Db::startTrans();
        try {
            $fresh = Db::name('fans_recharge_order')->where('id', $order['id'])->lock(true)->find();
            if (!$fresh || $fresh['status'] === 'paid') {
                Db::commit();
                return 'SUCCESS';
            }
            Db::name('fans_recharge_order')->where('id', $order['id'])->update([
                'status'     => 'paid',
                'remark'     => (string)($params['trade_no'] ?? 'gateway paid'),
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
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return 'SUCCESS';
    }

    public static function handleWithdrawNotify($channelId, array $params)
    {
        $channel = self::loadChannel((int)$channelId, 'withdraw');
        $cfg = self::merchantConfig($channel);
        if (!self::verifySign($params, $cfg['merchant_key'])) {
            throw new \RuntimeException('sign error');
        }
        if ((string)($params['merchant_no'] ?? '') !== $cfg['merchant_no']) {
            throw new \RuntimeException('merchant_no mismatch');
        }
        $orderNo = trim((string)($params['order_no'] ?? ''));
        $status = strtolower(trim((string)($params['status'] ?? '')));
        if ($orderNo === '') {
            throw new \RuntimeException('order_no missing');
        }
        $order = Db::name('fans_withdraw_order')->where('order_no', $orderNo)->find();
        if (!$order) {
            throw new \RuntimeException('order not found');
        }
        if ((int)$order['channel_id'] !== (int)$channelId) {
            throw new \RuntimeException('channel mismatch');
        }
        if (in_array($order['status'], ['paid', 'rejected', 'cancelled'], true)) {
            return 'SUCCESS';
        }
        $amount = number_format((float)$order['amount'], 2, '.', '');
        $notifyAmount = number_format((float)($params['amount'] ?? 0), 2, '.', '');
        if ($notifyAmount !== $amount) {
            throw new \RuntimeException('amount mismatch');
        }
        $now = time();
        if (in_array($status, ['success', 'paid', '1'], true)) {
            Db::name('fans_withdraw_order')->where('id', $order['id'])->update([
                'status'     => 'paid',
                'remark'     => (string)($params['trade_no'] ?? 'gateway paid'),
                'updatetime' => $now,
            ]);
            return 'SUCCESS';
        }
        if (in_array($status, ['fail', 'failed', 'reject', 'rejected', '0'], true)) {
            FansHubWallet::refundWithdrawOrder($order, (string)($params['message'] ?? '提现失败退回'));
            return 'SUCCESS';
        }
        Db::name('fans_withdraw_order')->where('id', $order['id'])->update([
            'status'     => 'processing',
            'remark'     => (string)($params['message'] ?? '处理中'),
            'updatetime' => $now,
        ]);
        return 'SUCCESS';
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

    protected static function assertMerchantConfig(array $cfg, $type)
    {
        $required = ['submit_url', 'merchant_no', 'merchant_key', 'pay_type', 'pay_channel', 'notify_url', 'return_url'];
        foreach ($required as $key) {
            if (trim((string)($cfg[$key] ?? '')) === '') {
                throw new \RuntimeException('通道商户配置不完整：' . $key);
            }
        }
    }

    protected static function isInternalTestSubmit($url)
    {
        return stripos((string)$url, '/api/pay/testsubmit') !== false
            || stripos((string)$url, '/api/pay/testwithdrawsubmit') !== false;
    }

    protected static function appendQuery($url, array $params)
    {
        $q = http_build_query($params);
        return strpos($url, '?') === false ? ($url . '?' . $q) : ($url . '&' . $q);
    }
}
