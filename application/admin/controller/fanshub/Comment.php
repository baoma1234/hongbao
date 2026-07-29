<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;

/**
 * 福利留言审核（已下线：实时福利交互大厅已剔除）
 *
 * @icon fa fa-comments
 */
class Comment extends Backend
{
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->error('留言功能已关闭');
    }

    public function index()
    {
        $this->error('留言功能已关闭');
    }

    public function approve($ids = null)
    {
        $this->error('留言功能已关闭');
    }

    public function reject($ids = null)
    {
        $this->error('留言功能已关闭');
    }

    public function export()
    {
        $this->error('留言功能已关闭');
    }
}
