<?php

namespace app\admin\model\fanshub;

class NoticeTheme extends \app\common\model\fanshub\NoticeTheme
{
    public function getStatusList()
    {
        return [
            'normal' => '启用',
            'hidden' => '隐藏',
        ];
    }
}
