<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;

/**
 * 极光推送设备（Registration ID）
 *
 * @icon fa fa-mobile
 */
class Pushdevice extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,user_id,registration_id,platform';
    protected $multiFields = 'enabled';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Pushdevice;
        $this->view->assign('platformList', $this->model->getPlatformList());
        $this->assignconfig('platformList', $this->model->getPlatformList());
    }
}
