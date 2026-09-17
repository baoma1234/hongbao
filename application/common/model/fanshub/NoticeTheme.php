<?php

namespace app\common\model\fanshub;

use think\Model;

class NoticeTheme extends Model
{
    protected $name = 'fans_notice_theme';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    /** 主题所属大模块（与帖子 category 一致） */
    public static function categoryMap()
    {
        return Notice::categoryMap();
    }

    public static function normalizeCategory($category)
    {
        $category = trim((string)$category);
        $map = self::categoryMap();
        return isset($map[$category]) ? $category : '';
    }
}
