<?php

namespace app\admin\model\fanshub;

class Notice extends \app\common\model\fanshub\Notice
{
    public function getStatusList()
    {
        return [
            'draft'     => '草稿',
            'published' => '展示中',
            'paused'    => '暂停展示',
            'pending'   => '待审核',
            'rejected'  => '已拒绝',
        ];
    }

    public function getCategoryList()
    {
        return self::categoryMap();
    }
}
