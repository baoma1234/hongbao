<?php

namespace app\admin\model\fanshub;

class Notice extends \app\common\model\fanshub\Notice
{
    public function getStatusList()
    {
        return ['draft' => '草稿', 'published' => '已发布'];
    }

    public function getCategoryList()
    {
        return self::categoryMap();
    }
}
