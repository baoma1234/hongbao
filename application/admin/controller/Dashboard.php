<?php

namespace app\admin\controller;

use app\admin\model\Admin;
use app\admin\model\User;
use app\common\controller\Backend;
use app\common\model\Attachment;
use fast\Date;
use think\Db;

/**
 * 控制台
 *
 * @icon   fa fa-dashboard
 * @remark 用于展示当前系统中的统计数据、统计报表及重要实时数据
 */
class Dashboard extends Backend
{

    /**
     * 查看
     */
    public function index()
    {
        try {
            \think\Db::execute("SET @@sql_mode='';");
        } catch (\Exception $e) {

        }

        $cacheKey = 'admin_dashboard_stats_v1';
        $cached = cache($cacheKey);
        if (is_array($cached) && !empty($cached['assign'])) {
            $this->view->assign($cached['assign']);
            $this->assignconfig('column', $cached['column'] ?? []);
            $this->assignconfig('userdata', $cached['userdata'] ?? []);
            return $this->view->fetch();
        }

        $column = [];
        $starttime = Date::unixtime('day', -6);
        $endtime = Date::unixtime('day', 0, 'end');
        $joinlist = Db("user")->where('jointime', 'between time', [$starttime, $endtime])
            ->field('jointime, status, COUNT(*) AS nums, DATE_FORMAT(FROM_UNIXTIME(jointime), "%Y-%m-%d") AS join_date')
            ->group('join_date')
            ->select();
        for ($time = $starttime; $time <= $endtime;) {
            $column[] = date("Y-m-d", $time);
            $time += 86400;
        }
        $userlist = array_fill_keys($column, 0);
        foreach ($joinlist as $k => $v) {
            $userlist[$v['join_date']] = $v['nums'];
        }

        // 库大小走 information_schema，避免 SHOW TABLE STATUS 全表元数据扫
        $dbMeta = Db::query(
            "SELECT COUNT(*) AS cnt, IFNULL(SUM(DATA_LENGTH+INDEX_LENGTH),0) AS sz
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
        );
        $dbtablenums = (int)($dbMeta[0]['cnt'] ?? 0);
        $dbsize = (float)($dbMeta[0]['sz'] ?? 0);

        $addonList = get_addon_list();
        $totalworkingaddon = 0;
        $totaladdon = count($addonList);
        foreach ($addonList as $index => $item) {
            if ($item['state']) {
                $totalworkingaddon += 1;
            }
        }
        $assign = [
            'totaluser'         => User::count(),
            'totaladdon'        => $totaladdon,
            'totaladmin'        => Admin::count(),
            'totalcategory'     => \app\common\model\Category::count(),
            'todayusersignup'   => User::whereTime('jointime', 'today')->count(),
            'todayuserlogin'    => User::whereTime('logintime', 'today')->count(),
            'sevendau'          => User::whereTime('jointime|logintime|prevtime', '-7 days')->count(),
            'thirtydau'         => User::whereTime('jointime|logintime|prevtime', '-30 days')->count(),
            'threednu'          => User::whereTime('jointime', '-3 days')->count(),
            'sevendnu'          => User::whereTime('jointime', '-7 days')->count(),
            'dbtablenums'       => $dbtablenums,
            'dbsize'            => $dbsize,
            'totalworkingaddon' => $totalworkingaddon,
            'attachmentnums'    => Attachment::count(),
            'attachmentsize'    => Attachment::sum('filesize'),
            'picturenums'       => Attachment::where('mimetype', 'like', 'image/%')->count(),
            'picturesize'       => Attachment::where('mimetype', 'like', 'image/%')->sum('filesize'),
        ];
        $this->view->assign($assign);

        $columnKeys = array_keys($userlist);
        $userdata = array_values($userlist);
        $this->assignconfig('column', $columnKeys);
        $this->assignconfig('userdata', $userdata);

        cache($cacheKey, [
            'assign'   => $assign,
            'column'   => $columnKeys,
            'userdata' => $userdata,
        ], 120);

        return $this->view->fetch();
    }

}
