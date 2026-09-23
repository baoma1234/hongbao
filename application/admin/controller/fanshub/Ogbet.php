<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubOg;
use think\Db;

/**
 * OG视讯投注记录
 *
 * @icon fa fa-list-alt
 */
class Ogbet extends Backend
{
    protected $model = null;
    protected $relationSearch = true;
    protected $searchFields = 'id,player_id,transaction_id,game_name,game_id,round_id';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Ogbet;
        $this->view->assign('gameTypeList', $this->model->getGameTypeList());
        $this->assignconfig('gameTypeList', $this->model->getGameTypeList());
        $cursor = '1';
        try {
            $v = Db::name('fans_og_sync')->where('name', 'bet_fetch_id')->value('value');
            if ($v !== null && $v !== '') {
                $cursor = (string)$v;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $this->view->assign('betFetchCursor', $cursor);
        $this->assignconfig('betFetchCursor', $cursor);
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            if ($sort === '' || $sort === null) {
                $sort = 'id';
                $order = $order ?: 'desc';
            }
            $list = $this->model
                ->with(['user'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            foreach ($list as $row) {
                if ($row->getRelation('user')) {
                    $row->getRelation('user')->visible(['id', 'mobile', 'nickname']);
                }
            }
            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }
        return $this->view->fetch();
    }

    /**
     * 立即同步一页/多页
     */
    public function syncnow()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        @set_time_limit(180);
        try {
            $opts = [
                'max_pages' => max(1, min(20, (int)$this->request->post('max_pages', 3))),
            ];
            $fetchId = (int)$this->request->post('fetch_id', 0);
            if ($fetchId > 0) {
                $opts['fetch_id'] = $fetchId;
                $opts['advance_cursor'] = false;
            }
            $gameType = (int)$this->request->post('game_type_id', 0);
            if ($gameType > 0) {
                $opts['game_type_id'] = $gameType;
            }
            $playerId = trim((string)$this->request->post('player_id', ''));
            if ($playerId !== '') {
                $opts['player_id'] = $playerId;
                $opts['advance_cursor'] = false;
            }
            $ret = FansHubOg::syncBetHistory($opts);
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: '同步失败');
        }
        $this->success(
            '同步完成：抓取 ' . (int)($ret['fetched'] ?? 0)
            . ' / 写入 ' . (int)($ret['upserted'] ?? 0)
            . ' / 挂账号 ' . (int)($ret['relinked'] ?? 0)
            . ' / 游标 ' . (string)($ret['last_fetch_id'] ?? ''),
            null,
            $ret
        );
    }

    /**
     * 仅把未挂账号的注单按 player_id 回填 user_id
     */
    public function relink()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        try {
            $n = FansHubOg::relinkUnmappedBets(10000);
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: '回填失败');
        }
        $this->success('已挂回账号 ' . (int)$n . ' 条', null, ['relinked' => $n]);
    }

    public function detail($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $user = null;
        if ((int)$row->user_id > 0) {
            $user = \app\common\model\User::get($row->user_id);
        }
        $secondary = json_decode((string)($row->secondary_info ?? ''), true);
        $other = json_decode((string)($row->other_info ?? ''), true);
        $this->view->assign('row', $row);
        $this->view->assign('user', $user ?: []);
        $this->view->assign('secondary', is_array($secondary) ? $secondary : []);
        $this->view->assign('other', is_array($other) ? $other : []);
        $this->view->assign('gameTypeList', $this->model->getGameTypeList());
        return $this->view->fetch();
    }
}
