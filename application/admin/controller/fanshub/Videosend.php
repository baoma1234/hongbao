<?php

namespace app\admin\controller\fanshub;

use think\Db;

/**
 * 视频发送（群/私聊，支持 mp4 / m3u8 外链）
 *
 * @icon fa fa-film
 */
class Videosend extends Imagent
{
    public function index()
    {
        $agents = Db::name('chat_agent_accounts')
            ->where('status', 1)
            ->order('id', 'desc')
            ->select();
        $groups = Db::name('chat_groups')
            ->where('status', 1)
            ->order('id', 'desc')
            ->limit(80)
            ->field('id,name,owner_user_id')
            ->select();

        $this->view->assign('agents', $agents ?: []);
        $this->view->assign('groups', $groups ?: []);
        return $this->view->fetch();
    }

    /**
     * 仅发视频消息（msg_type=5）
     */
    public function send()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }

        $agentUserId = (int)$this->request->post('agent_user_id');
        $ctype = (int)$this->request->post('conversation_type', 2);
        $content = trim((string)$this->request->post('content', ''));

        $url = trim((string)$this->request->post('video_url', ''));
        $thumb = trim((string)$this->request->post('thumb_url', ''));
        $extraIn = $this->parseExtraInput($this->request->post('extra'));
        if ($url === '' && !empty($extraIn['url'])) {
            $url = trim((string)$extraIn['url']);
        }
        if ($thumb === '' && !empty($extraIn['thumb'])) {
            $thumb = trim((string)$extraIn['thumb']);
        }
        if ($agentUserId <= 0) {
            $this->error('请选择托管账号');
        }
        if ($url === '') {
            $this->error('请填写视频地址');
        }
        if (!preg_match('#^https?://#i', $url)) {
            $this->error('视频地址须为 http(s) 开头');
        }
        if (strlen($url) > 1200) {
            $this->error('视频地址过长');
        }

        $extra = ['url' => $url, 'fullurl' => $url];
        if ($thumb !== '') {
            $extra['thumb'] = $thumb;
            $extra['poster'] = $thumb;
        }
        if ($content === '') {
            $content = '[视频]';
        }

        $payload = [
            'agent_user_id' => $agentUserId,
            'content'       => $content,
            'msg_type'      => 5,
            'extra'         => $extra,
            'admin_id'      => (int)$this->auth->id,
        ];

        if ($ctype === 2) {
            $groupId = (int)$this->request->post('group_id');
            if ($groupId <= 0) {
                $groupId = (int)$this->request->post('conversation_id');
            }
            if ($groupId <= 0) {
                $this->error('缺少群ID');
            }
            $payload['group_id'] = $groupId;
            $result = $this->callBridge('/agent/send_group', $payload);
            $this->publishOutgoingMessage($result);
        } else {
            $toUserId = (int)$this->request->post('to_user_id');
            if ($toUserId <= 0 || $toUserId === $agentUserId) {
                $this->error('对方ID无效');
            }
            $payload['to_user_id'] = $toUserId;
            $result = $this->callBridge('/agent/send_private', $payload);
            $this->publishOutgoingMessage($result);
        }
        $this->success('已发送', null, $result);
    }
}
