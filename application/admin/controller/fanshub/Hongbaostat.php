<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubHongbaoStat;

/**
 * 红宝统计
 *
 * @icon fa fa-bar-chart
 */
class Hongbaostat extends Backend
{
    protected $noNeedRight = ['*'];

    public function index()
    {
        $start = trim((string)$this->request->get('start', ''));
        $end = trim((string)$this->request->get('end', ''));
        if ($start === '' && $end === '') {
            $end = date('Y-m-d');
            $start = date('Y-m-d', strtotime('-6 days'));
        }
        $startTs = $start !== '' ? strtotime($start . ' 00:00:00') : 0;
        $endTs = $end !== '' ? strtotime($end . ' 23:59:59') : 0;
        $stats = FansHubHongbaoStat::build($startTs, $endTs);
        $this->view->assign([
            'stats' => $stats,
            'start' => $start,
            'end'   => $end,
        ]);
        return $this->view->fetch();
    }
}
