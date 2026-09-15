<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;

/**
 * 社区帖子主题（展示在帖子标签）
 *
 * @icon fa fa-tags
 */
class Noticetheme extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,code,title';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\NoticeTheme;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }
}
