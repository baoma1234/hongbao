<?php

namespace app\admin\model\fanshub;

use think\Model;

class Ogbet extends Model
{
    protected $name = 'fans_og_bet';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getGameTypeList()
    {
        return [
            '1' => '真人',
            '2' => '老虎机',
            '3' => '棋牌',
            '4' => '彩票',
            '5' => '体育',
        ];
    }

    public function user()
    {
        return $this->belongsTo('app\admin\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
