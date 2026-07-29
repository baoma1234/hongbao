<?php

namespace app\admin\model\fanshub;

use think\Model;

class Paychannel extends Model
{
    protected $name = 'fans_pay_channel';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    protected static function init()
    {
        self::afterInsert(function ($row) {
            self::ensureNotifyUrl($row);
        });
        self::afterUpdate(function ($row) {
            self::ensureNotifyUrl($row);
        });
    }

    protected static function ensureNotifyUrl($row)
    {
        $data = is_array($row) ? $row : $row->getData();
        if (!in_array(($data['handler'] ?? ''), ['merchant', 'jiuyuan', 'wanhuitong', 'bs'], true)) {
            return;
        }
        if (trim((string)($data['notify_url'] ?? '')) !== '') {
            return;
        }
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $type = ($data['type'] ?? 'recharge') === 'withdraw' ? 'withdraw' : 'recharge';
        $notifyUrl = \app\common\library\FansHubPayGateway::defaultNotifyUrl($id, $type);
        self::where('id', $id)->update(['notify_url' => $notifyUrl]);
    }

    public function getTypeList()
    {
        return ['recharge' => '充值', 'withdraw' => '提现'];
    }

    public function getStatusList()
    {
        return ['normal' => '显示', 'hidden' => '隐藏'];
    }

    public function getHandlerList()
    {
        return \app\common\library\FansHubWallet::handlerList();
    }
}
