<?php
namespace app\admin\controller\bot;

use app\common\controller\Backend;
use think\Db;

class Record extends Backend
{
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\bot\Record;

        // 【核心修复】全局注入 statusList 变量，彻底解决 FastAdmin 后台渲染报错
        $statusList = [
            'ongoing'   => '进行中',
            'completed' => '已完成'
        ];
        $typeList = [
            'checkin'  => '上班打卡',
            'checkout' => '下班打卡',
            'meal'     => '吃饭',
            'smoke'    => '抽烟'
        ];
        $this->view->assign("statusList", $statusList);
        $this->view->assign("typeList", $typeList);
    }

    /**
     * 打卡流水列表 (标准 CRUD)
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model
                ->with(['user']) // 建议在 Model 关联 fa_bot_user 表
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            
            $result = array("total" => $list->total(), "rows" => $list->items());
            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 月度统计报表接口与展示
     */
    public function report()
    {
        if ($this->request->isAjax()) {
            $filterMonth = $this->request->request('month', date('Y-m'));
            $startTime = strtotime($filterMonth . '-01 00:00:00');
            $endTime = strtotime(date('Y-m-t 23:59:59', $startTime));

            // 高效聚合查询
            $data = Db::name('bot_record')
                ->alias('r')
                ->join('bot_user u', 'r.tg_user_id = u.tg_user_id', 'LEFT')
                ->where('r.createtime', 'between', [$startTime, $endTime])
                ->field('
                    IFNULL(u.real_name, u.username) as name, 
                    r.type, 
                    COUNT(r.id) as times, 
                    SUM(r.duration_minutes) as total_minutes
                ')
                ->group('r.tg_user_id, r.type')
                ->select();

            $userNames = [];
            $userMap = [];
            foreach ($data as $v) {
                $name = $v['name'] ?: '未知用户';
                if (!isset($userMap[$name])) {
                    $userMap[$name] = ['checkin' => 0, 'meal' => 0, 'smoke' => 0];
                    $userNames[] = $name;
                }
                if ($v['type'] == 'checkin')  $userMap[$name]['checkin'] = (int)$v['times'];
                if ($v['type'] == 'meal')     $userMap[$name]['meal'] = (int)$v['total_minutes'];
                if ($v['type'] == 'smoke')    $userMap[$name]['smoke'] = (int)$v['total_minutes'];
            }

            $checkinData = []; $mealData = []; $smokeData = [];
            foreach ($userNames as $name) {
                $checkinData[] = $userMap[$name]['checkin'];
                $mealData[] = $userMap[$name]['meal'];
                $smokeData[] = $userMap[$name]['smoke'];
            }

            return $this->success('获取成功', null, [
                'userNames' => $userNames,
                'checkinData' => $checkinData,
                'mealData' => $mealData,
                'smokeData' => $smokeData
            ]);
        }
        return $this->view->fetch();
    }
}