<?php

namespace app\common\model\fanshub;

use think\Model;

class NoticeTheme extends Model
{
    protected $name = 'fans_notice_theme';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
}
