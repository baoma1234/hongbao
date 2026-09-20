<?php

namespace app\admin\model\fanshub;

use think\Model;

class Videocrawl extends Model
{
    protected $name = 'chat_video_crawl_task';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return ['normal' => '启用', 'hidden' => '停用'];
    }
}
