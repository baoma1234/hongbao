<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;

/**
 * 尾数牛牛自动购入/领取任务
 *
 * @icon fa fa-android
 */
class Niuniuauto extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,name,group_id';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Niuniuauto;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('actorModeList', $this->model->getActorModeList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('actorModeList', $this->model->getActorModeList());
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->normalize($this->request->post('row/a'));
            $this->request->post(['row' => $params]);
        }
        return parent::add();
    }

    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            $params = $this->normalize($this->request->post('row/a'));
            $this->request->post(['row' => $params]);
        }
        return parent::edit($ids);
    }

    protected function normalize(array $row)
    {
        $row['group_id'] = max(0, (int)($row['group_id'] ?? 0));
        $row['actor_mode'] = ((int)($row['actor_mode'] ?? 2) === 1) ? 1 : 2;
        $row['buyer_count_min'] = max(1, (int)($row['buyer_count_min'] ?? 3));
        $row['buyer_count_max'] = max($row['buyer_count_min'], (int)($row['buyer_count_max'] ?? 8));
        $row['shares_min'] = max(1, (int)($row['shares_min'] ?? 1));
        $row['shares_max'] = max($row['shares_min'], (int)($row['shares_max'] ?? 3));
        $row['buy_delay_min_ms'] = max(0, (int)($row['buy_delay_min_ms'] ?? 800));
        $row['buy_delay_max_ms'] = max($row['buy_delay_min_ms'], (int)($row['buy_delay_max_ms'] ?? 8000));
        $row['claim_delay_min_ms'] = max(0, (int)($row['claim_delay_min_ms'] ?? 500));
        $row['claim_delay_max_ms'] = max($row['claim_delay_min_ms'], (int)($row['claim_delay_max_ms'] ?? 5000));
        $row['auto_buy'] = !empty($row['auto_buy']) ? 1 : 0;
        $row['auto_claim'] = !empty($row['auto_claim']) ? 1 : 0;
        $row['buy_user_ids'] = trim((string)($row['buy_user_ids'] ?? ''));
        $row['name'] = trim((string)($row['name'] ?? ''));
        $row['remark'] = trim((string)($row['remark'] ?? ''));
        if (!isset($row['status']) || !in_array($row['status'], ['normal', 'hidden'], true)) {
            $row['status'] = 'hidden';
        }
        return $row;
    }
}
