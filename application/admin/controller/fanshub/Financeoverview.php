<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubFinanceOverview;

/**
 * 财务总览（日/周/月充提）
 *
 * @icon fa fa-dashboard
 */
class Financeoverview extends Backend
{
    public function index()
    {
        if ($this->request->isAjax()) {
            $data = FansHubFinanceOverview::build();
            return json(['code' => 1, 'msg' => 'ok', 'data' => $data]);
        }
        $stats = FansHubFinanceOverview::build();
        $this->view->assign('stats', $stats);
        return $this->view->fetch();
    }
}
