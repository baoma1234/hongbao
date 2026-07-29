<?php

namespace app\admin\model\bot;

use think\Model;


class Record extends Model
{

    

    

    // 表名
    protected $name = 'bot_record';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'integer';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = false;

    // 追加属性
    protected $append = [
        'type_text',
        'status_text'
    ];
    public function user()
    {
        // belongsTo('关联模型名', '当前表外键', '关联表主键', [], 'JOIN类型')
        // setEagerlyType(0) 是 FastAdmin 专属技巧，作用是强制使用 JOIN 查询，方便前端表格直接搜索
        return $this->belongsTo('User', 'tg_user_id', 'tg_user_id', [], 'LEFT')->setEagerlyType(0);
    }


    
    public function getTypeList()
    {
        return ['checkin' => __('Type checkin'), 'checkout' => __('Type checkout'), 'meal' => __('Type meal'), 'smoke' => __('Type smoke')];
    }

    public function getStatusList()
    {
        return ['ongoing' => __('Status ongoing'), 'completed' => __('Status completed')];
    }


    public function getTypeTextAttr($value, $data)
    {
        $value = $value ?: ($data['type'] ?? '');
        $list = $this->getTypeList();
        return $list[$value] ?? '';
    }


    public function getStatusTextAttr($value, $data)
    {
        $value = $value ?: ($data['status'] ?? '');
        $list = $this->getStatusList();
        return $list[$value] ?? '';
    }




}
