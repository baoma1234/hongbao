<?php

namespace app\admin\controller\sys;

use app\common\controller\Backend;
use app\common\library\finance\MerchSync;
use app\common\library\Platform;

/**
 * 商户通道管理
 *
 * @icon fa fa-exchange
 */
class MerchChannel extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,channelName,merchCode,chanel';
    protected $multiFields = 'status';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\sys\MerchChannel;
        $this->view->assign('pidList', Platform::getList());
        $this->assignconfig('platformList', Platform::getList());
        $this->view->assign('statusList', $this->model->getStatusList());
    }

    /**
     * 手动从远端同步商户通道
     */
    public function sync()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }

        $pid = (int)$this->request->post('pid', 1);
        if (!Platform::isValid($pid)) {
            $this->error('无效的平台');
        }

        try {
            $data = (new MerchSync($pid))->sync($pid);
            $msg = sprintf(
                '同步完成：远端 %d 条，新增 %d，更新 %d，跳过 %d',
                $data['remote_total'] ?? 0,
                $data['inserted'] ?? 0,
                $data['updated'] ?? 0,
                $data['skipped'] ?? 0
            );
            $this->success($msg, null, $data);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }
}
