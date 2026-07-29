<?php

namespace app\common\model\fanshub;

use app\common\library\FansHubPhase2;
use think\Model;

class Secret extends Model
{
    protected $name = 'fans_secret';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function user()
    {
        return $this->belongsTo('app\common\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function getStatusList()
    {
        return [
            'pending'   => '待联系',
            'contacted' => '已联系',
            'completed' => '已完成',
            'expired'   => '已过期',
        ];
    }

    protected static function init()
    {
        self::event('after_update', function ($model) {
            $origin = $model->getOrigin('status');
            $status = (string)$model->getData('status');
            if ($status === 'completed' && $origin !== 'completed') {
                FansHubPhase2::onSecretCompleted((int)$model->user_id, $model);
            }
        });
    }
}
