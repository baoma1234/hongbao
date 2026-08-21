<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubBsGateway;
use app\common\library\FansHubWanhuitongGateway;
use think\Db;

/**
 * 支付总商户（万汇通 / BS 等网关凭证）
 *
 * @icon fa fa-building
 */
class Paymerchant extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,name,merchant_no';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Paymerchant;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('gatewayList', $this->model->getGatewayList());
        $this->view->assign('walletList', FansHubWanhuitongGateway::paymentChannels());
        $this->view->assign('bsCoinList', FansHubBsGateway::coinTypes());
        $this->assignconfig('walletList', FansHubWanhuitongGateway::paymentChannels());
        $this->assignconfig('bsCoinList', FansHubBsGateway::coinTypes());
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            $rows = $list->items();
            $gatewayList = $this->model->getGatewayList();
            foreach ($rows as &$row) {
                $mid = (int)$row['id'];
                $row['channel_count'] = (int)Db::name('fans_pay_channel')->where('merchant_id', $mid)->count();
                $row['private_key'] = $row['private_key'] !== '' ? '已配置' : '';
                $row['platform_public_key'] = $row['platform_public_key'] !== '' ? '已配置' : '';
                $gw = (string)($row['gateway'] ?? '');
                $row['gateway_label'] = $gatewayList[$gw] ?? $gw;
            }
            unset($row);
            return json(['total' => $list->total(), 'rows' => $rows]);
        }
        return $this->view->fetch();
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->normalizeParams($this->request->post('row/a'));
            $this->request->post(['row' => $params]);
        } else {
            $this->view->assign('row', ['gateway' => 'wanhuitong']);
        }
        return parent::add();
    }

    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            $params = $this->normalizeParams($this->request->post('row/a'), (int)$ids);
            $this->request->post(['row' => $params]);
            return parent::edit($ids);
        }
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $this->view->assign('row', $this->hydrateRow($row));
        return $this->view->fetch();
    }

    public function balance($ids = null)
    {
        $id = (int)($ids ?: $this->request->param('ids'));
        if ($id <= 0) {
            $this->error('请选择商户');
        }
        $merchant = $this->model->get($id);
        if (!$merchant) {
            $this->error('商户不存在');
        }
        try {
            $channel = Db::name('fans_pay_channel')
                ->where('merchant_id', $id)
                ->where('handler', $merchant['gateway'])
                ->order('id', 'asc')
                ->find();
            if (!$channel) {
                $channel = [
                    'handler'     => $merchant['gateway'],
                    'merchant_no' => $merchant['merchant_no'],
                    'merchant_id' => $id,
                    'config'      => json_encode([
                        'merchant_no'         => $merchant['merchant_no'],
                        'private_key'         => $merchant['private_key'],
                        'platform_public_key' => $merchant['platform_public_key'],
                    ], JSON_UNESCAPED_UNICODE),
                ];
            }
            $gateway = (string)$merchant['gateway'];
            if ($gateway === 'bs') {
                $json = FansHubBsGateway::queryBalance($channel, 'USDT');
                $msg = sprintf(
                    '可用 %s / 冻结 %s / 待结算 %s (USDT)',
                    (string)($json['availableAmount'] ?? '-'),
                    (string)($json['frozenAmount'] ?? '-'),
                    (string)($json['unsettledAmount'] ?? '-')
                );
                $this->success($msg, null, $json);
            }
            $json = FansHubWanhuitongGateway::queryBalance($channel);
            $data = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : [];
            $msg = sprintf(
                '可用 %s / 冻结 %s / 合计 %s / USDT %s',
                (string)($data['balance'] ?? '-'),
                (string)($data['frozen_balance'] ?? '-'),
                (string)($data['total_balance'] ?? '-'),
                (string)($data['usdt_balance'] ?? '-')
            );
            $this->success($msg, null, $data);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 同步 BS 通道汇率（代收→充值 / 代付→提现）
     */
    public function syncrates($ids = null)
    {
        $id = (int)($ids ?: $this->request->param('ids'));
        $merchant = $this->model->get($id);
        if (!$merchant) {
            $this->error(__('No Results were found'));
        }
        if ((string)$merchant['gateway'] !== 'bs') {
            $this->error('仅支持 BS 必胜 USDT 商户');
        }
        try {
            $ret = FansHubBsGateway::syncMainMerchantRates($id);
            $parts = [];
            foreach ((array)$ret['coins'] as $coin => $rates) {
                $parts[] = sprintf(
                    '%s 代收%s / 代付%s',
                    $coin,
                    (string)($rates['collectionExchangeRate'] ?? '-'),
                    (string)($rates['paymentExchangeRate'] ?? '-')
                );
            }
            $msg = '已更新 ' . (int)$ret['updated'] . ' 个通道：' . implode('；', $parts);
            $this->success($msg, null, $ret);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    public function batchchannels($ids = null)
    {
        $id = (int)($ids ?: $this->request->param('ids'));
        $merchant = $this->model->get($id);
        if (!$merchant) {
            $this->error('商户不存在');
        }
        $merchantArr = $merchant->toArray();
        $gateway = (string)$merchantArr['gateway'];

        if ($this->request->isPost()) {
            $makeRecharge = (int)$this->request->post('make_recharge', 1) === 1;
            $makeWithdraw = (int)$this->request->post('make_withdraw', 1) === 1;
            $status = $this->request->post('status', 'hidden') === 'normal' ? 'normal' : 'hidden';
            $created = 0;
            $skipped = 0;
            Db::startTrans();
            try {
                if ($gateway === 'bs') {
                    $coins = $this->request->post('coins/a');
                    if (!is_array($coins) || !$coins) {
                        $this->error('请至少选择一个 USDT 币种');
                    }
                    $map = FansHubBsGateway::coinTypes();
                    foreach ($coins as $code) {
                        $code = trim((string)$code);
                        if ($code === '' || !isset($map[$code])) {
                            continue;
                        }
                        $label = $map[$code];
                        if ($makeRecharge) {
                            $r = FansHubBsGateway::ensureCoinChannel($merchantArr, $code, 'recharge', [
                                'status' => $status,
                                'name'   => $label . '充值',
                            ]);
                            $r['created'] ? $created++ : $skipped++;
                        }
                        if ($makeWithdraw) {
                            $r = FansHubBsGateway::ensureCoinChannel($merchantArr, $code, 'withdraw', [
                                'status' => $status,
                                'name'   => $label . '代付',
                            ]);
                            $r['created'] ? $created++ : $skipped++;
                        }
                    }
                } else {
                    $wallets = $this->request->post('wallets/a');
                    if (!is_array($wallets) || !$wallets) {
                        $this->error('请至少选择一个钱包通道');
                    }
                    $map = FansHubWanhuitongGateway::paymentChannels();
                    foreach ($wallets as $code) {
                        $code = trim((string)$code);
                        if ($code === '' || !isset($map[$code])) {
                            continue;
                        }
                        $label = $map[$code];
                        if ($makeRecharge) {
                            $r = FansHubWanhuitongGateway::ensureWalletChannel($merchantArr, $code, 'recharge', [
                                'status' => $status,
                                'name'   => $label . '充值',
                            ]);
                            $r['created'] ? $created++ : $skipped++;
                        }
                        if ($makeWithdraw) {
                            $r = FansHubWanhuitongGateway::ensureWalletChannel($merchantArr, $code, 'withdraw', [
                                'status' => $status,
                                'name'   => $label . '代付',
                            ]);
                            $r['created'] ? $created++ : $skipped++;
                        }
                    }
                }
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
            $this->success("完成：新建 {$created}，已存在跳过 {$skipped}");
        }

        $this->view->assign('row', $merchantArr);
        $this->view->assign('gateway', $gateway);
        $this->view->assign('walletList', FansHubWanhuitongGateway::paymentChannels());
        $this->view->assign('bsCoinList', FansHubBsGateway::coinTypes());
        return $this->view->fetch($gateway === 'bs' ? 'batchchannels_bs' : 'batchchannels');
    }

    protected function hydrateRow($row)
    {
        $data = is_array($row) ? $row : $row->toArray();
        $cfg = [];
        if (!empty($data['config'])) {
            $decoded = json_decode((string)$data['config'], true);
            if (is_array($decoded)) {
                $cfg = $decoded;
            }
        }
        foreach (['sign_type', 'merchant_key', 'api_version', 'callback_currency_code', 'currency_code', 'recharge_mode', 'cashier_language'] as $k) {
            if (!isset($data[$k]) && isset($cfg[$k])) {
                $data[$k] = $cfg[$k];
            }
        }
        return $data;
    }

    protected function normalizeParams(array $params, $id = 0)
    {
        $gateway = trim((string)($params['gateway'] ?? 'wanhuitong'));
        if (!in_array($gateway, ['wanhuitong', 'bs'], true)) {
            $gateway = 'wanhuitong';
        }
        $params['gateway'] = $gateway;
        $params['merchant_no'] = trim((string)($params['merchant_no'] ?? ''));
        $params['name'] = trim((string)($params['name'] ?? ''));
        if ($params['name'] === '') {
            $params['name'] = $params['merchant_no'] !== '' ? $params['merchant_no'] : $gateway;
        }
        $params['site'] = rtrim(trim((string)($params['site'] ?? '')), '/');
        $params['status'] = ($params['status'] ?? 'normal') === 'hidden' ? 'hidden' : 'normal';

        if ($gateway === 'bs') {
            $params['callback_ips'] = trim((string)($params['callback_ips'] ?? '8.217.236.95'));
            if ($params['callback_ips'] === '') {
                $params['callback_ips'] = '8.217.236.95';
            }
            $params['config'] = json_encode([
                'sign_type'              => strtoupper(trim((string)($params['sign_type'] ?? 'RSA'))),
                'merchant_key'           => trim((string)($params['merchant_key'] ?? '')),
                'api_version'            => trim((string)($params['api_version'] ?? '2.0.0')),
                'callback_currency_code'   => trim((string)($params['callback_currency_code'] ?? 'CNY')),
                'currency_code'          => trim((string)($params['callback_currency_code'] ?? 'CNY')),
                'recharge_mode'          => in_array(($params['recharge_mode'] ?? 'cashier'), ['cashier', 'api'], true)
                    ? $params['recharge_mode'] : 'cashier',
                'cashier_language'       => 'zh',
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $params['callback_ips'] = trim((string)($params['callback_ips'] ?? '18.162.71.242,95.40.141.160'));
            $params['config'] = '';
        }

        unset($params['sign_type'], $params['merchant_key'], $params['api_version'],
            $params['callback_currency_code'], $params['currency_code'], $params['recharge_mode']);

        if ($id > 0) {
            $old = $this->model->get($id);
            if ($old) {
                if (trim((string)($params['private_key'] ?? '')) === '') {
                    $params['private_key'] = $old['private_key'];
                }
                if (trim((string)($params['platform_public_key'] ?? '')) === '') {
                    $params['platform_public_key'] = $old['platform_public_key'];
                }
            }
        }
        return $params;
    }
}
