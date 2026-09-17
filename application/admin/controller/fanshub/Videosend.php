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
        // 发送账号完全固定：仅 videosend 白名单（深夜欲望），不混入托管号
        $agents = [];
        foreach ($this->videosendSenderUserIds() as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $u = Db::name('user')->where('id', $uid)->field('id,nickname')->find();
            $agents[] = [
                'user_id' => $uid,
                'label'   => $u ? (string)($u['nickname'] ?: '深夜欲望') : '深夜欲望',
            ];
        }
        // 仅「新成员可见历史」的群（当前：70/71/72）；含禁言群 status=3
        $groups = Db::name('chat_groups')
            ->where('new_member_see_history', 1)
            ->whereIn('status', [1, 3])
            ->order('id', 'asc')
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
        // 完全固定：仅深夜欲望
        return [11111111];
    }

    protected function assertVideosendSender($userId)
    {
        $userId = (int)$userId;
        if (!in_array($userId, $this->videosendSenderUserIds(), true)) {
            $this->error('发送账号已固定为深夜欲望');
        }
        $u = Db::name('user')->where('id', $userId)->find();
        if (!$u) {
            $this->error('发送账号不存在');
        }
    }

    protected function assertVideosendGroup($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            $this->error('请选择群');
        }
        $g = Db::name('chat_groups')
            ->where('id', $groupId)
            ->where('new_member_see_history', 1)
            ->whereIn('status', [1, 3])
            ->find();
        if (!$g) {
            $this->error('仅可向「新成员可见历史」的群发送');
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
        // 发送账号完全固定，忽略前端篡改
        $agentUserId = 11111111;
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
            // 去重，最多 9 张
            $seen = [];
            $uniq = [];
            foreach ($images as $img) {
                $k = $img['url'];
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = 1;
                $uniq[] = $img;
                if (count($uniq) >= 9) {
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
            $this->assertVideosendGroup($groupId);
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
