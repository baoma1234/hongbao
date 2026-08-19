<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubYxxGroup;
use think\Db;

/**
 * 鱼虾蟹群桌状态
 *
 * @icon fa fa-users
 */
class Yxxgroup extends Backend
{
    protected $noNeedRight = [];

    public function index()
    {
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $query = Db::name('fans_yxx_group_state');
            if ($where) {
                $query->where($where);
            }
            $total = (clone $query)->count();
            $list = $query
                ->order($sort ?: 'updatetime', $order ?: 'desc')
                ->limit($offset, $limit)
                ->select();
            if (!is_array($list)) {
                $list = $list ? $list->toArray() : [];
            }
            $gids = [];
            $ownerIds = [];
            foreach ($list as $row) {
                $gid = (int)($row['group_id'] ?? 0);
                if ($gid > 0) {
                    $gids[$gid] = $gid;
                }
                $oid = (int)($row['owner_user_id'] ?? 0);
                if ($oid > 0) {
                    $ownerIds[$oid] = $oid;
                }
            }
            $nameMap = [];
            if ($gids) {
                $names = Db::name('chat_groups')->where('id', 'in', array_values($gids))->column('name', 'id');
                if (is_array($names)) {
                    $nameMap = $names;
                }
            }
            $nickMap = [];
            if ($ownerIds) {
                $nicks = Db::name('user')->where('id', 'in', array_values($ownerIds))->column('nickname', 'id');
                if (is_array($nicks)) {
                    $nickMap = $nicks;
                }
            }
            foreach ($list as &$row) {
                $gid = (int)($row['group_id'] ?? 0);
                $oid = (int)($row['owner_user_id'] ?? 0);
                $row['group_name'] = $nameMap[$gid] ?? ('群 #' . $gid);
                $row['owner_nickname'] = $nickMap[$oid] ?? ('UID ' . $oid);
                $row['open_text'] = !empty($row['is_open']) ? '开桌中' : '已关桌';
                $row['time_text'] = !empty($row['updatetime']) ? date('Y-m-d H:i:s', (int)$row['updatetime']) : '-';
            }
            unset($row);
            return json(['total' => $total, 'rows' => $list]);
        }
        return $this->view->fetch();
    }

    /**
     * 后台强制关桌（需谷歌验证码）
     */
    public function close($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        try {
            $this->auth->assertGoogleCode($this->request->post('google_code', ''));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
        }
        $gid = (int)($ids ?: $this->request->post('ids'));
        if ($gid <= 0) {
            $this->error('无效群 ID');
        }
        try {
            FansHubYxxGroup::adminForceClose($gid);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
        }
        $this->success('已强制关桌');
    }
}
