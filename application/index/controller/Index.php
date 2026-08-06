<?php

namespace app\index\controller;

use think\Controller;

/**
 * 前台首页：跳转到 uni H5（/999）
 */
class Index extends Controller
{
    public function index()
    {
        return redirect('/999/');
    }
}
