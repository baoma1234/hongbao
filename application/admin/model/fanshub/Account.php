<?php

namespace app\admin\model\fanshub;

use think\Model;

class Account extends Model
{
    protected $name = 'fans_account';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getFlowStageList()
    {
        return ['stage1' => '阶段一', 'stage2' => '阶段二'];
    }

    public function getStatusList()
    {
        return ['normal' => '正常', 'frozen' => '冻结'];
    }

    public function getUidAuditList()
    {
        return [
            ''         => '未提交',
            'pending'  => '待核销',
            'approved' => '已通过',
            'rejected' => '已拒绝',
        ];
    }

    public function getUserModeList()
    {
        return ['newbie' => '新手', 'master' => '团长'];
    }

    public function user()
    {
        return $this->belongsTo('app\admin\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
