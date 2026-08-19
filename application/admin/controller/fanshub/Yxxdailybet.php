<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use think\Db;

/**
 * 鱼虾蟹日下注权重（红包雨资格）
 *
 * @icon fa fa-bar-chart
 */
class Yxxdailybet extends Backend
{
    protected $noNeedRight = [];

    public function index()
    {
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $query = Db::name('fans_yxx_daily_bet');
            if ($where) {
                $query->where($where);
            }
            $total = (clone $query)->count();
            $list = $query
                ->order($sort ?: 'bet_total', $order ?: 'desc')
                ->limit($offset, $limit)
                ->select();
            if (!is_array($list)) {
                $list = $list ? $list->toArray() : [];
            }
            $uids = [];
            foreach ($list as $row) {
                $uid = (int)($row['user_id'] ?? 0);
                if ($uid > 0) {
                    $uids[$uid] = $uid;
                }
            }
            $nickMap = [];
            if ($uids) {
                $nicks = Db::name('user')->where('id', 'in', array_values($uids))->column('nickname', 'id');
                if (is_array($nicks)) {
                    $nickMap = $nicks;
                }
            }
            foreach ($list as &$row) {
                $uid = (int)($row['user_id'] ?? 0);
                $row['nickname'] = $nickMap[$uid] ?? ('UID ' . $uid);
                $d = (string)($row['bet_date'] ?? '');
                if (strlen($d) === 8) {
                    $row['bet_date_text'] = substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
                } else {
                    $row['bet_date_text'] = $d ?: '-';
                }
                $row['time_text'] = !empty($row['updatetime']) ? date('Y-m-d H:i:s', (int)$row['updatetime']) : '-';
            }
            unset($row);
            return json(['total' => $total, 'rows' => $list]);
        }
        return $this->view->fetch();
    }
}
