<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubService;
use think\Db;

/**
 * 客服被加好友自动回复
 *
 * @icon fa fa-commenting
 */
class Csreply extends Backend
{
    public function index()
    {
        $cfg = \app\common\library\FansHubService::config() ?: [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $defaultReply = (string)($cfg['im_cs_friend_reply'] ?? '');
        if ($defaultReply === '') {
            $defaultReply = (string)($cfg['h5_copy']['chat_admin_welcome'] ?? '您好，我是平台客服，有问题随时私聊我。');
        }
        $agents = Db::name('chat_agent_accounts')->order('id', 'desc')->select();
        if ($this->request->isPost()) {
            return $this->save();
        }
        $this->view->assign('defaultReply', $defaultReply);
        $this->view->assign('agents', $agents ?: []);
        return $this->view->fetch();
    }

    public function save()
    {
        $default = trim((string)$this->request->post('default_reply', ''));
        $replies = $this->request->post('friend_reply/a');
        if (!is_array($replies)) {
            $replies = [];
        }
        $now = time();
        foreach ($replies as $id => $text) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }
            Db::name('chat_agent_accounts')->where('id', $id)->update([
                'friend_reply' => mb_substr(trim((string)$text), 0, 500),
                'updatetime'   => $now,
            ]);
        }
        $cfg = FansHubService::config() ?: [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $cfg['im_cs_friend_reply'] = mb_substr($default, 0, 500);
        if (!FansHubService::saveFanshubConfig($cfg)) {
            $this->error('保存配置失败');
        }
        $this->success('保存成功');
    }
}
