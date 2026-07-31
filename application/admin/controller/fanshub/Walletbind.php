<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubWallet;
use think\Db;
use think\exception\PDOException;
use think\exception\ValidateException;
use Exception;

/**
 * 用户收款绑定（银行卡 / 支付宝 / 微信 / 钱包地址）
 *
 * @icon fa fa-credit-card
 */
class Walletbind extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,user_id,wallet_type,account_name,account_no,bank_name';
    protected $relationSearch = true;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Walletbind;
        $this->view->assign('bindModeList', $this->model->getBindModeList());
        $this->view->assign('walletTypeList', $this->model->getWalletTypeList());
        $this->assignconfig('bindModeList', $this->model->getBindModeList());
        $this->assignconfig('walletTypeList', $this->model->getWalletTypeList());
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
                ->with(['user'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            foreach ($list as $row) {
                if ($row->getRelation('user')) {
                    $row->getRelation('user')->visible(['id', 'mobile', 'nickname', 'username']);
                }
            }
            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }
        return $this->view->fetch();
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (!is_array($params)) {
                $this->error(__('Parameter %s can not be empty', ''));
            }
            try {
                $this->saveBind(0, $params);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }
            $this->success();
        }
        $this->view->assign('row', [
            'user_id'      => 0,
            'wallet_type'  => 'BANK',
            'bind_mode'    => 'bank',
            'account_name' => '',
            'account_no'   => '',
            'bank_name'    => '',
        ]);
        return $this->view->fetch();
    }

    public function edit($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (!is_array($params)) {
                $this->error(__('Parameter %s can not be empty', ''));
            }
            try {
                $this->saveBind((int)$row['id'], $params);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }
            $this->success();
        }
        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    /**
     * @param int   $id 0=新增
     * @param array $params
     */
    protected function saveBind($id, array $params)
    {
        $userId = (int)($params['user_id'] ?? 0);
        $walletType = strtoupper(trim((string)($params['wallet_type'] ?? '')));
        $bindMode = strtolower(trim((string)($params['bind_mode'] ?? 'wallet')));
        $accountName = trim((string)($params['account_name'] ?? ''));
        $accountNo = FansHubWallet::normalizeAccountNo($params['account_no'] ?? '');
        $bankName = trim((string)($params['bank_name'] ?? ''));

        if ($userId <= 0) {
            throw new \InvalidArgumentException('请填写会员用户ID');
        }
        if ($walletType === '') {
            throw new \InvalidArgumentException('请填写绑定类型 wallet_type');
        }
        if ($accountNo === '') {
            throw new \InvalidArgumentException('请填写账号/地址');
        }
        if (!in_array($bindMode, ['bank', 'alipay', 'wechat', 'wallet', 'conventional'], true)) {
            $bindMode = 'wallet';
        }
        // 常规收款默认 bank_name
        if ($bindMode === 'alipay' && $bankName === '') {
            $bankName = '支付宝';
        }
        if ($bindMode === 'wechat' && $bankName === '') {
            $bankName = '微信';
        }
        if ($bindMode === 'bank' && $walletType === '') {
            $walletType = 'BANK';
        }

        $user = Db::name('user')->where('id', $userId)->find();
        if (!$user) {
            throw new \RuntimeException('用户不存在: ' . $userId);
        }

        $hash = FansHubWallet::accountHash($accountNo);
        $now = time();

        $dup = Db::name('fans_wallet_bind')
            ->where('wallet_type', $walletType)
            ->where('account_hash', $hash)
            ->where('id', '<>', (int)$id)
            ->find();
        if ($dup) {
            throw new \RuntimeException('该账号/地址已被其他记录绑定（用户ID ' . (int)$dup['user_id'] . '）');
        }

        $existType = Db::name('fans_wallet_bind')
            ->where(['user_id' => $userId, 'wallet_type' => $walletType])
            ->where('id', '<>', (int)$id)
            ->find();
        if ($existType) {
            throw new \RuntimeException('该用户已存在同类型绑定（ID ' . (int)$existType['id'] . '），请直接编辑原记录');
        }

        $data = [
            'user_id'      => $userId,
            'wallet_type'  => mb_substr($walletType, 0, 64),
            'bind_mode'    => $bindMode,
            'account_name' => mb_substr($accountName, 0, 64),
            'account_no'   => mb_substr($accountNo, 0, 255),
            'account_hash' => $hash,
            'bank_name'    => mb_substr($bankName, 0, 64),
            'updatetime'   => $now,
        ];

        Db::startTrans();
        try {
            if ($id > 0) {
                Db::name('fans_wallet_bind')->where('id', $id)->update($data);
            } else {
                $data['createtime'] = $now;
                Db::name('fans_wallet_bind')->insert($data);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false) {
                throw new \RuntimeException('账号重复或该用户同类型已绑定');
            }
            throw $e;
        }
    }
}
