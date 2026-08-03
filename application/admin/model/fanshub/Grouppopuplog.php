<?php

namespace app\admin\model\fanshub;

use think\Model;

class Grouppopuplog extends Model
{
    protected $name = 'chat_group_popup_logs';
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;

    public function getActionList()
    {
        return [
            'view'             => '展示',
            'dismiss_forever'  => '永久关闭',
        ];
    }

    public function user()
    {
        return $this->belongsTo('app\admin\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function popup()
    {
        return $this->belongsTo('app\admin\model\fanshub\Grouppopup', 'popup_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
