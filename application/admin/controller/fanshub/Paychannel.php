<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubBsGateway;
use app\common\library\FansHubPayGateway;
use app\common\library\FansHubWanhuitongGateway;
use think\Db;

/**
 * 充值/提现通道（可被 Rechargechannel / Withdrawchannel 继承并固定类型）
 *
 * @icon fa fa-credit-card
 */
class Paychannel extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,name,handler,merchant_no,pay_channel';
    /** @var string 固定通道类型 recharge|withdraw，空则不限制（兼容旧菜单） */
    protected $fixedType = '';
    /** @var array */
    protected $partitionList = [];
    /** @var array */
    protected $merchantList = [];
    /** @var array */
    protected $bsMerchantList = [];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Paychannel;
        $this->view->assign('typeList', $this->model->getTypeList());
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('handlerList', $this->model->getHandlerList());
        $this->view->assign('fixedType', $this->fixedType);
        $this->merchantList = Db::name('fans_pay_merchant')
            ->where('gateway', 'wanhuitong')
            ->where('status', 'normal')
            ->order('id', 'desc')
            ->field('id,name,merchant_no')
            ->select() ?: [];
        $this->bsMerchantList = Db::name('fans_pay_merchant')
            ->where('gateway', 'bs')
            ->where('status', 'normal')
            ->order('id', 'desc')
            ->field('id,name,merchant_no')
            ->select() ?: [];
        $this->view->assign('merchantList', $this->merchantList);
        $this->view->assign('bsMerchantList', $this->bsMerchantList);
        $this->view->assign('walletList', FansHubWanhuitongGateway::paymentChannels());
        $this->view->assign('quickWalletList', FansHubWanhuitongGateway::quickPaymentChannels());
        $this->view->assign('bsCoinList', FansHubBsGateway::coinTypes());
        $partType = $this->fixedType === 'withdraw' ? 'withdraw' : ($this->fixedType === 'recharge' ? 'recharge' : '');
        $partQuery = Db::name('fans_pay_partition')->where('status', 'normal')->order('weigh desc,id asc');
        if ($partType !== '') {
            $partQuery->where('type', $partType);
        }
        $partitions = [];
        try {
            $partitions = $partQuery->select();
        } catch (\Throwable $e) {
            $partitions = [];
        }
        $this->partitionList = [0 => '未分区'];
        foreach ($partitions ?: [] as $p) {
            $label = (string)$p['name'] . ' (' . $p['code'] . ')';
            if ($partType === '') {
                $label = ($p['type'] === 'withdraw' ? '[提现]' : '[充值]') . $label;
            }
            $this->partitionList[(int)$p['id']] = $label;
        }
        $this->view->assign('partitionList', $this->partitionList);
        $this->assignconfig('partitionList', $this->partitionList);
        $this->assignconfig('walletList', FansHubWanhuitongGateway::allPaymentChannels());
        $this->assignconfig('bsCoinList', FansHubBsGateway::coinTypes());
        $this->assignconfig('merchantMap', $this->buildMerchantMap($this->merchantList));
        $this->assignconfig('bsMerchantMap', $this->buildMerchantMap($this->bsMerchantList));
        $this->assignconfig('fixedType', $this->fixedType);
        $authPrefix = 'fanshub/paychannel';
        if ($this->fixedType === 'recharge') {
            $authPrefix = 'fanshub/rechargechannel';
        } elseif ($this->fixedType === 'withdraw') {
            $authPrefix = 'fanshub/withdrawchannel';
        }
        $this->view->assign('authPrefix', $authPrefix);
    }

    protected function buildMerchantMap($list)
    {
        $map = [];
        foreach ($list as $m) {
            $map[(string)$m['id']] = [
                'merchant_no' => (string)$m['merchant_no'],
                'name'        => (string)$m['name'],
            ];
        }
        return $map;
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $query = $this->model->where($where);
            if ($this->fixedType === 'recharge' || $this->fixedType === 'withdraw') {
                $query = $query->where('type', $this->fixedType);
            }
            $list = $query
                ->order($sort, $order)
                ->paginate($limit);
            $rows = $list->items();
            $mids = [];
            foreach ($rows as $r) {
                if ((int)($r['merchant_id'] ?? 0) > 0) {
                    $mids[] = (int)$r['merchant_id'];
                }
            }
            $mnames = [];
            if ($mids) {
                $mrows = Db::name('fans_pay_merchant')->where('id', 'in', array_unique($mids))->column('name', 'id');
                $mnames = $mrows ?: [];
            }
            foreach ($rows as &$row) {
                $mid = (int)($row['merchant_id'] ?? 0);
                $row['merchant_name'] = $mid > 0 ? (string)($mnames[$mid] ?? '') : '';
                $row['wallet_label'] = ($row['handler'] ?? '') === 'wanhuitong'
                    ? FansHubWanhuitongGateway::walletLabel((string)($row['pay_channel'] ?? ''))
                    : '';
                $pid = (int)($row['partition_id'] ?? 0);
                $row['partition_name'] = $pid > 0 ? (string)($this->partitionList[$pid] ?? ('#' . $pid)) : '未分区';
            }
            unset($row);
            return json(['total' => $list->total(), 'rows' => $rows]);
        }
        // 充值/提现子控制器走各自 view（include paychannel 模板）
        return $this->view->fetch();
    }

    public function add()
    {
        if ($this->request->isPost()) {
            try {
                $params = $this->normalizeParams($this->request->post('row/a'));
                $this->request->post(['row' => $params]);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }
            return parent::add();
        }
        // 与 merchant_fields 共用模板，避免未定义 $row
        $this->view->assign('row', [
            'merchant_id'             => 0,
            'pay_channel'             => '',
            'recharge_mode'           => 'cashier',
            'withdraw_type'           => '2',
            'callback_exchange_rate'  => '',
            'verify_url'              => '',
            'submit_url'              => '',
            'merchant_no'             => '',
            'merchant_key'            => '',
            'pay_type'                => '',
            'notify_url'              => '',
            'return_url'              => '',
            'product_name'            => '',
        ]);
        return $this->view->fetch();
    }

    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            try {
                $params = $this->normalizeParams($this->request->post('row/a'), (int)$ids);
                $this->request->post(['row' => $params]);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }
            return parent::edit($ids);
        }
        $row = $this->model->get($ids);
        if ($row && ($this->fixedType === 'recharge' || $this->fixedType === 'withdraw')) {
            if ((string)$row['type'] !== $this->fixedType) {
                $this->error('通道类型不匹配');
            }
        }
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $row = $this->hydrateRow($row);
        if ($row['config']) {
            $cfg = json_decode($row['config'], true);
            if (is_array($cfg)) {
                $row['config'] = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
        }
        // 编辑时若当前总商户不在下拉列表（已隐藏等），补进选项以免丢失
        $this->ensureMerchantInSelectLists((int)($row['merchant_id'] ?? 0), (string)($row['handler'] ?? ''));
        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    /**
     * 保证编辑页总商户下拉能选中当前 merchant_id
     */
    protected function ensureMerchantInSelectLists($merchantId, $handler)
    {
        if ($merchantId <= 0) {
            return;
        }
        $m = Db::name('fans_pay_merchant')->where('id', $merchantId)->field('id,name,merchant_no,gateway,status')->find();
        if (!$m) {
            return;
        }
        $item = ['id' => (int)$m['id'], 'name' => (string)$m['name'], 'merchant_no' => (string)$m['merchant_no']];
        $isBs = $handler === 'bs' || (string)$m['gateway'] === 'bs';
        if ($isBs) {
            foreach ($this->bsMerchantList as $row) {
                if ((int)$row['id'] === $merchantId) {
                    return;
                }
            }
            array_unshift($this->bsMerchantList, $item);
            $this->view->assign('bsMerchantList', $this->bsMerchantList);
            $this->assignconfig('bsMerchantMap', $this->buildMerchantMap($this->bsMerchantList));
            return;
        }
        foreach ($this->merchantList as $row) {
            if ((int)$row['id'] === $merchantId) {
                return;
            }
        }
        array_unshift($this->merchantList, $item);
        $this->view->assign('merchantList', $this->merchantList);
        $this->assignconfig('merchantMap', $this->buildMerchantMap($this->merchantList));
    }

    /**
     * 查询 wanhuipay 商户实时余额
     */
    public function balance($ids = null)
    {
        $id = (int)($ids ?: $this->request->param('ids'));
        if ($id <= 0) {
            $this->error('请选择通道');
        }
        try {
            $row = Db::name('fans_pay_channel')->where('id', $id)->find();
            if (!$row) {
                $this->error('通道不存在');
            }
            $handler = (string)($row['handler'] ?? '');
            if ($handler === 'wanhuitong') {
                $json = FansHubWanhuitongGateway::queryBalanceByChannelId($id);
                $data = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : [];
                $msg = sprintf(
                    '可用 %s / 冻结 %s / 合计 %s / USDT %s',
                    (string)($data['balance'] ?? '-'),
                    (string)($data['frozen_balance'] ?? '-'),
                    (string)($data['total_balance'] ?? '-'),
                    (string)($data['usdt_balance'] ?? '-')
                );
                $this->success($msg, null, $data);
            }
            if ($handler === 'bs') {
                $json = FansHubBsGateway::queryBalanceByChannelId($id, 'USDT');
                $msg = sprintf(
                    '可用 %s / 冻结 %s / 待结算 %s (USDT)',
                    (string)($json['availableAmount'] ?? '-'),
                    (string)($json['frozenAmount'] ?? '-'),
                    (string)($json['unsettledAmount'] ?? '-')
                );
                $this->success($msg, null, $json);
            }
            $this->error('该处理器暂不支持余额查询');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    protected function normalizeParams(array $params, $channelId = 0)
    {
        if (!empty($params['config']) && is_string($params['config'])) {
            $decoded = json_decode($params['config'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $params['config'] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
        }
        $type = ($params['type'] ?? 'recharge') === 'withdraw' ? 'withdraw' : 'recharge';
        if ($this->fixedType === 'recharge' || $this->fixedType === 'withdraw') {
            $type = $this->fixedType;
            $params['type'] = $type;
        }
        $params['partition_id'] = max(0, (int)($params['partition_id'] ?? 0));
        $handler = (string)($params['handler'] ?? '');
        // 非万汇通：文本框可能用 pay_channel_md5
        if ($handler !== 'wanhuitong' && trim((string)($params['pay_channel'] ?? '')) === ''
            && trim((string)($params['pay_channel_md5'] ?? '')) !== '') {
            $params['pay_channel'] = trim((string)$params['pay_channel_md5']);
        }
        unset($params['pay_channel_md5']);
        if (in_array($handler, ['merchant', 'jiuyuan', 'wanhuitong', 'bs'], true)) {
            if ($handler === 'wanhuitong') {
                $rawSubmit = trim((string)($params['submit_url'] ?? ''));
                if ($rawSubmit === '') {
                    $params['submit_url'] = '';
                } else {
                    $params['submit_url'] = $this->normalizeSubmitUrl($rawSubmit, $type);
                }
            } elseif ($handler === 'bs') {
                $rawSubmit = trim((string)($params['submit_url'] ?? ''));
                if ($rawSubmit === '') {
                    $params['submit_url'] = '';
                } else {
                    $params['submit_url'] = $this->normalizeSubmitUrl($rawSubmit, $type);
                }
            } else {
                $params['submit_url'] = $this->normalizeSubmitUrl((string)($params['submit_url'] ?? ''), $type);
            }
            if (trim((string)($params['notify_url'] ?? '')) === '' && $channelId > 0) {
                $params['notify_url'] = FansHubPayGateway::defaultNotifyUrl($channelId, $type);
            }
            if (trim((string)($params['return_url'] ?? '')) === '') {
                $params['return_url'] = FansHubPayGateway::defaultReturnUrl();
            }
            if (trim((string)($params['product_name'] ?? '')) === '') {
                $params['product_name'] = $type === 'withdraw' ? '账户提现' : '账户充值';
            }
            $existingCfg = [];
            if ($channelId > 0) {
                $old = $this->model->where('id', $channelId)->find();
                if ($old && !empty($old['config'])) {
                    $decodedOld = json_decode($old['config'], true);
                    if (is_array($decodedOld)) {
                        $existingCfg = $decodedOld;
                    }
                }
            }
            $cfg = [
                'gateway'      => $handler === 'jiuyuan' ? 'jiuyuan' : ($handler === 'wanhuitong' ? 'wanhuitong' : 'merchant'),
                'submit_url'   => $params['submit_url'] ?? '',
                'merchant_no'  => $params['merchant_no'] ?? '',
                'merchant_key' => $params['merchant_key'] ?? '',
                'pay_type'     => $params['pay_type'] ?? '',
                'pay_channel'  => $params['pay_channel'] ?? '',
                'notify_url'   => $params['notify_url'] ?? '',
                'return_url'   => $params['return_url'] ?? '',
                'product_name' => $params['product_name'] ?? '',
            ];
            if ($handler === 'wanhuitong') {
                $defaults = FansHubWanhuitongGateway::defaultEndpoints();
                $merchantId = (int)($params['merchant_id'] ?? 0);
                $merchant = $merchantId > 0 ? FansHubWanhuitongGateway::loadMerchant($merchantId) : null;
                if (!$merchant) {
                    throw new \RuntimeException('请选择万汇通总商户（先在「支付总商户」录入凭证）');
                }
                $params['merchant_id'] = $merchantId;
                $params['merchant_no'] = (string)$merchant['merchant_no'];
                $cfg['merchant_id'] = $merchantId;
                $cfg['merchant_no'] = $params['merchant_no'];

                if (trim((string)($params['submit_url'] ?? '')) === '') {
                    $params['submit_url'] = $type === 'withdraw'
                        ? $defaults['withdraw_url']
                        : $defaults['submit_url'];
                    $cfg['submit_url'] = $params['submit_url'];
                }
                $payChannel = trim((string)($params['pay_channel'] ?? ''));
                if ($payChannel === '') {
                    $payChannel = (string)($existingCfg['payment_channel'] ?? 'Bobi');
                }
                $all = FansHubWanhuitongGateway::allPaymentChannels();
                if (!isset($all[$payChannel])) {
                    throw new \RuntimeException('无效钱包通道编码: ' . $payChannel);
                }
                $cfg['payment_channel'] = $payChannel;
                $params['pay_channel'] = $payChannel;
                $cfg['pay_channel'] = $payChannel;

                // 自动补名称
                if (trim((string)($params['name'] ?? '')) === '') {
                    $params['name'] = FansHubWanhuitongGateway::walletLabel($payChannel)
                        . ($type === 'withdraw' ? '代付' : '充值');
                }

                $cfg['query_url'] = trim((string)($existingCfg['query_url'] ?? $defaults['query_url']));
                $cfg['withdraw_query_url'] = trim((string)($existingCfg['withdraw_query_url'] ?? $defaults['withdraw_query_url']));
                $cfg['balance_url'] = trim((string)($existingCfg['balance_url'] ?? $defaults['balance_url']));
                $cfg['withdraw_url'] = $defaults['withdraw_url'];
                if (!empty($merchant['callback_ips'])) {
                    $cfg['callback_ips'] = preg_split('/[\s,;]+/', (string)$merchant['callback_ips'], -1, PREG_SPLIT_NO_EMPTY);
                } else {
                    $cfg['callback_ips'] = $defaults['callback_ips'];
                }
                $cfg['notify_ack'] = 'SUCCESS';
                $wt = trim((string)($params['withdraw_type'] ?? ''));
                $cfg['withdraw_type'] = ($wt === '1' || $wt === '2') ? $wt : (string)($existingCfg['withdraw_type'] ?? '2');
                // 密钥只存总商户，通道 config 不再落私钥
                unset($cfg['private_key'], $cfg['platform_public_key'], $cfg['merchant_key']);
                $params['merchant_key'] = '';
            } elseif ($handler === 'bs') {
                $defaults = FansHubBsGateway::defaultEndpoints();
                $merchantId = (int)($params['merchant_id'] ?? 0);
                $merchant = $merchantId > 0 ? FansHubBsGateway::loadMerchant($merchantId) : null;
                if (!$merchant) {
                    throw new \RuntimeException('请选择 BS 总商户（先在「支付总商户」录入凭证）');
                }
                $mCfg = FansHubBsGateway::decodeMerchantConfig($merchant);
                $params['merchant_id'] = $merchantId;
                $params['merchant_no'] = (string)$merchant['merchant_no'];
                $cfg['merchant_id'] = $merchantId;
                $cfg['merchant_no'] = $params['merchant_no'];
                $cfg['gateway'] = 'bs';

                if (trim((string)($params['submit_url'] ?? '')) === '') {
                    $mode = strtolower(trim((string)($params['recharge_mode'] ?? $mCfg['recharge_mode'] ?? 'cashier')));
                    $params['submit_url'] = $mode === 'api'
                        ? $defaults['recharge_api_url']
                        : $defaults['recharge_cashier_url'];
                }
                $coinType = trim((string)($params['pay_channel'] ?? $existingCfg['coin_type'] ?? 'USDT_TRC20'));
                if ($coinType === '') {
                    $coinType = 'USDT_TRC20';
                }
                $allCoins = FansHubBsGateway::coinTypes();
                if (!isset($allCoins[$coinType])) {
                    throw new \RuntimeException('无效 USDT 币种: ' . $coinType);
                }
                $cfg['coin_type'] = $coinType;
                $params['pay_channel'] = $coinType;
                $cfg['pay_channel'] = $coinType;
                $cfg['sign_type'] = $mCfg['sign_type'];
                $cfg['api_version'] = $mCfg['api_version'];
                $cfg['callback_currency_code'] = $mCfg['callback_currency_code'];
                $cfg['currency_code'] = $mCfg['currency_code'];
                $cfg['callback_exchange_rate'] = trim((string)($params['callback_exchange_rate'] ?? $existingCfg['callback_exchange_rate'] ?? ''));
                $mode = strtolower(trim((string)($params['recharge_mode'] ?? $mCfg['recharge_mode'] ?? 'cashier')));
                $cfg['recharge_mode'] = in_array($mode, ['cashier', 'api'], true) ? $mode : 'cashier';
                $cfg['cashier_language'] = $mCfg['cashier_language'];
                if ($type === 'recharge' && $cfg['recharge_mode'] === 'cashier') {
                    $params['submit_url'] = FansHubBsGateway::CASHIER_URL;
                    $cfg['submit_url'] = FansHubBsGateway::CASHIER_URL;
                }
                $cfg['withdraw_url'] = $defaults['withdraw_url'];
                $cfg['recharge_query_url'] = $defaults['recharge_query_url'];
                $cfg['withdraw_query_url'] = $defaults['withdraw_query_url'];
                if (!empty($merchant['callback_ips'])) {
                    $cfg['callback_ips'] = preg_split('/[\s,;]+/', (string)$merchant['callback_ips'], -1, PREG_SPLIT_NO_EMPTY);
                } else {
                    $cfg['callback_ips'] = $defaults['callback_ips'];
                }
                if (trim((string)($params['name'] ?? '')) === '') {
                    $params['name'] = ($allCoins[$coinType] ?? $coinType) . ($type === 'withdraw' ? '代付' : '充值');
                }
                $cfg['submit_url'] = $params['submit_url'];
                unset($cfg['private_key'], $cfg['platform_public_key'], $cfg['merchant_key']);
                $params['merchant_key'] = '';
                unset($params['private_key'], $params['platform_public_key'], $params['sign_type'],
                    $params['api_version'], $params['callback_currency_code'], $params['callback_exchange_rate'],
                    $params['recharge_mode']);
            }
            unset($params['private_key'], $params['platform_public_key'], $params['withdraw_url'], $params['withdraw_type']);
            $params['config'] = json_encode($cfg, JSON_UNESCAPED_UNICODE);
        }
        return $params;
    }

    protected function hydrateRow($row)
    {
        $data = is_array($row) ? $row : $row->toArray();
        $cfg = [];
        if (!empty($data['config'])) {
            $cfg = json_decode($data['config'], true);
            if (!is_array($cfg)) {
                $cfg = [];
            }
        }
        $keys = ['submit_url', 'merchant_no', 'merchant_key', 'pay_type', 'pay_channel', 'notify_url', 'return_url', 'product_name'];
        foreach ($keys as $key) {
            if (empty($data[$key]) && !empty($cfg[$key])) {
                $data[$key] = $cfg[$key];
            }
        }
        if (empty($data['pay_channel']) && !empty($cfg['payment_channel'])) {
            $data['pay_channel'] = $cfg['payment_channel'];
        }
        $data['merchant_id'] = (int)($data['merchant_id'] ?? $cfg['merchant_id'] ?? 0);
        $data['withdraw_type'] = $cfg['withdraw_type'] ?? '2';
        $data['private_key'] = $cfg['private_key'] ?? '';
        $data['platform_public_key'] = $cfg['platform_public_key'] ?? '';
        $data['sign_type'] = $cfg['sign_type'] ?? 'RSA';
        $data['api_version'] = $cfg['api_version'] ?? '2.0.0';
        $data['callback_currency_code'] = $cfg['callback_currency_code'] ?? 'CNY';
        $data['callback_exchange_rate'] = $cfg['callback_exchange_rate'] ?? '';
        $data['recharge_mode'] = $cfg['recharge_mode'] ?? 'cashier';
        $data['verify_url'] = '';
        if (($data['handler'] ?? '') === 'wanhuitong' && ($data['type'] ?? '') === 'withdraw') {
            $data['verify_url'] = FansHubWanhuitongGateway::defaultWithdrawVerifyUrl((int)($data['id'] ?? 0));
        }
        if (($data['handler'] ?? '') === 'bs' && ($data['type'] ?? '') === 'withdraw') {
            $data['verify_url'] = rtrim(FansHubPayGateway::siteOrigin(), '/')
                . '/api/pay/withdrawverify?channel_id=' . (int)($data['id'] ?? 0);
        }
        if (is_object($row)) {
            foreach ($keys as $key) {
                $row->$key = $data[$key] ?? '';
            }
            $row->merchant_id = $data['merchant_id'];
            $row->withdraw_type = $data['withdraw_type'];
            $row->verify_url = $data['verify_url'];
            $row->private_key = $data['private_key'];
            $row->platform_public_key = $data['platform_public_key'];
            $row->sign_type = $data['sign_type'];
            $row->api_version = $data['api_version'];
            $row->callback_currency_code = $data['callback_currency_code'];
            $row->callback_exchange_rate = $data['callback_exchange_rate'];
            $row->recharge_mode = $data['recharge_mode'];
            return $row;
        }
        return $data;
    }

    protected function normalizeSubmitUrl($url, $type)
    {
        $url = trim($url);
        if ($url === '') {
            return FansHubPayGateway::defaultTestSubmitUrl($type);
        }
        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            $origin = FansHubPayGateway::siteOrigin();
            if ($origin !== '') {
                return rtrim($origin, '/') . '/' . ltrim($url, '/');
            }
        }
        return $url;
    }
}
