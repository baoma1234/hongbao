<?php

namespace app\admin\controller\fanshub;

use think\Db;

/**
 * 视频发送（群/私聊，支持 mp4 / m3u8 外链 + 预览图 + 文案）
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
        $agents = is_array($agents) ? $agents : [];
        $seen = [];
        foreach ($agents as $ag) {
            $seen[(int)($ag['user_id'] ?? 0)] = true;
        }
        // 固定发送号（不托管）：置顶出现在下拉
        foreach ($this->videosendSenderUserIds() as $uid) {
            if ($uid <= 0 || isset($seen[$uid])) {
                continue;
            }
            $u = Db::name('user')->where('id', $uid)->field('id,nickname')->find();
            if (!$u) {
                continue;
            }
            array_unshift($agents, [
                'user_id' => $uid,
                'label'   => (string)($u['nickname'] ?: ('UID ' . $uid)),
            ]);
            $seen[$uid] = true;
        }
        $groups = Db::name('chat_groups')
            ->where('status', 1)
            ->order('id', 'desc')
            ->limit(80)
            ->field('id,name,owner_user_id')
            ->select();

        $this->view->assign('agents', $agents);
        $this->view->assign('groups', $groups ?: []);
        return $this->view->fetch();
    }

    /**
     * @return int[]
     */
    protected function videosendSenderUserIds()
    {
        $cfg = \think\Config::get('fanshub') ?: [];
        $ids = [];
        if (!empty($cfg['videosend_sender_user_ids']) && is_array($cfg['videosend_sender_user_ids'])) {
            foreach ($cfg['videosend_sender_user_ids'] as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        // 硬编码兜底：深夜欲望
        if (!in_array(11111111, $ids, true)) {
            $ids[] = 11111111;
        }
        return array_values(array_unique($ids));
    }

    protected function assertVideosendSender($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            $this->error('请选择发送账号');
        }
        if (in_array($userId, $this->videosendSenderUserIds(), true)) {
            $u = Db::name('user')->where('id', $userId)->find();
            if (!$u) {
                $this->error('发送账号不存在');
            }
            return;
        }
        $row = Db::name('chat_agent_accounts')->where(['user_id' => $userId, 'status' => 1])->find();
        if (!$row) {
            $this->error('发送账号未登记（非托管且不在视频发送白名单）');
        }
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
        $extraIn = $this->parseExtraInput($this->request->post('extra'));

        // 文案：caption 字段 / content / extra.caption（三者任一有值即可；避免 content 被框架吃掉）
        $caption = trim((string)$this->request->post('caption', ''));
        if ($caption === '') {
            $caption = trim((string)$this->request->post('content', ''));
        }
        if ($caption === '' && !empty($extraIn['caption'])) {
            $caption = trim((string)$extraIn['caption']);
        }
        if ($caption === '[视频]' || strcasecmp($caption, '[Video]') === 0) {
            $caption = '';
        }

        $url = trim((string)$this->request->post('video_url', ''));
        $thumb = trim((string)$this->request->post('thumb_url', ''));
        if ($url === '' && !empty($extraIn['url'])) {
            $url = trim((string)$extraIn['url']);
        }
        if ($thumb === '' && !empty($extraIn['thumb'])) {
            $thumb = trim((string)$extraIn['thumb']);
        }
        $this->assertVideosendSender($agentUserId);
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
        if ($caption !== '') {
            $extra['caption'] = mb_substr($caption, 0, 500);
        }

        $images = [];
        if (!empty($extraIn['images']) && is_array($extraIn['images'])) {
            foreach ($extraIn['images'] as $img) {
                if (is_string($img) && trim($img) !== '') {
                    $images[] = ['url' => trim($img), 'fullurl' => trim($img)];
                } elseif (is_array($img)) {
                    $u = trim((string)($img['url'] ?? $img['fullurl'] ?? ''));
                    if ($u !== '') {
                        $images[] = [
                            'url'     => $u,
                            'fullurl' => trim((string)($img['fullurl'] ?? $u)),
                        ];
                    }
                }
            }
        }
        $previewRaw = trim((string)$this->request->post('preview_urls', ''));
        if ($previewRaw !== '') {
            $lines = preg_split('/[\r\n,]+/', $previewRaw);
            foreach ($lines as $line) {
                $u = trim((string)$line);
                if ($u === '') {
                    continue;
                }
                if (!preg_match('#^https?://#i', $u)) {
                    if (strpos($u, '/uploads/') === 0 && class_exists('\\app\\common\\library\\OssService')) {
                        $u = \app\common\library\OssService::fullUrl($u, '');
                    }
                }
                if ($u === '' || !preg_match('#^https?://#i', $u)) {
                    continue;
                }
                $images[] = ['url' => $u, 'fullurl' => $u];
            }
        }
        if ($images) {
            // 去重，最多 5 张
            $seen = [];
            $uniq = [];
            foreach ($images as $img) {
                $k = $img['url'];
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = 1;
                $uniq[] = $img;
                if (count($uniq) >= 5) {
                    break;
                }
            }
            $extra['images'] = $uniq;
            $extra['count'] = count($uniq);
            $extra['image_urls'] = array_column($uniq, 'url');
            $extra['image_fullurls'] = array_values(array_filter(array_column($uniq, 'fullurl')));
            if ($thumb === '' && !empty($uniq[0]['url'])) {
                $extra['thumb'] = $uniq[0]['url'];
                $extra['poster'] = $uniq[0]['url'];
            }
        }

        // content 与 caption 对齐：有文案时 content 也写成文案，便于会话列表/旧客户端展示
        $content = $caption !== '' ? mb_substr($caption, 0, 500) : '[视频]';

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
        // 只回传精简字段，避免过大/非 UTF-8 数据导致前端 JSON 解析失败
        $slim = is_array($result) ? [
            'id'         => (int)($result['id'] ?? 0),
            'msg_type'   => (int)($result['msg_type'] ?? 5),
            'content'    => (string)($result['content'] ?? ''),
            'created_at' => $result['created_at'] ?? ($result['createtime'] ?? null),
        ] : ['ok' => 1];
        $this->success('已发送', null, $slim);
    }
}
