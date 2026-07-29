<?php

namespace app\index\controller;

use think\Controller;

/**
 * 前台首页：跳转到福利大厅 H5
 */
class Index extends Controller
{
    public function index()
    {
        return redirect('/888/');
    }
}
