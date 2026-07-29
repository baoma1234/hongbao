<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubService;

/**
 * 福利大厅首页
 *
 * @icon fa fa-gift
 */
class Index extends Backend
{
    protected $noNeedRight = ['index'];

    public function index()
    {
        $start = trim((string)$this->request->get('start', ''));
        $end = trim((string)$this->request->get('end', ''));
        $startTs = $start !== '' ? strtotime($start . ' 00:00:00') : 0;
        $endTs = $end !== '' ? strtotime($end . ' 23:59:59') : 0;
        if ($startTs > 0 && $endTs > 0 && $startTs > $endTs) {
            $tmp = $startTs;
            $startTs = $endTs;
            $endTs = $tmp;
        }
        $stats = FansHubService::dashboardStats($startTs, $endTs);
        $this->view->assign([
            'stats' => $stats,
            'start' => $start,
            'end'   => $end,
        ]);
        return $this->view->fetch();
    }
}
