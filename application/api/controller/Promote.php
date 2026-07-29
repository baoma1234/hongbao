<?php

namespace app\api\controller;

use app\common\controller\Api;

/**
 * 首页接口
 */
class Promote extends Api
{
    protected $noNeedLogin = ['*'];
    protected $noNeedRight = ['*'];

    /**
     * 首页
     *
     */
    // application/api/controller/Promote.php 核心片段

    public function bind_relation()
    {
        $inviter_id = $this->request->request('ref'); // 邀请人的ID
        $user = $this->auth->getUser(); // 当前登录的新用户

        if ($user->parent_id <= 0 && $inviter_id != $user->id) {
            // 更新用户表的推荐人
            $user->save(['parent_id' => $inviter_id]);

            // 写入邀请日志
            \app\common\model\InviteLog::create([
                'inviter_id' => $inviter_id,
                'invitee_id' => $user->id,
                'is_recharge' => 0
            ]);
            $this->success('邀请关系绑定成功');
        }
        $this->error('无法重复绑定或绑定自己');
    }
}
