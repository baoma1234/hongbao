<?php

namespace app\common\model\fanshub;

use think\Model;

class TelegramBind extends Model
{
    protected $name = 'fans_telegram_bind';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function user()
    {
        return $this->belongsTo('app\\admin\\model\\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
