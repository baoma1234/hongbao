<?php

namespace app\admin\controller\sys;

use app\common\controller\Backend;
use app\common\library\Platform;

/**
 * 催单时间表
 *
 * @icon fa fa-clock-o
 */
class UrgeSchedule extends Backend
{
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\sys\UrgeSchedule;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('typeList', $this->model->getTypeList());
        $this->view->assign('pidList', Platform::getList());
        $this->assignconfig('platformList', Platform::getList());
    }
}
