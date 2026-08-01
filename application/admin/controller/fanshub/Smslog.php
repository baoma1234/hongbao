<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;

/**
 * 短信发送记录
 *
 * @icon fa fa-commenting
 */
class Smslog extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,mobile,code,event,ip';
    protected $noNeedRight = [];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\common\model\fanshub\SmsLog;
        $this->view->assign('statusList', ['sent' => '已发送', 'used' => '已使用']);
        $this->view->assign('channelList', [
            'mock'    => '模拟',
            'dagou'   => '大狗',
            'una'     => 'UNA国际',
            'http'    => 'HTTP网关',
            'default' => '默认',
        ]);
        $this->assignconfig('statusList', ['sent' => '已发送', 'used' => '已使用']);
        $this->assignconfig('channelList', [
            'mock'    => '模拟',
            'dagou'   => '大狗',
            'una'     => 'UNA国际',
            'http'    => 'HTTP网关',
            'default' => '默认',
        ]);
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }
        return $this->view->fetch();
    }
}
