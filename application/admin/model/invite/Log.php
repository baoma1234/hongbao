<?php

namespace app\admin\model\invite;

use think\Model;


class Log extends Model
{

    

    

    // 表名
    protected $name = 'invite_log';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'integer';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = false;

    // 追加属性
    protected $append = [
        'is_recharge_text'
    ];
    

    
    public function getIsRechargeList()
    {
        return ['0' => __('Is_recharge 0'), '1' => __('Is_recharge 1')];
    }


    public function getIsRechargeTextAttr($value, $data)
    {
        $value = $value ?: ($data['is_recharge'] ?? '');
        $list = $this->getIsRechargeList();
        return $list[$value] ?? '';
    }




}
