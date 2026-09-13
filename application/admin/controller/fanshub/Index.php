<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubImCache;
use app\common\library\FansHubService;

/**
 * 福利大厅首页
 *
 * @icon fa fa-gift
 */
class Index extends Backend
{
    protected $noNeedRight = ['index', 'onlinecount', 'onlinelist'];

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
        $online = FansHubImCache::realtimeOnlineReal(['page' => 1, 'limit' => 1]);
        $stats['online_real'] = (int)($online['total'] ?? 0);
        $stats['online_raw'] = (int)($online['raw_online'] ?? 0);
        $stats['online_bot'] = (int)($online['bot_online'] ?? 0);
        $this->view->assign([
            'stats' => $stats,
            'start' => $start,
            'end'   => $end,
        ]);
        return $this->view->fetch();
    }

    /**
     * 实时在线真实人数（AJAX 轮询）
     */
    public function onlinecount()
    {
        $online = FansHubImCache::realtimeOnlineReal(['page' => 1, 'limit' => 1]);
        $this->success('', null, [
            'online_real' => (int)($online['total'] ?? 0),
            'online_raw'  => (int)($online['raw_online'] ?? 0),
            'online_bot'  => (int)($online['bot_online'] ?? 0),
        ]);
    }

    /**
     * 实时在线真实用户列表（分页）
     */
    public function onlinelist()
    {
        $page = max(1, (int)$this->request->get('page', 1));
        $limit = min(100, max(1, (int)$this->request->get('limit', 50)));
        $data = FansHubImCache::realtimeOnlineReal([
            'page'  => $page,
            'limit' => $limit,
        ]);
        $this->success('', null, $data);
    }
}
