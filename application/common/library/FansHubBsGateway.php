<?php

namespace app\common\library;

use think\Db;

/**
 * BS / 必胜 USDT 支付（bishengusdt）
 * 文档：https://doc.bishengusdt.com/
 */
class FansHubBsGateway
{
    const GATEWAY_NAME = 'bs';
    const NOTIFY_ACK = 'success';
    const DEFAULT_CALLBACK_IPS = ['8.217.236.95'];
    const CASHIER_URL = 'https://gateway.bishengusdt.com/api/coin/payOrder/createCashier';
    const RECHARGE_QUERY_URL = 'https://gateway.bishengusdt.com/api/coin/payOrder/query';
    const REMIT_CREATE_URL = 'https://gateway.bishengusdt.com/api/coin/remitOrder/create';
    const REMIT_QUERY_URL = 'https://gateway.bishengusdt.com/api/coin/remitOrder/query';
    const BALANCE_QUERY_URL = 'https://gateway.bishengusdt.com/api/coin/balance/query';

    /**
     * 收银台代收默认通道配置（商户凭证稍后填入 runtime/bs.credentials.php）
     */
    public static function defaultChannelPreset($type = 'recharge')
    {
        $defaults = self::defaultEndpoints();
        $preset = [
            'gateway'                => self::GATEWAY_NAME,
            'coin_type'              => 'USDT_TRC20',
            'pay_channel'            => 'USDT_TRC20',
            'sign_type'              => 'RSA',
            'api_version'            => '2.0.0',
            'callback_currency_code' => 'CNY',
            'currency_code'          => 'CNY',
            'recharge_mode'          => 'cashier',
            'cashier_language'       => 'zh',
            'submit_url'             => self::CASHIER_URL,
            'callback_ips'           => $defaults['callback_ips'],
        ];
        if ($type === 'withdraw') {
            $preset['withdraw_url'] = $defaults['withdraw_url'];
        }
        return $preset;
    }

    public static function credentialsFile()
    {
        $base = defined('RUNTIME_PATH') ? RUNTIME_PATH : (dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR);
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'bs.credentials.php';
    }

    public static function loadCredentials()
    {
        $file = self::credentialsFile();
        if (!is_file($file)) {
            return [];
        }
        $data = include $file;
        return is_array($data) ? $data : [];
    }

    public static function loadMerchant($merchantId)
    {
        $merchantId = (int)$merchantId;
        if ($merchantId <= 0) {
            return null;
        }
        $row = Db::name('fans_pay_merchant')->where('id', $merchantId)->find();
        if (!$row || (string)($row['gateway'] ?? '') !== self::GATEWAY_NAME) {
            return null;
        }
        return $row;
    }

    public static function findMerchantByNo($merchantNo)
    {
        $merchantNo = trim((string)$merchantNo);
        if ($merchantNo === '') {
            return null;
        }
        return Db::name('fans_pay_merchant')
            ->where('gateway', self::GATEWAY_NAME)
            ->where('merchant_no', $merchantNo)
            ->find() ?: null;
    }

    public static function decodeMerchantConfig(array $merchant)
    {
        $cfg = [];
        if (!empty($merchant['config'])) {
            $decoded = json_decode((string)$merchant['config'], true);
            if (is_array($decoded)) {
                $cfg = $decoded;
            }
        }
        return array_merge([
            'sign_type'              => 'RSA',
            'merchant_key'           => '',
            'api_version'            => '2.0.0',
            'callback_currency_code' => 'CNY',
            'currency_code'          => 'CNY',
            'recharge_mode'          => 'cashier',
            'cashier_language'       => 'zh',
        ], $cfg);
    }

    /**
     * 为总商户确保某币种的充/提通道存在
     *
     * @return array{created:bool,id:int,channel:array}
     */
    public static function ensureCoinChannel(array $merchant, $coinType, $type, array $opts = [])
    {
        $coinType = trim((string)$coinType);
        $type = $type === 'withdraw' ? 'withdraw' : 'recharge';
        $all = self::coinTypes();
        if (!isset($all[$coinType])) {
            throw new \RuntimeException('无效 USDT 币种: ' . $coinType);
        }
        $merchantId = (int)($merchant['id'] ?? 0);
        $merchantNo = trim((string)($merchant['merchant_no'] ?? ''));
        if ($merchantId <= 0 || $merchantNo === '') {
            throw new \RuntimeException('总商户无效');
        }

        $exist = Db::name('fans_pay_channel')
            ->where('handler', self::GATEWAY_NAME)
            ->where('type', $type)
            ->where('pay_channel', $coinType)
            ->where('merchant_id', $merchantId)
            ->find();
        if ($exist) {
            return ['created' => false, 'id' => (int)$exist['id'], 'channel' => $exist];
        }

        $mCfg = self::decodeMerchantConfig($merchant);
        $defaults = self::defaultEndpoints();
        $status = ($opts['status'] ?? 'hidden') === 'normal' ? 'normal' : 'hidden';
        $label = $all[$coinType];
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

        $rowCfg = array_merge(self::defaultChannelPreset($type), [
            'gateway'                => self::GATEWAY_NAME,
            'merchant_id'            => $merchantId,
            'merchant_no'            => $merchantNo,
            'coin_type'              => $coinType,
            'pay_channel'            => $coinType,
            'sign_type'              => $mCfg['sign_type'],
            'api_version'            => $mCfg['api_version'],
            'callback_currency_code' => $mCfg['callback_currency_code'],
            'currency_code'          => $mCfg['currency_code'],
            'recharge_mode'          => $mCfg['recharge_mode'],
            'cashier_language'       => $mCfg['cashier_language'],
            'callback_ips'           => array_values($ipList),
        ]);

        $now = time();
        $row = [
            'type'         => $type,
            'name'         => $name,
            'icon'         => '',
            'tip'          => 'BS 必胜 USDT',
            'handler'      => self::GATEWAY_NAME,
            'merchant_id'  => $merchantId,
            'submit_url'   => $type === 'recharge' ? self::CASHIER_URL : self::REMIT_CREATE_URL,
            'merchant_no'  => $merchantNo,
            'merchant_key' => '',
            'pay_channel'  => $coinType,
            'notify_url'   => '',
            'return_url'   => $returnUrl,
            'product_name' => $type === 'withdraw' ? '账户提现' : '账户充值',
            'config'       => json_encode($rowCfg, JSON_UNESCAPED_UNICODE),
            'min_amount'   => 10,
            'max_amount'   => 50000,
            'weigh'        => 85,
            'status'       => $status,
            'createtime'   => $now,
            'updatetime'   => $now,
        ];
        $id = (int)Db::name('fans_pay_channel')->insertGetId($row);
        $action = $type === 'withdraw' ? 'withdrawnotify' : 'rechargenotify';
        $notify = $site !== ''
            ? $site . '/api/pay/' . $action . '?channel_id=' . $id
            : FansHubPayGateway::defaultNotifyUrl($id, $type);
        $rowCfg['notify_url'] = $notify;
        Db::name('fans_pay_channel')->where('id', $id)->update([
            'notify_url' => $notify,
            'config'     => json_encode($rowCfg, JSON_UNESCAPED_UNICODE),
            'updatetime' => $now,
        ]);
        $row['id'] = $id;
        $row['notify_url'] = $notify;
        return ['created' => true, 'id' => $id, 'channel' => $row];
    }

    public static function defaultEndpoints()
    {
        $base = 'https://gateway.bishengusdt.com';
        return [
            'recharge_api_url'     => $base . '/api/coin/payOrder/create',
            'recharge_cashier_url' => self::CASHIER_URL,
            'recharge_query_url'   => self::RECHARGE_QUERY_URL,
            'withdraw_url'         => self::REMIT_CREATE_URL,
            'withdraw_query_url'   => self::REMIT_QUERY_URL,
            'balance_query_url'    => self::BALANCE_QUERY_URL,
            'callback_ips'         => self::DEFAULT_CALLBACK_IPS,
        ];
    }

    public static function coinTypes()
    {
        return [
            'USDT_TRC20' => 'USDT (TRC20)',
            'USDT_BEP20' => 'USDT (BEP20)',
            'CNY'        => 'CNY（按汇率转U代收）',
        ];
    }

    public static function config(array $channel)
    {
        $cfg = FansHubWallet::decodeConfigPublic($channel['config'] ?? '');
        $defaults = self::defaultEndpoints();
        $signType = strtoupper(trim((string)($cfg['sign_type'] ?? 'RSA')));
        if (!in_array($signType, ['RSA', 'MD5'], true)) {
            $signType = 'RSA';
        }
        $rechargeMode = strtolower(trim((string)($cfg['recharge_mode'] ?? 'cashier')));
        if (!in_array($rechargeMode, ['cashier', 'api'], true)) {
            $rechargeMode = 'cashier';
        }
        $coinType = trim((string)($cfg['coin_type'] ?? $channel['pay_channel'] ?? 'USDT_TRC20'));
        if ($coinType === '') {
            $coinType = 'USDT_TRC20';
        }
        $callbackCode = trim((string)($cfg['callback_currency_code'] ?? 'CNY'));
        if ($callbackCode === '') {
            $callbackCode = 'CNY';
        }
        $version = trim((string)($cfg['api_version'] ?? '2.0.0'));
        if ($version === '') {
            $version = '2.0.0';
        }
        $submitUrl = trim((string)($channel['submit_url'] ?? $cfg['submit_url'] ?? ''));
        if ($submitUrl === '') {
            $submitUrl = $rechargeMode === 'api'
                ? $defaults['recharge_api_url']
                : $defaults['recharge_cashier_url'];
        }
        $withdrawUrl = trim((string)($cfg['withdraw_url'] ?? $defaults['withdraw_url']));
        if (($channel['type'] ?? '') === 'withdraw') {
            $withdrawUrl = self::REMIT_CREATE_URL;
        }
        $cred = self::loadCredentials();
        $merchantRowId = (int)($channel['merchant_id'] ?? $cfg['merchant_id'] ?? 0);
        $merchant = $merchantRowId > 0 ? self::loadMerchant($merchantRowId) : null;
        if (!$merchant) {
            $mNo = trim((string)($channel['merchant_no'] ?? $cfg['merchant_no'] ?? ''));
            if ($mNo !== '') {
                $merchant = self::findMerchantByNo($mNo);
            }
        }
        $mCfg = $merchant ? self::decodeMerchantConfig($merchant) : [];

        $ips = $cfg['callback_ips'] ?? $defaults['callback_ips'];
        if ($merchant && !empty($merchant['callback_ips'])) {
            $ips = preg_split('/[\s,;]+/', (string)$merchant['callback_ips'], -1, PREG_SPLIT_NO_EMPTY);
        }
        if (is_string($ips)) {
            $ips = preg_split('/[\s,;]+/', $ips, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($ips) || !$ips) {
            $ips = $defaults['callback_ips'];
        }

        $merchantIdStr = trim((string)($channel['merchant_no'] ?? $cfg['merchant_no'] ?? $cred['merchant_id'] ?? $cred['merchant_no'] ?? ''));
        if ($merchant) {
            if ($merchantIdStr === '') {
                $merchantIdStr = trim((string)$merchant['merchant_no']);
            }
        }
        $privateKey = trim((string)($cfg['private_key'] ?? $cred['private_key'] ?? ''));
        $platformKey = trim((string)($cfg['platform_public_key'] ?? $cred['platform_public_key'] ?? ''));
        $merchantKey = trim((string)($channel['merchant_key'] ?? $cfg['merchant_key'] ?? $cred['merchant_key'] ?? ''));
        if ($merchant) {
            if (trim((string)$merchant['private_key']) !== '') {
                $privateKey = trim((string)$merchant['private_key']);
            }
            if (trim((string)$merchant['platform_public_key']) !== '') {
                $platformKey = trim((string)$merchant['platform_public_key']);
            }
            if (!empty($mCfg['merchant_key'])) {
                $merchantKey = trim((string)$mCfg['merchant_key']);
            }
            if (!empty($mCfg['sign_type'])) {
                $signType = strtoupper(trim((string)$mCfg['sign_type']));
            }
        }
        if (!empty($cred['sign_type']) && !$merchant) {
            $signType = strtoupper(trim((string)$cred['sign_type']));
        }
        $currencyCode = trim((string)($cfg['currency_code'] ?? $mCfg['currency_code'] ?? $cred['currency_code'] ?? ''));
        if ($currencyCode === '' && $callbackCode === 'CNY') {
            $currencyCode = 'CNY';
        }
        if ($callbackCode === 'CNY' && !empty($mCfg['callback_currency_code'])) {
            $callbackCode = trim((string)$mCfg['callback_currency_code']);
        }
        if (!empty($mCfg['api_version'])) {
            $version = trim((string)$mCfg['api_version']);
        }
        if (!empty($mCfg['recharge_mode'])) {
            $rechargeMode = strtolower(trim((string)$mCfg['recharge_mode']));
            if (!in_array($rechargeMode, ['cashier', 'api'], true)) {
                $rechargeMode = 'cashier';
            }
        }
        $cashierLanguage = trim((string)($cfg['cashier_language'] ?? $mCfg['cashier_language'] ?? $cred['cashier_language'] ?? 'zh'));
        if ($rechargeMode === 'cashier') {
            $submitUrl = self::CASHIER_URL;
        }
        return [
            'gateway'                  => self::GATEWAY_NAME,
            'merchant_id'              => $merchantIdStr,
            'merchant_no'              => $merchantIdStr,
            'merchant_key'             => $merchantKey,
            'private_key'              => $privateKey,
            'platform_public_key'      => $platformKey,
            'sign_type'                => $signType,
            'api_version'              => $version,
            'coin_type'                => $coinType,
            'callback_currency_code'   => $callbackCode,
            'currency_code'          => $currencyCode,
            'callback_exchange_rate'   => trim((string)($cfg['callback_exchange_rate'] ?? $mCfg['callback_exchange_rate'] ?? $cred['callback_exchange_rate'] ?? '')),
            'recharge_mode'            => $rechargeMode,
            'cashier_language'         => $cashierLanguage,
            'submit_url'               => $submitUrl,
            'withdraw_url'             => $withdrawUrl,
            'notify_url'               => trim((string)($channel['notify_url'] ?? $cfg['notify_url'] ?? '')),
            'return_url'               => trim((string)($channel['return_url'] ?? $cfg['return_url'] ?? '')),
            'callback_ips'             => $ips,
            'recharge_query_url'       => trim((string)($cfg['recharge_query_url'] ?? $defaults['recharge_query_url'])),
            'withdraw_query_url'       => trim((string)($cfg['withdraw_query_url'] ?? $defaults['withdraw_query_url'])),
            'balance_query_url'        => trim((string)($cfg['balance_query_url'] ?? $defaults['balance_query_url'])),
        ];
    }

    public static function buildSignString(array $params)
    {
        unset($params['sign']);
        $out = [];
        foreach ($params as $k => $v) {
            if ($k === 'signType') {
                continue;
            }
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($v)) {
                $v = $v ? '1' : '0';
            } else {
                $v = (string)$v;
            }
            if ($v === '') {
                continue;
            }
            $out[(string)$k] = $v;
        }
        ksort($out);
        $parts = [];
        foreach ($out as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        return implode('&', $parts);
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
        return "-----BEGIN PRIVATE KEY-----\n" . $body . "-----END PRIVATE KEY-----";
    }

    public static function sign(array $params, array $cfg)
    {
        $signType = strtoupper((string)($cfg['sign_type'] ?? 'RSA'));
        if ($signType === 'MD5') {
            return self::signMd5($params, (string)($cfg['merchant_key'] ?? ''));
        }
        return self::signRsa($params, (string)($cfg['private_key'] ?? ''));
    }

    public static function signMd5(array $params, $merchantKey)
    {
        $merchantKey = trim((string)$merchantKey);
        if ($merchantKey === '') {
            throw new \RuntimeException('BS 通道未配置 MD5 商户密钥');
        }
        $signString = self::buildSignString($params);
        return strtolower(md5($signString . '&key=' . $merchantKey));
    }

    public static function signRsa(array $params, $privateKey)
    {
        $signString = self::buildSignString($params);
        $pem = self::normalizePem($privateKey, 'private');
        $res = openssl_pkey_get_private($pem);
        if ($res === false) {
            throw new \RuntimeException('BS 商户 RSA 私钥无效');
        }
        $ok = openssl_sign($signString, $signature, $res, OPENSSL_ALGO_SHA1);
        if (is_resource($res)) {
            @openssl_free_key($res);
        }
        if (!$ok) {
            throw new \RuntimeException('BS RSA 签名失败');
        }
        return base64_encode($signature);
    }

    public static function verify(array $params, array $cfg)
    {
        $sign = trim((string)($params['sign'] ?? ''));
        if ($sign === '') {
            return false;
        }
        $signType = strtoupper(trim((string)($params['signType'] ?? $cfg['sign_type'] ?? 'RSA')));
        if ($signType === 'MD5') {
            $expect = self::signMd5($params, (string)($cfg['merchant_key'] ?? ''));
            return hash_equals($expect, strtolower($sign));
        }
        $pub = (string)($cfg['platform_public_key'] ?? '');
        if ($pub === '') {
            return false;
        }
        $signString = self::buildSignString($params);
        $pem = self::normalizePem($pub, 'public');
        $res = openssl_pkey_get_public($pem);
        if ($res === false) {
            return false;
        }
        $ok = openssl_verify($signString, base64_decode($sign), $res, OPENSSL_ALGO_SHA1);
        if (is_resource($res)) {
            @openssl_free_key($res);
        }
        return $ok === 1;
    }

    public static function buildRechargeSubmit(array $channel, $orderNo, $amount, $userId)
    {
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'recharge');
        $orderNo = self::clipOrderNo($orderNo);
        $notifyUrl = self::absoluteUrl($cfg['notify_url']);
        if ($notifyUrl === '' || strpos($notifyUrl, 'http') !== 0) {
            throw new \RuntimeException('BS notify_url 需为公网绝对地址');
        }

        $isCashier = ($cfg['recharge_mode'] === 'cashier');
        $params = [
            'merchantId'             => $cfg['merchant_id'],
            'version'                => $cfg['api_version'],
            'merchantOrderNo'        => $orderNo,
            'amount'                 => number_format((float)$amount, 2, '.', ''),
            'coinType'               => $cfg['coin_type'],
            'callbackCurrencyCode'   => $cfg['callback_currency_code'],
            'notifyUrl'              => $notifyUrl,
        ];
        if ($cfg['currency_code'] !== '') {
            $params['currencyCode'] = $cfg['currency_code'];
        }
        $memberNo = trim((string)(int)$userId);
        if ($memberNo !== '' && $memberNo !== '0') {
            $params['memberNo'] = $memberNo;
        }
        if ($cfg['callback_exchange_rate'] !== '') {
            $params['callbackExchangeRate'] = $cfg['callback_exchange_rate'];
        }
        if ($isCashier && $cfg['return_url'] !== '') {
            $params['returnUrl'] = self::absoluteUrl($cfg['return_url']);
        }
        if ($isCashier && version_compare($cfg['api_version'], '4.0.0', '>=')) {
            $lang = trim((string)$cfg['cashier_language']);
            if ($lang !== '') {
                $params['language'] = $lang;
            }
        }
        if ($cfg['sign_type'] === 'MD5') {
            $params['signType'] = 'MD5';
        }
        $params['sign'] = self::sign($params, $cfg);

        $url = $isCashier ? self::CASHIER_URL : $cfg['submit_url'];
        $raw = self::httpPostJson($url, $params, FansHubPayCurlLog::logMeta(
            FansHubPayCurlLog::SCENE_RECHARGE,
            'bs',
            $orderNo,
            $isCashier ? 'createCashier' : 'payOrder_create'
        ));
        $json = self::decodeJson($raw);
        if (!$json) {
            throw new \RuntimeException('BS 下单返回非 JSON：' . mb_substr((string)$raw, 0, 160));
        }
        if ((string)($json['code'] ?? '') !== '0') {
            throw new \RuntimeException((string)($json['msg'] ?? 'BS 下单失败') . ' (code=' . ($json['code'] ?? '') . ')');
        }

        if ($cfg['recharge_mode'] === 'cashier') {
            $payUrl = trim((string)($json['payUrl'] ?? ''));
            if ($payUrl === '') {
                throw new \RuntimeException('BS 未返回收银台地址 payUrl');
            }
            return [
                'action'  => 'url',
                'method'  => 'GET',
                'url'     => $payUrl,
                'message' => (string)($json['msg'] ?? '请完成支付'),
                'gateway' => self::GATEWAY_NAME,
                'brand'   => 'BS USDT',
                'raw'     => $json,
            ];
        }

        $platformNo = trim((string)($json['orderNo'] ?? ''));
        $address = trim((string)($json['bookingAddress'] ?? ''));
        if ($address === '') {
            throw new \RuntimeException('BS 未返回收款地址 bookingAddress');
        }
        if ($platformNo !== '') {
            try {
                Db::name('fans_recharge_order')->where('order_no', $orderNo)->update([
                    'remark'     => 'bs:' . $platformNo,
                    'updatetime' => time(),
                ]);
            } catch (\Throwable $e) {
            }
        }
        return [
            'action'            => 'usdt',
            'url'               => '',
            'booking_address'   => $address,
            'pay_coin_amount'   => (string)($json['payCoinAmount'] ?? ''),
            'coin_type'         => (string)($json['coinType'] ?? $cfg['coin_type']),
            'order_expire_date' => (string)($json['orderExpireDate'] ?? ''),
            'exchange_rate'     => (string)($json['exchangeRate'] ?? ''),
            'message'           => '请向以下地址转入 ' . ($json['payCoinAmount'] ?? '') . ' ' . ($json['coinType'] ?? $cfg['coin_type']),
            'gateway'           => self::GATEWAY_NAME,
            'brand'             => 'BS USDT',
            'platform_order_no' => $platformNo,
            'raw'               => $json,
        ];
    }

    public static function buildWithdrawSubmit(array $channel, $orderNo, $amount, $userId, array $accountInfo = [])
    {
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'withdraw');
        $orderNo = self::clipOrderNo($orderNo);
        $notifyUrl = self::absoluteUrl($cfg['notify_url']);
        if ($notifyUrl === '' || strpos($notifyUrl, 'http') !== 0) {
            throw new \RuntimeException('BS 代付 notify_url 需为公网绝对地址');
        }

        $address = trim((string)($accountInfo['account_or_address'] ?? $accountInfo['cardnumber'] ?? $accountInfo['account'] ?? ''));
        if ($address === '') {
            throw new \RuntimeException('请填写 USDT 收款地址');
        }

        // 本地订单 amount 为人民币红宝；提交 BS 时换算为 USDT（如 100 / 6.67 ≈ 14.99）
        $cnyAmount = round((float)$amount, 2);
        $rate = (float)($cfg['callback_exchange_rate'] ?? 0);
        $submitAmount = $cnyAmount;
        if ($rate > 0) {
            $submitAmount = round($cnyAmount / $rate, 4);
        }
        if ($submitAmount <= 0) {
            throw new \RuntimeException('换算后的 USDT 金额无效');
        }

        $params = [
            'merchantId'             => $cfg['merchant_id'],
            'version'                => $cfg['api_version'],
            'merchantOrderNo'        => $orderNo,
            'amount'                 => number_format($submitAmount, 2, '.', ''),
            'coinType'               => $cfg['coin_type'],
            'bookingAddress'         => $address,
            'callbackCurrencyCode'   => $cfg['callback_currency_code'],
            'notifyUrl'              => $notifyUrl,
        ];
        $memberNo = trim((string)(int)$userId);
        if ($memberNo !== '' && $memberNo !== '0') {
            $params['memberNo'] = $memberNo;
        }
        if ($cfg['callback_exchange_rate'] !== '') {
            $params['callbackExchangeRate'] = $cfg['callback_exchange_rate'];
        }
        if ($cfg['sign_type'] === 'MD5') {
            $params['signType'] = 'MD5';
        }
        $params['sign'] = self::sign($params, $cfg);

        $raw = self::httpPostJson(self::REMIT_CREATE_URL, $params, FansHubPayCurlLog::logMeta(
            FansHubPayCurlLog::SCENE_WITHDRAW,
            'bs',
            $orderNo,
            'remitOrder_create'
        ));
        $json = self::decodeJson($raw);
        if (!$json) {
            throw new \RuntimeException('BS 代付返回非 JSON：' . mb_substr((string)$raw, 0, 160));
        }
        if ((string)($json['code'] ?? '') !== '0') {
            throw new \RuntimeException((string)($json['msg'] ?? 'BS 代付失败') . ' (code=' . ($json['code'] ?? '') . ')');
        }
        if (!self::verify($json, $cfg)) {
            throw new \RuntimeException('BS 代付响应验签失败');
        }

        $status = (string)($json['status'] ?? '0');
        if ($status === '2') {
            throw new \RuntimeException((string)($json['msg'] ?? 'BS 代付受理失败'));
        }
        $localStatus = 'processing';
        $remark = 'bs submitted cny=' . number_format($cnyAmount, 2, '.', '')
            . ' usdt=' . number_format($submitAmount, 2, '.', '');
        if ($rate > 0) {
            $remark .= ' rate=' . $rate;
        }
        if (!empty($json['remitCoinAmount'])) {
            $remark .= ' remit=' . $json['remitCoinAmount'];
        }
        if (!empty($json['exchangeRate'])) {
            $remark .= ' gw_rate=' . $json['exchangeRate'];
        }
        Db::name('fans_withdraw_order')->where('order_no', $orderNo)->update([
            'status'     => $localStatus,
            'remark'     => mb_substr($remark, 0, 250),
            'updatetime' => time(),
        ]);

        return [
            'action'          => 'submitted',
            'message'         => (string)($json['msg'] ?? '代付已提交'),
            'gateway'         => self::GATEWAY_NAME,
            'brand'           => 'BS USDT',
            'status'          => $localStatus,
            'exchange_rate'   => (string)($json['exchangeRate'] ?? ''),
            'remit_coin_amount' => (string)($json['remitCoinAmount'] ?? ''),
            'raw'             => $json,
        ];
    }

    public static function handleRechargeNotify($channelId, array $params, $clientIp = '')
    {
        $channel = self::loadChannel((int)$channelId, 'recharge');
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'notify');
        self::assertCallbackIp($clientIp, $cfg);
        if (!self::verify($params, $cfg)) {
            throw new \RuntimeException('sign error');
        }

        $merchantId = trim((string)($params['merchantId'] ?? ''));
        if ($merchantId !== '' && $merchantId !== $cfg['merchant_id']) {
            throw new \RuntimeException('merchant mismatch');
        }
        $orderNo = trim((string)($params['merchantOrderNo'] ?? ''));
        if ($orderNo === '') {
            throw new \RuntimeException('merchantOrderNo missing');
        }

        $order = Db::name('fans_recharge_order')->where('order_no', $orderNo)->find();
        if (!$order) {
            throw new \RuntimeException('order not found');
        }
        if ((int)$order['channel_id'] !== (int)$channelId) {
            throw new \RuntimeException('channel mismatch');
        }
        if ($order['status'] === 'paid') {
            return self::NOTIFY_ACK;
        }

        $status = (string)($params['status'] ?? '');
        if ($status === '0') {
            return self::NOTIFY_ACK;
        }
        if ($status === '2') {
            Db::name('fans_recharge_order')->where('id', $order['id'])->update([
                'status'     => 'failed',
                'remark'     => 'bs failed',
                'updatetime' => time(),
            ]);
            return self::NOTIFY_ACK;
        }
        if ($status !== '1') {
            throw new \RuntimeException('unknown status=' . $status);
        }

        $creditAmount = self::resolveRechargeCreditAmount($params, $order, $cfg);
        if ($creditAmount <= 0) {
            throw new \RuntimeException('callbackOrderAmount missing or invalid');
        }
        $supplementState = (string)($params['supplementOrderState'] ?? '');
        $supplementRemark = trim((string)($params['supplementOrderRemark'] ?? ''));

        $now = time();
        Db::startTrans();
        try {
            $fresh = Db::name('fans_recharge_order')->where('id', $order['id'])->lock(true)->find();
            if (!$fresh || $fresh['status'] === 'paid') {
                Db::commit();
                return self::NOTIFY_ACK;
            }
            Db::name('fans_recharge_order')->where('id', $order['id'])->update([
                'status'     => 'paid',
                'amount'     => round($creditAmount, 2),
                'remark'     => mb_substr(
                    'bs paid callback=' . number_format($creditAmount, 2, '.', '')
                    . ($supplementState !== '' ? ' supplement=' . $supplementState : '')
                    . ($supplementRemark !== '' ? ' ' . $supplementRemark : ''),
                    0,
                    250
                ),
                'updatetime' => $now,
            ]);
            FansHubWallet::creditBalancePublic(
                (int)$order['user_id'],
                $creditAmount,
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
        return self::NOTIFY_ACK;
    }

    public static function handleWithdrawNotify($channelId, array $params, $clientIp = '')
    {
        $channel = self::loadChannel((int)$channelId, 'withdraw');
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'notify');
        self::assertCallbackIp($clientIp, $cfg);
        if (!self::verify($params, $cfg)) {
            throw new \RuntimeException('sign error');
        }

        $merchantId = trim((string)($params['merchantId'] ?? ''));
        if ($merchantId !== '' && $merchantId !== $cfg['merchant_id']) {
            throw new \RuntimeException('merchant mismatch');
        }
        $orderNo = trim((string)($params['merchantOrderNo'] ?? ''));
        if ($orderNo === '') {
            throw new \RuntimeException('merchantOrderNo missing');
        }

        $order = Db::name('fans_withdraw_order')->where('order_no', $orderNo)->find();
        if (!$order) {
            throw new \RuntimeException('order not found');
        }
        if ((int)$order['channel_id'] !== (int)$channelId) {
            throw new \RuntimeException('channel mismatch');
        }
        if (in_array($order['status'], ['paid', 'rejected', 'cancelled'], true)) {
            return self::NOTIFY_ACK;
        }

        $status = (string)($params['status'] ?? '');
        $exchangeRate = trim((string)($params['exchangeRate'] ?? ''));
        $callbackAmount = (float)($params['callbackOrderAmount'] ?? 0);
        if ($callbackAmount <= 0) {
            $callbackAmount = (float)($params['amount'] ?? 0);
        }
        $remitCoinAmount = trim((string)($params['remitCoinAmount'] ?? ''));
        $now = time();
        if ($status === '0') {
            Db::name('fans_withdraw_order')->where('id', $order['id'])->update([
                'status'     => 'processing',
                'remark'     => mb_substr(
                    'bs processing'
                    . ($callbackAmount > 0 ? ' callback=' . number_format($callbackAmount, 2, '.', '') : '')
                    . ($exchangeRate !== '' ? ' rate=' . $exchangeRate : ''),
                    0,
                    250
                ),
                'updatetime' => $now,
            ]);
            return self::NOTIFY_ACK;
        }
        if ($status === '1') {
            Db::name('fans_withdraw_order')->where('id', $order['id'])->update([
                'status'     => 'paid',
                'remark'     => mb_substr(
                    'bs paid'
                    . ($remitCoinAmount !== '' ? ' remit=' . $remitCoinAmount : '')
                    . ($callbackAmount > 0 ? ' callback=' . number_format($callbackAmount, 2, '.', '') : '')
                    . ($exchangeRate !== '' ? ' rate=' . $exchangeRate : ''),
                    0,
                    250
                ),
                'updatetime' => $now,
            ]);
            return self::NOTIFY_ACK;
        }
        if ($status === '2') {
            FansHubWallet::refundWithdrawOrder($order, 'BS 代付失败');
            return self::NOTIFY_ACK;
        }
        throw new \RuntimeException('unknown status=' . $status);
    }

    /**
     * BS 代付反查（商户提供接口，平台 POST 校验订单）
     * 文档：merchantOrderNo / amount / merchantId / coinType / bookingAddress / sign
     * 响应：merchantOrderNo / code(0成功) / message / sign
     */
    public static function handleWithdrawVerify($channelId, array $params, $clientIp = '')
    {
        $channel = self::loadChannel((int)$channelId, 'withdraw');
        $cfg = self::config($channel);
        self::assertCallbackIp($clientIp, $cfg);

        $orderNo = trim((string)($params['merchantOrderNo'] ?? ''));
        if ($orderNo === '') {
            return self::verifyResponse($params, $cfg, 1, 'merchantOrderNo missing');
        }

        if (!self::verify($params, $cfg)) {
            return self::verifyResponse($params, $cfg, 1, 'sign error');
        }

        $merchantId = trim((string)($params['merchantId'] ?? ''));
        if ($merchantId !== '' && $merchantId !== $cfg['merchant_id']) {
            return self::verifyResponse($params, $cfg, 2, 'merchant mismatch');
        }

        $coinType = trim((string)($params['coinType'] ?? ''));
        if ($coinType !== '' && strcasecmp($coinType, $cfg['coin_type']) !== 0) {
            return self::verifyResponse($params, $cfg, 3, 'coinType mismatch');
        }

        $order = Db::name('fans_withdraw_order')->where('order_no', $orderNo)->find();
        if (!$order) {
            return self::verifyResponse($params, $cfg, 4, 'order not found');
        }
        if ((int)$order['channel_id'] !== (int)$channelId) {
            return self::verifyResponse($params, $cfg, 5, 'channel mismatch');
        }

        $amount = number_format((float)($params['amount'] ?? 0), 2, '.', '');
        $orderAmount = number_format((float)$order['amount'], 2, '.', '');
        if ($amount !== '0.00' && $amount !== $orderAmount) {
            return self::verifyResponse($params, $cfg, 6, 'amount mismatch');
        }

        $address = trim((string)($params['bookingAddress'] ?? ''));
        $localAddress = self::extractWithdrawAddress($order);
        if ($address !== '' && $localAddress !== '' && strcasecmp($address, $localAddress) !== 0) {
            return self::verifyResponse($params, $cfg, 7, 'bookingAddress mismatch');
        }

        if (!in_array($order['status'], ['pending', 'processing'], true)) {
            return self::verifyResponse($params, $cfg, 8, 'order status not allowed');
        }

        return self::verifyResponse($params, $cfg, 0, '操作成功');
    }

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
            ?? ''
        ));
    }

    /**
     * USDT 代收订单查询：POST /api/coin/payOrder/query
     */
    public static function queryRechargeOrder(array $channel, $merchantOrderNo, $submitTime = null)
    {
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'recharge');
        $merchantOrderNo = self::clipOrderNo($merchantOrderNo);
        $submitTime = self::formatSubmitTime($submitTime, $merchantOrderNo);

        $params = [
            'merchantId'      => $cfg['merchant_id'],
            'version'         => $cfg['api_version'],
            'merchantOrderNo' => $merchantOrderNo,
            'submitTime'      => $submitTime,
        ];
        if ($cfg['sign_type'] === 'MD5') {
            $params['signType'] = 'MD5';
        }
        $params['sign'] = self::sign($params, $cfg);

        $url = trim((string)$cfg['recharge_query_url']);
        if ($url === '') {
            $url = self::RECHARGE_QUERY_URL;
        }
        $raw = self::httpPostJson($url, $params, FansHubPayCurlLog::logMeta(
            FansHubPayCurlLog::SCENE_RECHARGE,
            'bs',
            $merchantOrderNo,
            'payOrder_query'
        ));
        $json = self::decodeJson($raw);
        if (!$json) {
            throw new \RuntimeException('BS 查询返回非 JSON：' . mb_substr((string)$raw, 0, 160));
        }
        if ((string)($json['code'] ?? '') !== '0') {
            throw new \RuntimeException((string)($json['msg'] ?? 'BS 查询失败') . ' (code=' . ($json['code'] ?? '') . ')');
        }
        if (!self::verify($json, $cfg)) {
            throw new \RuntimeException('BS 查询响应验签失败');
        }
        return $json;
    }

    /**
     * 主动查询 BS 代收并同步本地充值单
     * status：0处理中 1成功 2失败
     *
     * @return string paid|failed|pending|unchanged
     */
    public static function syncRechargeFromQuery($channelId, $merchantOrderNo)
    {
        $channel = self::loadChannel((int)$channelId, 'recharge');
        $merchantOrderNo = self::clipOrderNo($merchantOrderNo);
        $order = Db::name('fans_recharge_order')->where('order_no', $merchantOrderNo)->find();
        if (!$order) {
            throw new \RuntimeException('order not found');
        }
        if ((int)$order['channel_id'] !== (int)$channelId) {
            throw new \RuntimeException('channel mismatch');
        }
        if ($order['status'] === 'paid') {
            return 'unchanged';
        }

        $json = self::queryRechargeOrder($channel, $merchantOrderNo, date('YmdHis', (int)$order['createtime']));
        $status = (string)($json['status'] ?? '');
        if ($status === '0') {
            return 'pending';
        }
        if ($status === '2') {
            Db::name('fans_recharge_order')->where('id', $order['id'])->update([
                'status'     => 'failed',
                'remark'     => 'bs query status=2',
                'updatetime' => time(),
            ]);
            return 'failed';
        }
        if ($status !== '1') {
            throw new \RuntimeException('unknown status=' . $status);
        }

        $cfg = self::config($channel);
        $creditAmount = self::resolveRechargeCreditAmount($json, $order, $cfg);
        if ($creditAmount <= 0) {
            throw new \RuntimeException('BS 查单入账金额无效');
        }

        $now = time();
        Db::startTrans();
        try {
            $fresh = Db::name('fans_recharge_order')->where('id', $order['id'])->lock(true)->find();
            if (!$fresh || $fresh['status'] === 'paid') {
                Db::commit();
                return 'unchanged';
            }
            Db::name('fans_recharge_order')->where('id', $order['id'])->update([
                'status'     => 'paid',
                'amount'     => round($creditAmount, 2),
                'remark'     => 'bs query paid callback=' . number_format($creditAmount, 2, '.', ''),
                'updatetime' => $now,
            ]);
            FansHubWallet::creditBalancePublic(
                (int)$order['user_id'],
                $creditAmount,
                'recharge',
                '充值到账 ' . $merchantOrderNo,
                (string)$channel['name']
            );
            Db::commit();
            FansHubImCache::bustWallet((int)$order['user_id']);
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return 'paid';
    }

    /**
     * 充值入账金额（人民币/红宝）。
     * 用户按 USDT 下单；有汇率时：入账 = USDT × callback_exchange_rate（如 10×6.67=66.7）。
     */
    protected static function resolveRechargeCreditAmount(array $params, array $order, array $cfg = [])
    {
        $rate = (float)($cfg['callback_exchange_rate'] ?? 0);
        $coin = (float)($params['payCoinAmount'] ?? $params['addFundsCoinAmount'] ?? 0);
        if ($coin <= 0) {
            $coin = (float)($params['amount'] ?? 0);
        }
        if ($coin <= 0) {
            $coin = (float)($order['amount'] ?? 0);
        }

        // 优先回调法币金额；若数值几乎等于币量且配置了汇率，视为未换算，改用 币×汇率
        $callbackAmount = (float)($params['callbackOrderAmount'] ?? 0);
        if ($callbackAmount > 0) {
            if ($rate > 0 && $coin > 0 && abs($callbackAmount - $coin) < 0.0001) {
                return round($coin * $rate, 2);
            }
            return round($callbackAmount, 2);
        }
        $currencyAmount = (float)($params['currencyOrderAmount'] ?? 0);
        if ($currencyAmount > 0) {
            if ($rate > 0 && $coin > 0 && abs($currencyAmount - $coin) < 0.0001) {
                return round($coin * $rate, 2);
            }
            return round($currencyAmount, 2);
        }
        if ($coin > 0 && $rate > 0) {
            return round($coin * $rate, 2);
        }
        return $coin > 0 ? round($coin, 2) : 0;
    }

    /**
     * USDT 代付订单查询：POST /api/coin/remitOrder/query
     */
    public static function queryWithdrawOrder(array $channel, $merchantOrderNo, $submitTime = null)
    {
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'withdraw');
        $merchantOrderNo = self::clipOrderNo($merchantOrderNo);
        $submitTime = self::formatSubmitTime($submitTime, $merchantOrderNo);

        $params = [
            'merchantId'      => $cfg['merchant_id'],
            'version'         => $cfg['api_version'],
            'merchantOrderNo' => $merchantOrderNo,
            'submitTime'      => $submitTime,
        ];
        if ($cfg['sign_type'] === 'MD5') {
            $params['signType'] = 'MD5';
        }
        $params['sign'] = self::sign($params, $cfg);

        $url = trim((string)$cfg['withdraw_query_url']);
        if ($url === '') {
            $url = self::REMIT_QUERY_URL;
        }
        $raw = self::httpPostJson($url, $params, FansHubPayCurlLog::logMeta(
            FansHubPayCurlLog::SCENE_WITHDRAW,
            'bs',
            $merchantOrderNo,
            'remitOrder_query'
        ));
        $json = self::decodeJson($raw);
        if (!$json) {
            throw new \RuntimeException('BS 代付查询返回非 JSON：' . mb_substr((string)$raw, 0, 160));
        }
        if ((string)($json['code'] ?? '') !== '0') {
            throw new \RuntimeException((string)($json['msg'] ?? 'BS 代付查询失败') . ' (code=' . ($json['code'] ?? '') . ')');
        }
        if (!self::verify($json, $cfg)) {
            throw new \RuntimeException('BS 代付查询响应验签失败');
        }
        return $json;
    }

    /**
     * 主动查询 BS 代付并同步本地提现单
     * status：0处理中 1成功 2失败
     *
     * @return string paid|failed|pending|unchanged
     */
    public static function syncWithdrawFromQuery($channelId, $merchantOrderNo)
    {
        $channel = self::loadChannel((int)$channelId, 'withdraw');
        $merchantOrderNo = self::clipOrderNo($merchantOrderNo);
        $order = Db::name('fans_withdraw_order')->where('order_no', $merchantOrderNo)->find();
        if (!$order) {
            throw new \RuntimeException('order not found');
        }
        if ((int)$order['channel_id'] !== (int)$channelId) {
            throw new \RuntimeException('channel mismatch');
        }
        if (in_array($order['status'], ['paid', 'rejected', 'cancelled'], true)) {
            return 'unchanged';
        }

        $json = self::queryWithdrawOrder($channel, $merchantOrderNo, date('YmdHis', (int)$order['createtime']));
        $status = (string)($json['status'] ?? '');
        if ($status === '0') {
            if ($order['status'] === 'pending') {
                Db::name('fans_withdraw_order')->where('id', $order['id'])->update([
                    'status'     => 'processing',
                    'updatetime' => time(),
                ]);
            }
            return 'pending';
        }
        if ($status === '2') {
            FansHubWallet::refundWithdrawOrder($order, 'BS 查单代付失败');
            return 'failed';
        }
        if ($status !== '1') {
            throw new \RuntimeException('unknown status=' . $status);
        }

        $remark = 'bs query paid';
        if (!empty($json['remitCoinAmount'])) {
            $remark .= ' remit=' . $json['remitCoinAmount'];
        }
        if (!empty($json['exchangeRate'])) {
            $remark .= ' rate=' . $json['exchangeRate'];
        }
        Db::name('fans_withdraw_order')->where('id', $order['id'])->update([
            'status'     => 'paid',
            'remark'     => mb_substr($remark, 0, 250),
            'updatetime' => time(),
        ]);
        return 'paid';
    }

    /**
     * 商户余额查询：POST /api/coin/balance/query
     */
    public static function queryBalance(array $channel, $coinType = 'USDT', $requestTime = null)
    {
        $cfg = self::config($channel);
        self::assertConfig($cfg, 'query');
        $requestTime = self::formatSubmitTime($requestTime, '');
        $coinType = strtoupper(trim((string)$coinType));
        if ($coinType === '') {
            $coinType = 'USDT';
        }
        $params = [
            'merchantId'  => $cfg['merchant_id'],
            'version'     => $cfg['api_version'],
            'requestTime' => $requestTime,
            'coinType'    => $coinType,
        ];
        if ($cfg['sign_type'] === 'MD5') {
            $params['signType'] = 'MD5';
        }
        $params['sign'] = self::sign($params, $cfg);

        $url = trim((string)$cfg['balance_query_url']);
        if ($url === '') {
            $url = self::BALANCE_QUERY_URL;
        }
        $raw = self::httpPostJson($url, $params, FansHubPayCurlLog::logMeta(
            FansHubPayCurlLog::SCENE_WITHDRAW,
            'bs',
            'balance',
            'balance_query'
        ));
        $json = self::decodeJson($raw);
        if (!$json) {
            throw new \RuntimeException('BS 余额查询返回非 JSON：' . mb_substr((string)$raw, 0, 160));
        }
        if ((string)($json['code'] ?? '') !== '0') {
            throw new \RuntimeException((string)($json['msg'] ?? 'BS 余额查询失败') . ' (code=' . ($json['code'] ?? '') . ')');
        }
        if (!self::verify($json, $cfg)) {
            throw new \RuntimeException('BS 余额查询响应验签失败');
        }
        return $json;
    }

    /**
     * 按通道 ID 查余额
     */
    public static function queryBalanceByChannelId($channelId, $coinType = 'USDT')
    {
        $row = Db::name('fans_pay_channel')->where('id', (int)$channelId)->find();
        if (!$row) {
            throw new \RuntimeException('channel not found');
        }
        if (($row['handler'] ?? '') !== 'bs') {
            throw new \RuntimeException('仅支持 BS 通道');
        }
        return self::queryBalance($row, $coinType);
    }

    protected static function formatSubmitTime($submitTime, $merchantOrderNo)
    {
        $submitTime = trim((string)$submitTime);
        if ($submitTime !== '' && preg_match('/^\d{14}$/', $submitTime)) {
            return $submitTime;
        }
        if ($submitTime !== '') {
            $ts = strtotime($submitTime);
            if ($ts) {
                return date('YmdHis', $ts);
            }
        }
        $order = Db::name('fans_recharge_order')->where('order_no', self::clipOrderNo($merchantOrderNo))->find();
        if ($order && !empty($order['createtime'])) {
            return date('YmdHis', (int)$order['createtime']);
        }
        $order = Db::name('fans_withdraw_order')->where('order_no', self::clipOrderNo($merchantOrderNo))->find();
        if ($order && !empty($order['createtime'])) {
            return date('YmdHis', (int)$order['createtime']);
        }
        return date('YmdHis');
    }

    protected static function verifyResponse(array $req, array $cfg, $code, $message, array $extra = [])
    {
        $orderNo = trim((string)($req['merchantOrderNo'] ?? ''));
        $body = [
            'merchantOrderNo' => $orderNo,
            'code'            => (string)(int)$code,
            'message'         => (string)$message,
        ];
        if ($extra) {
            $body = array_merge($body, $extra);
        }
        $body['sign'] = self::sign($body, $cfg);
        return $body;
    }

    protected static function assertConfig(array $cfg, $scene)
    {
        if (trim((string)$cfg['merchant_id']) === '') {
            throw new \RuntimeException('BS 未配置商户号 merchantId');
        }
        if ($scene === 'notify') {
            return;
        }
        $signType = strtoupper((string)$cfg['sign_type']);
        if ($signType === 'MD5' && trim((string)$cfg['merchant_key']) === '') {
            throw new \RuntimeException('BS MD5 签名需配置商户密钥');
        }
        if ($signType === 'RSA' && trim((string)$cfg['private_key']) === '') {
            throw new \RuntimeException('BS RSA 签名需配置商户私钥');
        }
        if ($scene === 'withdraw' && trim((string)$cfg['withdraw_url']) === '') {
            throw new \RuntimeException('BS 未配置代付地址');
        }
        if ($scene === 'recharge' && trim((string)$cfg['submit_url']) === '') {
            throw new \RuntimeException('BS 未配置代收地址');
        }
    }

    protected static function assertCallbackIp($clientIp, array $cfg)
    {
        $clientIp = trim((string)$clientIp);
        if ($clientIp === '') {
            return;
        }
        $ips = $cfg['callback_ips'] ?? [];
        if (!is_array($ips) || !$ips) {
            return;
        }
        foreach ($ips as $ip) {
            if ($clientIp === trim((string)$ip)) {
                return;
            }
        }
        throw new \RuntimeException('callback ip denied: ' . $clientIp);
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

    protected static function absoluteUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, 'http') === 0) {
            return $url;
        }
        $origin = FansHubPayGateway::siteOrigin();
        if ($origin === '') {
            return $url;
        }
        return rtrim($origin, '/') . '/' . ltrim($url, '/');
    }

    protected static function clipOrderNo($orderNo)
    {
        $orderNo = (string)$orderNo;
        if (strlen($orderNo) > 32) {
            return substr($orderNo, 0, 32);
        }
        return $orderNo;
    }

    protected static function httpPostJson($url, array $params, array $logMeta = [])
    {
        return FansHubPayCurlLog::postJson($url, $params, $logMeta, ['error_prefix' => 'BS 请求失败']);
    }

    protected static function decodeJson($raw)
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $json = json_decode(trim($raw), true);
        return is_array($json) ? $json : null;
    }
}
