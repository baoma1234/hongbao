<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubVideoCrawl;
use think\Db;

/**
 * 视频采集管理
 *
 * @icon fa fa-cloud-download
 */
class Videocrawl extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,name,api_url,group_id';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Videocrawl;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }

    public function index()
    {
        if ($this->request->isAjax()) {
            return parent::index();
        }
        return $this->view->fetch();
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->normalize($this->request->post('row/a'));
            $this->request->post(['row' => $params]);
        }
        $this->view->assign('groups', $this->groupOptions());
        return parent::add();
    }

    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            $params = $this->normalize($this->request->post('row/a'));
            $this->request->post(['row' => $params]);
            return parent::edit($ids);
        }
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $this->view->assign('row', $row);
        $this->view->assign('groups', $this->groupOptions());
        return $this->view->fetch();
    }

    /**
     * 立即采集一页（倒序当前页）
     */
    public function runonce($ids = null)
    {
        $raw = (string)($ids ?: $this->request->post('ids', $this->request->get('ids', '')));
        $idList = [];
        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            $id = (int)$p;
            if ($id > 0) {
                $idList[$id] = $id;
            }
        }
        if (!$idList) {
            $this->error('请先勾选任务');
        }
        @set_time_limit(300);
        $msgs = [];
        $anyOk = false;
        foreach ($idList as $id) {
            $stat = FansHubVideoCrawl::runOnePage($id, true);
            $msgs[] = '#' . $id . ' ' . ($stat['msg'] ?? '');
            if (!empty($stat['ok'])) {
                $anyOk = true;
            }
        }
        $text = implode('；', $msgs);
        if (!$anyOk) {
            $this->error($text !== '' ? $text : '采集失败');
        }
        $this->success($text);
    }

    /**
     * 重置倒序进度：从 start_page 重新开始（不去重表，避免重发；仅重置页码）
     */
    public function resetpage($ids = null)
    {
        $raw = (string)($ids ?: $this->request->post('ids', $this->request->get('ids', '')));
        $idList = [];
        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            $id = (int)$p;
            if ($id > 0) {
                $idList[] = $id;
            }
        }
        if (!$idList) {
            $this->error('请先勾选任务');
        }
        $n = 0;
        foreach ($idList as $id) {
            $row = $this->model->get($id);
            if (!$row) {
                continue;
            }
            $start = max(1, (int)$row['start_page']);
            $row->save([
                'current_page' => $start,
                'pages_done'   => 0,
                'last_error'   => '',
                'updatetime'   => time(),
            ]);
            $n++;
        }
        $this->success('已重置 ' . $n . ' 个任务的倒序进度（已发去重保留）');
    }

    protected function groupOptions()
    {
        $rows = Db::name('chat_groups')
            ->whereIn('status', [1, 3])
            ->order('id', 'asc')
            ->field('id,name')
            ->limit(500)
            ->select();
        $out = [];
        foreach ($rows ?: [] as $r) {
            $out[] = [
                'id'   => (int)$r['id'],
                'name' => (string)($r['name'] ?? ''),
            ];
        }
        return $out;
    }

    protected function normalize(array $params)
    {
        $params['name'] = trim((string)($params['name'] ?? ''));
        if ($params['name'] === '') {
            $this->error('请填写任务名');
        }
        $api = trim((string)($params['api_url'] ?? ''));
        if ($api === '') {
            $this->error('请填写采集地址');
        }
        if (!preg_match('#^https?://#i', $api)) {
            $this->error('采集地址须以 http(s) 开头');
        }
        $params['api_url'] = mb_substr($api, 0, 500);

        $params['group_id'] = max(0, (int)($params['group_id'] ?? 0));
        if ($params['group_id'] <= 0) {
            $this->error('请选择发送群');
        }
        $g = Db::name('chat_groups')->where('id', $params['group_id'])->whereIn('status', [1, 3])->find();
        if (!$g) {
            $this->error('群不存在');
        }

        $params['send_user_id'] = max(1, (int)($params['send_user_id'] ?? 11111111));
        $params['start_page'] = max(1, min(99999, (int)($params['start_page'] ?? 100)));
        // 新建或改了 start_page 且 current 为空：对齐起点
        $cur = (int)($params['current_page'] ?? 0);
        if ($cur <= 0) {
            $params['current_page'] = $params['start_page'];
        } else {
            $params['current_page'] = max(0, min(99999, $cur));
        }
        $params['page_limit'] = max(0, min(100, (int)($params['page_limit'] ?? 0)));
        $params['auto_run'] = !empty($params['auto_run']) ? 1 : 0;
        $status = (string)($params['status'] ?? 'hidden');
        $params['status'] = $status === 'normal' ? 'normal' : 'hidden';
        $params['remark'] = mb_substr(trim((string)($params['remark'] ?? '')), 0, 255);
        unset($params['force_run'], $params['pages_done'], $params['sent_count'], $params['skip_count'], $params['pagecount'], $params['last_page'], $params['last_error'], $params['last_run_time']);
        return $params;
    }
}
