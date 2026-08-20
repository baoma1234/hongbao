<?php
/**
 * FastAdmin 后台：IM 聊天台（会话模式 + 全量记录 CRUD）
 */

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use think\Db;

class Imagent extends Backend
{
    protected $model = null;
    /** agentdel 与 add 同权，避免未建菜单节点导致 403 */
    protected $noNeedRight = ['agentdel'];

    public function index()
    {
        $agents = Db::name('chat_agent_accounts')->where('status', 1)->order('id', 'desc')->select();
        $skins = [];
        try {
            $skins = Db::name('chat_red_packet_skins')
                ->where('status', 'normal')
                ->order('weigh', 'desc')
                ->order('id', 'desc')
                ->select();
        } catch (\Throwable $e) {
            $skins = [];
        }
        $this->view->assign('agents', $agents ?: []);
        $this->view->assign('rpSkins', $skins ?: []);
        return $this->view->fetch();
    }

    /**
     * 托管账号列表（ajax）
     */
    public function agents()
    {
        if (!$this->request->isAjax()) {
            $this->error('非法请求');
        }
        $list = Db::name('chat_agent_accounts')->order('id', 'desc')->select();
        return json(['total' => count($list), 'rows' => $list ?: []]);
    }

    /**
     * 会话列表：托管账号 fa_chat_user_conversations 收件箱 + 短缓存（避免扫 chat_messages）
     */
    public function conversations()
    {
        if (!$this->request->isAjax()) {
            $this->error('非法请求');
        }
        $limit = max(1, min(120, (int)$this->request->param('limit', 60)));
        $kw = trim((string)$this->request->param('q', ''));
        $sinceId = (int)$this->request->param('since_id', 0);
        $agentIds = array_keys($this->agentUserIdMap());
        if (!$agentIds) {
            $this->success('ok', null, ['list' => [], 'max_last_id' => 0]);
        }

        // 轻量探活：有 since_id 且无搜索时，max(last_msg_id) 未变则直接返回
        if ($sinceId > 0 && $kw === '') {
            try {
                $maxId = (int)Db::name('chat_user_conversations')
                    ->where('user_id', 'in', $agentIds)
                    ->where('last_msg_id', '>', 0)
                    ->max('last_msg_id');
                if ($maxId > 0 && $maxId <= $sinceId) {
                    $this->success('ok', null, [
                        'unchanged'   => 1,
                        'max_last_id' => $sinceId,
                        'list'        => [],
                    ]);
                }
            } catch (\Throwable $e) {
            }
        }

        $cacheKey = 'fh:admin:im:convs:v3:' . md5($limit . '|' . $kw . '|' . implode(',', $agentIds));
        try {
            $cached = \think\Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['list'])) {
                if ($sinceId > 0 && $kw === '' && (int)($cached['max_last_id'] ?? 0) <= $sinceId) {
                    $this->success('ok', null, [
                        'unchanged'   => 1,
                        'max_last_id' => $sinceId,
                        'list'        => [],
                    ]);
                }
                $this->success('ok', null, $cached);
            }
        } catch (\Throwable $e) {
        }

        $items = $this->conversationsFromInbox($agentIds, $limit, $kw);
        if ($items === null) {
            $items = $this->conversationsLegacyScan($agentIds, $limit, $kw);
        }

        usort($items, function ($x, $y) {
            $ud = ((int)($y['updatetime'] ?? 0)) <=> ((int)($x['updatetime'] ?? 0));
            if ($ud !== 0) {
                return $ud;
            }
            return ((int)($y['last_id'] ?? 0)) <=> ((int)($x['last_id'] ?? 0));
        });
        $list = array_slice($items, 0, $limit);
        $maxLast = 0;
        foreach ($list as $it) {
            $maxLast = max($maxLast, (int)($it['last_id'] ?? 0));
        }
        $payload = ['list' => $list, 'max_last_id' => $maxLast];
        try {
            \think\Cache::set($cacheKey, $payload, 8);
        } catch (\Throwable $e) {
        }
        $this->success('ok', null, $payload);
    }

    /**
     * @param int[] $agentIds
     * @return array|null null=表不可用/空，走回退
     */
    protected function conversationsFromInbox(array $agentIds, $limit, $kw)
    {
        $agentIds = array_values(array_filter(array_map('intval', $agentIds)));
        if (!$agentIds) {
            return [];
        }
        // 按托管账号分别走 idx_user_last，再合并去重（比跨用户 GROUP BY 快）
        $per = min(120, max($limit * 2, 40));
        $best = [];
        try {
            foreach ($agentIds as $aid) {
                $part = Db::name('chat_user_conversations')
                    ->where('user_id', $aid)
                    ->where('last_msg_id', '>', 0)
                    ->order('last_msg_id', 'desc')
                    ->limit($per)
                    ->field('conversation_type,conversation_id,peer_user_id,group_id,last_msg_id,last_msg_time')
                    ->select();
                foreach ($part ?: [] as $r) {
                    $ctype = (int)($r['conversation_type'] ?? 0);
                    $cid = (string)($r['conversation_id'] ?? '');
                    if ($cid === '' || ($ctype !== 1 && $ctype !== 2)) {
                        continue;
                    }
                    $key = $ctype . ':' . $cid;
                    $mid = (int)($r['last_msg_id'] ?? 0);
                    if (!isset($best[$key]) || $mid > (int)$best[$key]['last_msg_id']) {
                        $best[$key] = $r;
                    }
                }
            }
        } catch (\Throwable $e) {
            return null;
        }
        if (!$best) {
            return null;
        }
        uasort($best, function ($a, $b) {
            return ((int)($b['last_msg_id'] ?? 0)) <=> ((int)($a['last_msg_id'] ?? 0));
        });
        $rows = array_slice(array_values($best), 0, min(400, max($limit * 4, $limit)));

        $msgIds = [];
        $groupIds = [];
        $peerIds = [];
        foreach ($rows as $r) {
            $mid = (int)($r['last_msg_id'] ?? 0);
            if ($mid > 0) {
                $msgIds[$mid] = true;
            }
            if ((int)($r['conversation_type'] ?? 0) === 2) {
                $gid = (int)($r['group_id'] ?? $r['conversation_id'] ?? 0);
                if ($gid > 0) {
                    $groupIds[$gid] = true;
                }
            } else {
                $pid = (int)($r['peer_user_id'] ?? 0);
                if ($pid > 0) {
                    $peerIds[$pid] = true;
                }
            }
        }
        $msgs = $this->messagesByIds(array_keys($msgIds));
        $groups = [];
        if ($groupIds) {
            $gRows = Db::name('chat_groups')->where('id', 'in', array_keys($groupIds))->field('id,name,avatar,updatetime,createtime')->select();
            foreach ($gRows ?: [] as $g) {
                $groups[(int)$g['id']] = $g;
            }
        }
        foreach ($msgs as $m) {
            $peerIds[(int)$m['from_user_id']] = true;
            $peerIds[(int)$m['to_user_id']] = true;
        }
        $users = $this->usersMap(array_keys($peerIds));
        $agentMap = array_fill_keys($agentIds, true);

        $items = [];
        foreach ($rows as $r) {
            $ctype = (int)($r['conversation_type'] ?? 0);
            $cid = (string)($r['conversation_id'] ?? '');
            $lastId = (int)($r['last_msg_id'] ?? 0);
            $m = $msgs[$lastId] ?? null;
            if ($ctype === 1) {
                $peerId = (int)($r['peer_user_id'] ?? 0);
                if ($peerId <= 0 || isset($agentMap[$peerId])) {
                    if ($m) {
                        $peerId = $this->resolvePrivatePeerId((int)$m['from_user_id'], (int)$m['to_user_id'], $agentMap);
                    }
                }
                if ($peerId <= 0) {
                    continue;
                }
                $nick = $this->userNick($users, $peerId);
                $title = $nick !== '' ? $nick : ('ID' . $peerId);
                $content = $m ? (string)$m['content'] : '';
                if ($kw !== '' && stripos($title, $kw) === false && stripos($content, $kw) === false
                    && stripos((string)$peerId, $kw) === false) {
                    continue;
                }
                $a = $m ? (int)$m['from_user_id'] : 0;
                $b = $m ? (int)$m['to_user_id'] : 0;
                if ($a <= 0 || $b <= 0) {
                    $a = $peerId;
                    $b = (int)$agentIds[0];
                }
                $items[] = [
                    'conversation_type' => 1,
                    'conversation_id'   => $cid !== '' ? $cid : (min($a, $b) . '_' . max($a, $b)),
                    'group_id'          => 0,
                    'peer_a'            => $a,
                    'peer_b'            => $b,
                    'peer_user_id'      => $peerId,
                    'peer_nickname'     => $title,
                    'peer_avatar'       => $this->userAvatar($users, $peerId),
                    'title'             => $title,
                    'last_content'      => $content,
                    'last_msg_type'     => $m ? (int)$m['msg_type'] : 0,
                    'updatetime'        => $m ? (int)$m['createtime'] : (int)($r['last_msg_time'] ?? 0),
                    'last_id'           => $lastId,
                ];
            } elseif ($ctype === 2) {
                $gid = (int)($r['group_id'] ?? $cid);
                if ($gid <= 0) {
                    continue;
                }
                $g = $groups[$gid] ?? null;
                $gName = $g ? trim((string)($g['name'] ?? '')) : '';
                $title = $gName !== '' ? $gName : ('群聊 #' . $gid);
                $content = $m ? (string)$m['content'] : '(暂无消息)';
                if ($kw !== '' && stripos($title, $kw) === false && stripos($content, $kw) === false) {
                    continue;
                }
                $gAvatar = $g ? trim((string)($g['avatar'] ?? '')) : '';
                $items[] = [
                    'conversation_type' => 2,
                    'conversation_id'   => (string)$gid,
                    'group_id'          => $gid,
                    'peer_a'            => 0,
                    'peer_b'            => 0,
                    'peer_user_id'      => 0,
                    'peer_nickname'     => $title,
                    'peer_avatar'       => $this->normalizeAvatarUrl($gAvatar),
                    'title'             => $title,
                    'last_content'      => $content,
                    'last_msg_type'     => $m ? (int)$m['msg_type'] : 0,
                    'updatetime'        => $m ? (int)$m['createtime'] : (int)($r['last_msg_time'] ?? ($g['updatetime'] ?? 0)),
                    'last_id'           => $lastId,
                ];
            }
        }
        return $items;
    }

    /**
     * @param int[] $ids
     * @return array<int,array>
     */
    protected function messagesByIds(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $rows = Db::name('chat_messages')
            ->where('id', 'in', $ids)
            ->where('status', 'in', [1, 2])
            ->field('id,conversation_type,conversation_id,group_id,from_user_id,to_user_id,msg_type,content,createtime')
            ->select();
        $map = [];
        foreach ($rows ?: [] as $r) {
            $map[(int)$r['id']] = $r;
        }
        return $map;
    }

    /**
     * 旧扫库回退（收件箱表缺失或尚未回填时）
     * @param int[] $agentIds
     */
    protected function conversationsLegacyScan(array $agentIds, $limit, $kw)
    {
        $items = [];
        $agentMap = array_fill_keys(array_map('intval', $agentIds), true);
        $fetchLimit = min(300, max($limit * 3, $limit));
        $scanLimit = min(3000, max(800, $fetchLimit * 20));
        $recentPriv = Db::name('chat_messages')
            ->where(['conversation_type' => 1, 'status' => 1])
            ->where(function ($q) use ($agentIds) {
                $q->where('from_user_id', 'in', $agentIds)->whereOr('to_user_id', 'in', $agentIds);
            })
            ->order('id', 'desc')
            ->limit($scanLimit)
            ->select();
        $seenCid = [];
        $privates = [];
        foreach ($recentPriv ?: [] as $m) {
            $cid = (string)($m['conversation_id'] ?? '');
            if ($cid === '' || isset($seenCid[$cid])) {
                continue;
            }
            $seenCid[$cid] = true;
            $privates[] = $m;
            if (count($privates) >= $fetchLimit) {
                break;
            }
        }
        $peerIds = [];
        foreach ($privates as $m) {
            $peerIds[] = (int)$m['from_user_id'];
            $peerIds[] = (int)$m['to_user_id'];
        }
        $users = $this->usersMap($peerIds);
        foreach ($privates as $m) {
            $a = (int)$m['from_user_id'];
            $b = (int)$m['to_user_id'];
            $peerId = $this->resolvePrivatePeerId($a, $b, $agentMap);
            if ($peerId <= 0) {
                continue;
            }
            $nick = $this->userNick($users, $peerId);
            $title = $nick !== '' ? $nick : ('ID' . $peerId);
            if ($kw !== '' && stripos($title, $kw) === false && stripos((string)$m['content'], $kw) === false
                && stripos((string)$peerId, $kw) === false) {
                continue;
            }
            $items[] = [
                'conversation_type' => 1,
                'conversation_id'   => (string)$m['conversation_id'],
                'group_id'          => 0,
                'peer_a'            => $a,
                'peer_b'            => $b,
                'peer_user_id'      => $peerId,
                'peer_nickname'     => $title,
                'peer_avatar'       => $this->userAvatar($users, $peerId),
                'title'             => $title,
                'last_content'      => (string)$m['content'],
                'last_msg_type'     => (int)$m['msg_type'],
                'updatetime'        => (int)$m['createtime'],
                'last_id'           => (int)$m['id'],
            ];
        }

        $groups = Db::name('chat_groups')->whereIn('status', [1, 3])
            ->order('updatetime', 'desc')->order('id', 'desc')->limit($limit)->select();
        foreach ($groups ?: [] as $g) {
            $gid = (int)$g['id'];
            $gName = trim((string)($g['name'] ?? ''));
            $title = $gName !== '' ? $gName : ('群聊 #' . $gid);
            if ($kw !== '' && stripos($title, $kw) === false) {
                continue;
            }
            $items[] = [
                'conversation_type' => 2,
                'conversation_id'   => (string)$gid,
                'group_id'          => $gid,
                'peer_a'            => 0,
                'peer_b'            => 0,
                'peer_user_id'      => 0,
                'peer_nickname'     => $title,
                'peer_avatar'       => $this->normalizeAvatarUrl(trim((string)($g['avatar'] ?? ''))),
                'title'             => $title,
                'last_content'      => '',
                'last_msg_type'     => 0,
                'updatetime'        => (int)($g['updatetime'] ?: $g['createtime']),
                'last_id'           => 0,
            ];
        }
        return $items;
    }

    /**
     * 某会话历史
     */
    public function history()
    {
        if (!$this->request->isAjax()) {
            $this->error('非法请求');
        }
        $ctype = (int)$this->request->param('conversation_type', 1);
        $cid = trim((string)$this->request->param('conversation_id', ''));
        $beforeId = (int)$this->request->param('before_id', 0);
        $limit = max(1, min(100, (int)$this->request->param('limit', 50)));
        $includeDeleted = (int)$this->request->param('include_deleted', 0) === 1;

        if ($cid === '') {
            $this->error('缺少 conversation_id');
        }

        $q = Db::name('chat_messages')
            ->where('conversation_type', $ctype)
            ->where('conversation_id', $cid);
        if (!$includeDeleted) {
            $q->where('status', 'in', [1, 2]);
        }
        if ($beforeId > 0) {
            $q->where('id', '<', $beforeId);
        }
        $rows = $q->order('id', 'desc')->limit($limit)->select();
        $rows = array_reverse($rows ?: []);
        $uids = [];
        foreach ($rows as $r) {
            $uids[] = (int)$r['from_user_id'];
            $uids[] = (int)$r['to_user_id'];
        }
        $users = $this->usersMap($uids);
        $list = [];
        foreach ($rows as $r) {
            $list[] = $this->formatMessage($r, $users);
        }
        $this->success('ok', null, ['list' => $list]);
    }

    /**
     * 全量消息表（bootstrapTable）
     */
    public function messages()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            $offset = max(0, (int)$this->request->get('offset', 0));
            $limit = max(1, min(100, (int)$this->request->get('limit', 20)));
            $sort = (string)$this->request->get('sort', 'id');
            $order = strtolower((string)$this->request->get('order', 'desc')) === 'asc' ? 'asc' : 'desc';
            $allowedSort = ['id', 'createtime', 'from_user_id', 'to_user_id', 'conversation_type', 'status'];
            if (!in_array($sort, $allowedSort, true)) {
                $sort = 'id';
            }

            $filter = $this->request->get('filter');
            $op = $this->request->get('op');
            $filterArr = is_string($filter) ? (json_decode($filter, true) ?: []) : (is_array($filter) ? $filter : []);
            $opArr = is_string($op) ? (json_decode($op, true) ?: []) : (is_array($op) ? $op : []);

            $q = Db::name('chat_messages');
            foreach ($filterArr as $field => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $field = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$field);
                if ($field === '') {
                    continue;
                }
                $operator = isset($opArr[$field]) ? strtoupper((string)$opArr[$field]) : '=';
                if ($operator === 'LIKE') {
                    $q->where($field, 'like', '%' . $value . '%');
                } elseif ($operator === 'BETWEEN' && is_string($value) && strpos($value, ',') !== false) {
                    $parts = explode(',', $value, 2);
                    $q->where($field, 'between', [trim($parts[0]), trim($parts[1])]);
                } else {
                    $q->where($field, $value);
                }
            }

            $total = null;
            if (!$filterArr) {
                try {
                    $cached = \think\Cache::get('fh:admin:im:msg_total');
                    if ($cached !== false && $cached !== null) {
                        $total = (int)$cached;
                    }
                } catch (\Throwable $e) {
                }
            }
            if ($total === null) {
                $total = (int)$q->count();
                if (!$filterArr) {
                    try {
                        \think\Cache::set('fh:admin:im:msg_total', $total, 30);
                    } catch (\Throwable $e) {
                    }
                }
            }
            $rows = Db::name('chat_messages');
            foreach ($filterArr as $field => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $field = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$field);
                if ($field === '') {
                    continue;
                }
                $operator = isset($opArr[$field]) ? strtoupper((string)$opArr[$field]) : '=';
                if ($operator === 'LIKE') {
                    $rows->where($field, 'like', '%' . $value . '%');
                } elseif ($operator === 'BETWEEN' && is_string($value) && strpos($value, ',') !== false) {
                    $parts = explode(',', $value, 2);
                    $rows->where($field, 'between', [trim($parts[0]), trim($parts[1])]);
                } else {
                    $rows->where($field, $value);
                }
            }
            $rows = $rows->order($sort, $order)->limit($offset, $limit)->select();
            $uids = [];
            foreach ($rows ?: [] as $r) {
                $uids[] = (int)$r['from_user_id'];
                $uids[] = (int)$r['to_user_id'];
            }
            $users = $this->usersMap($uids);
            $list = [];
            foreach ($rows ?: [] as $r) {
                $item = $this->formatMessage($r, $users);
                $item['from_label'] = $this->userLabel($users, (int)$r['from_user_id']);
                $item['to_label'] = (int)$r['to_user_id'] > 0 ? $this->userLabel($users, (int)$r['to_user_id']) : '-';
                $list[] = $item;
            }
            return json(['total' => $total, 'rows' => $list]);
        }
        return $this->view->fetch('messages');
    }

    /**
     * 代发（文本/图片/视频/表情包）
     */
    public function send()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $ctype = (int)$this->request->post('conversation_type', 1);
        $agentUserId = (int)$this->request->post('agent_user_id');
        $msgType = (int)$this->request->post('msg_type', 1);
        if (!in_array($msgType, [1, 4, 5, 6, 7], true)) {
            $msgType = 1;
        }
        $content = trim((string)$this->request->post('content', ''));
        $extra = $this->parseExtraInput($this->request->post('extra'));
        if ($agentUserId <= 0) {
            $this->error('请选择托管账号');
        }
        if ($msgType === 1 && $content === '') {
            $this->error('内容不能为空');
        }
        if (in_array($msgType, [4, 5, 6, 7], true) && (empty($extra['url']))) {
            $this->error('缺少媒体地址');
        }
        if ($msgType === 6 && empty($extra['code'])) {
            $this->error('缺少表情代码');
        }
        if ($content === '') {
            if ($msgType === 4) {
                $content = '[图片]';
            } elseif ($msgType === 5) {
                $content = '[视频]';
            } elseif ($msgType === 7) {
                $content = '[文件]' . ($extra['name'] ?? '');
            } elseif ($msgType === 6) {
                $content = '[' . ($extra['code'] ?? '表情') . ']';
            }
        }

        $payload = [
            'agent_user_id' => $agentUserId,
            'content'       => $content,
            'msg_type'      => $msgType,
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
            if ($toUserId <= 0) {
                $cid = trim((string)$this->request->post('conversation_id', ''));
                if (preg_match('/^(\d+)_(\d+)$/', $cid, $m)) {
                    $a = (int)$m[1];
                    $b = (int)$m[2];
                    $toUserId = ($a === $agentUserId) ? $b : $a;
                }
            }
            if ($toUserId <= 0 || $toUserId === $agentUserId) {
                $this->error('对方ID无效');
            }
            $payload['to_user_id'] = $toUserId;
            $result = $this->callBridge('/agent/send_private', $payload);
            $this->publishOutgoingMessage($result);
        }
        $this->success('已发送', null, $result);
    }

    /**
     * 代发红包（扣托管账号余额）
     */
    public function sendredpacket()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $ctype = (int)$this->request->post('conversation_type', 1);
        $agentUserId = (int)$this->request->post('agent_user_id');
        $amount = round((float)$this->request->post('total_amount', 0), 2);
        $count = (int)$this->request->post('total_count', 1);
        $packetType = (int)$this->request->post('packet_type', 2);
        $blessing = trim((string)$this->request->post('blessing', '恭喜发财'));
        $mineDigit = (int)$this->request->post('mine_digit', 0);
        $skinId = (int)$this->request->post('skin_id', 0);
        if ($agentUserId <= 0) {
            $this->error('请选择托管账号');
        }
        if ($amount <= 0) {
            $this->error('红包金额无效');
        }
        if ($count <= 0) {
            $this->error('红包个数无效');
        }
        if (!in_array($packetType, [1, 2, 3], true)) {
            $packetType = 2;
        }
        if ($packetType === 3 && ($mineDigit < 0 || $mineDigit > 9)) {
            $this->error('雷号须为 0-9');
        }
        $payload = [
            'agent_user_id' => $agentUserId,
            'scope_type'    => $ctype === 2 ? 2 : 1,
            'packet_type'   => $packetType,
            'total_amount'  => $amount,
            'total_count'   => $ctype === 1 ? 1 : $count,
            'blessing'      => $blessing !== '' ? $blessing : '恭喜发财',
            'mine_digit'    => $packetType === 3 ? $mineDigit : 0,
            'skin_id'       => $skinId,
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
        } else {
            $toUserId = (int)$this->request->post('to_user_id');
            if ($toUserId <= 0) {
                $cid = trim((string)$this->request->post('conversation_id', ''));
                if (preg_match('/^(\d+)_(\d+)$/', $cid, $m)) {
                    $a = (int)$m[1];
                    $b = (int)$m[2];
                    $toUserId = ($a === $agentUserId) ? $b : $a;
                }
            }
            if ($toUserId <= 0 || $toUserId === $agentUserId) {
                $this->error('对方ID无效');
            }
            $payload['to_user_id'] = $toUserId;
            $payload['total_count'] = 1;
        }
        $result = $this->callBridge('/agent/send_redpacket', $payload);
        $this->publishOutgoingMessage($result);
        $this->success('红包已发送', null, $result);
    }

    /**
     * 撤回消息
     */
    public function recall()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            $this->error('缺少消息ID');
        }
        $row = Db::name('chat_messages')->where('id', $id)->find();
        if (!$row) {
            $this->error('消息不存在');
        }
        if ((int)$row['status'] === 2) {
            $this->success('已撤回', null, $this->formatMessage($row));
        }
        if ((int)$row['status'] !== 1) {
            $this->error('该消息无法撤回');
        }
        Db::name('chat_messages')->where('id', $id)->update(['status' => 2]);
        $row['status'] = 2;
        $row['content'] = '[已撤回]';
        $payload = $this->formatMessage($row);
        $this->publishImNotify('message.recalled', $payload, false);
        $this->publishImNotify('message.recalled', $payload, true);
        $this->success('已撤回', null, $payload);
    }

    protected function parseExtraInput($raw)
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * 编辑消息（改）
     */
    public function edit($ids = null)
    {
        $id = (int)($ids ?: $this->request->param('ids'));
        $row = Db::name('chat_messages')->where('id', $id)->find();
        if (!$row) {
            $this->error('消息不存在');
        }
        if ($this->request->isPost()) {
            $content = trim((string)$this->request->post('content', ''));
            $status = $this->request->post('status', null);
            $data = [];
            if ($this->request->post('content') !== null) {
                if ($content === '') {
                    $this->error('内容不能为空');
                }
                if (mb_strlen($content) > 2000) {
                    $this->error('内容过长');
                }
                $data['content'] = $content;
            }
            if ($status !== null && $status !== '') {
                $st = (int)$status;
                if (!in_array($st, [1, 2, 3], true)) {
                    $this->error('状态无效');
                }
                $data['status'] = $st;
            }
            if (!$data) {
                $this->error('没有可更新的内容');
            }
            Db::name('chat_messages')->where('id', $id)->update($data);
            $fresh = Db::name('chat_messages')->where('id', $id)->find();
            $this->success('已保存', null, $this->formatMessage($fresh, $this->usersMap([
                (int)$fresh['from_user_id'],
                (int)$fresh['to_user_id'],
            ])));
        }
        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    /**
     * 删除消息（软删 status=3）
     */
    public function del($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $ids = $ids ?: $this->request->post('ids');
        if (is_array($ids)) {
            $idList = array_map('intval', $ids);
        } else {
            $idList = array_filter(array_map('intval', explode(',', (string)$ids)));
        }
        if (!$idList) {
            $this->error('请选择消息');
        }
        $n = Db::name('chat_messages')->where('id', 'in', $idList)->update(['status' => 3]);
        $this->success('已删除 ' . $n . ' 条', null, ['count' => $n]);
    }

    /**
     * 恢复已删消息
     */
    public function restore($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $ids = $ids ?: $this->request->post('ids');
        if (is_array($ids)) {
            $idList = array_map('intval', $ids);
        } else {
            $idList = array_filter(array_map('intval', explode(',', (string)$ids)));
        }
        if (!$idList) {
            $this->error('请选择消息');
        }
        $n = Db::name('chat_messages')->where('id', 'in', $idList)->update(['status' => 1]);
        $this->success('已恢复 ' . $n . ' 条', null, ['count' => $n]);
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            $userId = (int)($params['user_id'] ?? 0);
            if ($userId <= 0) {
                $this->error('请填写托管会员ID');
            }
            $exists = Db::name('user')->where('id', $userId)->find();
            if (!$exists) {
                $this->error('会员ID不存在');
            }
            $dup = Db::name('chat_agent_accounts')->where('user_id', $userId)->find();
            if ($dup) {
                if ((int)$dup['status'] === 1) {
                    $this->error('该会员已是托管账号');
                }
                Db::name('chat_agent_accounts')->where('id', (int)$dup['id'])->update([
                    'admin_id'     => (int)($params['admin_id'] ?? $this->auth->id),
                    'label'        => trim((string)($params['label'] ?? '')),
                    'scope'        => in_array(($params['scope'] ?? 'all'), ['all', 'private', 'group'], true) ? $params['scope'] : 'all',
                    'friend_reply' => mb_substr(trim((string)($params['friend_reply'] ?? '')), 0, 500),
                    'status'       => 1,
                    'updatetime'   => time(),
                ]);
                $this->flushAgentCache();
                $this->success('已重新启用该托管账号');
            }
            $now = time();
            try {
                Db::name('chat_agent_accounts')->insert([
                    'user_id'      => $userId,
                    'admin_id'     => (int)($params['admin_id'] ?? $this->auth->id),
                    'label'        => trim((string)($params['label'] ?? '')),
                    'scope'        => in_array(($params['scope'] ?? 'all'), ['all', 'private', 'group'], true) ? $params['scope'] : 'all',
                    'friend_reply' => mb_substr(trim((string)($params['friend_reply'] ?? '')), 0, 500),
                    'status'       => 1,
                    'createtime'   => $now,
                    'updatetime'   => $now,
                ]);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }
            $this->flushAgentCache();
            $this->success();
        }
        $agents = Db::name('chat_agent_accounts')->order('status', 'desc')->order('id', 'desc')->select();
        $this->view->assign('agents', $agents ?: []);
        return $this->view->fetch();
    }

    /**
     * 删除/停用托管账号（从代聊下拉移除；不删会员本身）
     */
    public function agentdel()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        if (!$this->auth->check('fanshub/imagent/add') && !$this->auth->isSuperAdmin()) {
            $this->error(__('You have no permission'));
        }
        $id = (int)$this->request->post('id', 0);
        $hard = (int)$this->request->post('hard', 0) === 1;
        if ($id <= 0) {
            $ids = $this->request->post('ids');
            if (is_array($ids)) {
                $id = (int)($ids[0] ?? 0);
            } else {
                $id = (int)explode(',', (string)$ids)[0];
            }
        }
        if ($id <= 0) {
            $this->error('请选择托管账号');
        }
        $row = Db::name('chat_agent_accounts')->where('id', $id)->find();
        if (!$row) {
            $this->error('记录不存在');
        }
        if ($hard) {
            Db::name('chat_agent_accounts')->where('id', $id)->delete();
            $this->flushAgentCache();
            $this->success('已删除托管登记');
        }
        Db::name('chat_agent_accounts')->where('id', $id)->update([
            'status'     => 0,
            'updatetime' => time(),
        ]);
        $this->flushAgentCache();
        $this->success('已停用，不再出现在代聊托管列表');
    }

    protected function flushAgentCache()
    {
        try {
            $cfg = \think\Config::get('fanshub') ?: [];
            $redis = is_array($cfg['redis'] ?? null) ? $cfg['redis'] : [];
            // IM AdminService 短缓存键
            if (class_exists('\\think\\Cache')) {
                \think\Cache::rm('fh:admin:im:agents');
            }
        } catch (\Throwable $e) {
        }
        try {
            $cfg = require dirname(__DIR__, 3) . '/im-server/config/app.php';
            if (!is_array($cfg) || empty($cfg['redis'])) {
                return;
            }
            if (!class_exists('\\Redis')) {
                return;
            }
            $r = new \Redis();
            $host = (string)($cfg['redis']['host'] ?? '127.0.0.1');
            $port = (int)($cfg['redis']['port'] ?? 6379);
            $r->connect($host, $port, 1.5);
            $pass = (string)($cfg['redis']['password'] ?? '');
            if ($pass !== '') {
                $r->auth($pass);
            }
            $db = (int)($cfg['redis']['db'] ?? 0);
            if ($db > 0) {
                $r->select($db);
            }
            $prefix = (string)($cfg['redis']['prefix'] ?? 'im:');
            $r->del($prefix . 'admin:rows');
        } catch (\Throwable $e) {
        }
    }

    /** 兼容旧入口 */
    public function sendprivate()
    {
        if (!$this->request->isPost()) {
            $agents = Db::name('chat_agent_accounts')->where('status', 1)->select();
            $this->view->assign('agents', $agents ?: []);
            return $this->view->fetch();
        }
        return $this->send();
    }

    public function sendgroup()
    {
        if (!$this->request->isPost()) {
            $agents = Db::name('chat_agent_accounts')->where('status', 1)->select();
            $this->view->assign('agents', $agents ?: []);
            return $this->view->fetch();
        }
        return $this->send();
    }

    protected function formatMessage($r, array $users = [])
    {
        $r = is_array($r) ? $r : (array)$r;
        $extra = $r['extra'] ?? null;
        if (is_string($extra) && $extra !== '') {
            $decoded = json_decode($extra, true);
            if (is_array($decoded)) {
                $extra = $decoded;
            }
        }
        return [
            'id'                => (int)$r['id'],
            'msg_id'            => (string)$r['msg_id'],
            'conversation_type' => (int)$r['conversation_type'],
            'conversation_id'   => (string)$r['conversation_id'],
            'group_id'          => (int)$r['group_id'],
            'from_user_id'      => (int)$r['from_user_id'],
            'to_user_id'        => (int)$r['to_user_id'],
            'msg_type'          => (int)$r['msg_type'],
            'content'           => (string)$r['content'],
            'extra'             => $extra,
            'status'            => (int)$r['status'],
            'createtime'        => (int)$r['createtime'],
            'createtime_text'   => !empty($r['createtime']) ? date('Y-m-d H:i:s', (int)$r['createtime']) : '',
            'from_label'        => $this->userLabel($users, (int)$r['from_user_id']),
            'from_avatar'       => $this->userAvatar($users, (int)$r['from_user_id']),
        ];
    }

    protected function usersMap(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $rows = Db::name('user')->where('id', 'in', $ids)->field('id,nickname,username,mobile,avatar')->select();
        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int)$row['id']] = $row;
        }
        return $map;
    }

    protected function userLabel(array $users, $uid)
    {
        $uid = (int)$uid;
        if ($uid <= 0) {
            return '-';
        }
        $u = $users[$uid] ?? null;
        if (!$u) {
            return 'ID' . $uid;
        }
        $nick = trim((string)($u['nickname'] ?: $u['username'] ?: ''));
        $mob = (string)($u['mobile'] ?? '');
        if ($nick !== '') {
            return $nick . '(ID' . $uid . ')';
        }
        if ($mob !== '') {
            $mask = strlen($mob) >= 7 ? (substr($mob, 0, 3) . '****' . substr($mob, -4)) : $mob;
            return $mask . '(ID' . $uid . ')';
        }
        return 'ID' . $uid;
    }

    protected function userAvatar(array $users, $uid)
    {
        $uid = (int)$uid;
        $default = 'https://888jhdhifhbchashjdl.oss-accelerate.aliyuncs.com/uploads/brand/default-avatar.png';
        if ($uid <= 0) {
            return $default;
        }
        $u = $users[$uid] ?? null;
        return $this->normalizeAvatarUrl(trim((string)($u['avatar'] ?? '')), $default);
    }

    protected function userNick(array $users, $uid)
    {
        $uid = (int)$uid;
        if ($uid <= 0) {
            return '';
        }
        $u = $users[$uid] ?? null;
        if (!$u) {
            return 'ID' . $uid;
        }
        $nick = trim((string)($u['nickname'] ?: $u['username'] ?: ''));
        if ($nick !== '') {
            return $nick;
        }
        $mob = (string)($u['mobile'] ?? '');
        if ($mob !== '') {
            return strlen($mob) >= 7 ? (substr($mob, 0, 3) . '****' . substr($mob, -4)) : $mob;
        }
        return 'ID' . $uid;
    }

    /** @return array<int,true> */
    protected function agentUserIdMap()
    {
        static $map = null;
        if (is_array($map)) {
            return $map;
        }
        $map = [];
        try {
            $rows = Db::name('chat_agent_accounts')->where('status', 1)->column('user_id');
            foreach ($rows ?: [] as $uid) {
                $uid = (int)$uid;
                if ($uid > 0) {
                    $map[$uid] = true;
                }
            }
        } catch (\Throwable $e) {
            $map = [];
        }
        return $map;
    }

    /**
     * 私聊展示对方：优先非托管账号；若双方都是/都不是托管，取另一侧中非「最新消息发送方」优先用户侧
     */
    protected function resolvePrivatePeerId($a, $b, array $agentIds)
    {
        $a = (int)$a;
        $b = (int)$b;
        $aAgent = isset($agentIds[$a]);
        $bAgent = isset($agentIds[$b]);
        if ($aAgent && !$bAgent) {
            return $b;
        }
        if ($bAgent && !$aAgent) {
            return $a;
        }
        // 双方同为托管或都不是：取较小 id 以外的「对方」仍需一个展示位，优先非发送方意义不大，取较大 id
        return max($a, $b);
    }

    protected function normalizeAvatarUrl($avatar, $default = '')
    {
        $default = $default !== ''
            ? $default
            : 'https://888jhdhifhbchashjdl.oss-accelerate.aliyuncs.com/uploads/brand/default-avatar.png';
        $avatar = trim((string)$avatar);
        if ($avatar === '') {
            return $default;
        }
        if (preg_match('#^https?://#i', $avatar) || strpos($avatar, '//') === 0) {
            return $avatar;
        }
        if (class_exists('\\app\\common\\library\\OssService')) {
            try {
                $full = \app\common\library\OssService::fullUrl($avatar, '');
                if (is_string($full) && $full !== '') {
                    return $full;
                }
            } catch (\Throwable $e) {
            }
        }
        if (function_exists('cdnurl')) {
            return (string)cdnurl($avatar, true);
        }
        return $avatar;
    }

    protected function callBridge($path, array $body)
    {
        $cfg = \think\Config::get('fanshub') ?: [];
        $im = isset($cfg['im_admin']) && is_array($cfg['im_admin']) ? $cfg['im_admin'] : [];
        $base = rtrim((string)($im['bridge_url'] ?? 'http://127.0.0.1:17273'), '/');
        $key = (string)($im['bridge_key'] ?? 'change-me-im-admin');
        $body['admin_key'] = $key;
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno) {
            return $this->sendDirectFallback($path, $body);
        }
        $json = json_decode((string)$raw, true);
        if ($code >= 400) {
            $msg = 'send failed';
            if (is_array($json) && isset($json['message'])) {
                $msg = is_string($json['message']) ? $json['message'] : json_encode($json['message'], JSON_UNESCAPED_UNICODE);
            }
            $this->error($msg);
        }
        return is_array($json) ? $json : ['raw' => (string)$raw];
    }

    protected function sendDirectFallback($path, array $body)
    {
        if (strpos($path, 'send_redpacket') !== false) {
            $this->error('发红包需要 IM 桥接服务（start_admin.php），当前不可达');
        }
        $agent = (int)($body['agent_user_id'] ?? 0);
        $content = trim((string)($body['content'] ?? ''));
        $msgType = (int)($body['msg_type'] ?? 1);
        if (!in_array($msgType, [1, 4, 5, 6, 7], true)) {
            $msgType = 1;
        }
        $extra = $body['extra'] ?? null;
        if (is_array($extra)) {
            $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE);
        } elseif (is_string($extra) && $extra !== '') {
            $extraJson = $extra;
        } else {
            $extraJson = null;
        }
        $row = Db::name('chat_agent_accounts')->where(['user_id' => $agent, 'status' => 1])->find();
        if (!$row) {
            $this->error('托管账号未登记，且 IM 桥接不可达');
        }
        $now = time();
        $msgId = sprintf('m%s%04d', date('YmdHis'), random_int(0, 9999));
        if (strpos($path, 'send_group') !== false) {
            $gid = (int)($body['group_id'] ?? 0);
            $member = Db::name('chat_group_members')->where(['group_id' => $gid, 'user_id' => $agent, 'status' => 1])->find();
            if (!$member) {
                $this->error('托管账号不在该群，且桥接不可达');
            }
            $id = Db::name('chat_messages')->insertGetId([
                'msg_id'            => $msgId,
                'conversation_type' => 2,
                'conversation_id'   => (string)$gid,
                'group_id'          => $gid,
                'from_user_id'      => $agent,
                'to_user_id'        => 0,
                'msg_type'          => $msgType,
                'content'           => mb_substr($content, 0, 2000),
                'extra'             => $extraJson,
                'status'            => 1,
                'createtime'        => $now,
            ]);
        } else {
            $to = (int)($body['to_user_id'] ?? 0);
            $a = min($agent, $to);
            $b = max($agent, $to);
            $cid = $a . '_' . $b;
            $id = Db::name('chat_messages')->insertGetId([
                'msg_id'            => $msgId,
                'conversation_type' => 1,
                'conversation_id'   => $cid,
                'group_id'          => 0,
                'from_user_id'      => $agent,
                'to_user_id'        => $to,
                'msg_type'          => $msgType,
                'content'           => mb_substr($content, 0, 2000),
                'extra'             => $extraJson,
                'status'            => 1,
                'createtime'        => $now,
            ]);
        }
        $msg = Db::name('chat_messages')->where('id', $id)->find();
        $result = ['message' => $msg, 'fallback' => true, 'hint' => 'IM桥接未启动，已直写数据库'];
        $this->publishOutgoingMessage($result);
        return $result;
    }

    /**
     * 代发/撤回后写入 IM notify_queue，由 WS 服务扇出给在线用户
     */
    protected function publishOutgoingMessage($result)
    {
        if (!is_array($result)) {
            return;
        }
        if (empty($result['fallback'])) {
            return;
        }
        $msg = $result['message'] ?? null;
        if (!$msg) {
            return;
        }
        $formatted = $this->formatMessage($msg);
        $type = (int)($formatted['conversation_type'] ?? 0) === 2 ? 'group.message' : 'private.message';
        $this->publishImNotify($type, $formatted, false);
    }

    protected function publishImNotify($type, array $message, $adminOnly = false)
    {
        $cfg = \think\Config::get('fanshub') ?: [];
        $im = isset($cfg['im_admin']) && is_array($cfg['im_admin']) ? $cfg['im_admin'] : [];
        $base = rtrim((string)($im['bridge_url'] ?? 'http://127.0.0.1:17273'), '/');
        $key = (string)($im['bridge_key'] ?? 'change-me-im-admin');
        $body = json_encode([
            'admin_key'  => $key,
            'type'       => (string)$type,
            'message'    => $message,
            'admin_only' => $adminOnly ? 1 : 0,
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init($base . '/internal/push');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
