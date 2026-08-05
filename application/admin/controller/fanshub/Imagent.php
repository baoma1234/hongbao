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
     * 会话列表
     */
    public function conversations()
    {
        if (!$this->request->isAjax()) {
            $this->error('非法请求');
        }
        $limit = max(1, min(200, (int)$this->request->param('limit', 80)));
        $kw = trim((string)$this->request->param('q', ''));
        $items = [];

        $privates = Db::query(
            "SELECT m.* FROM fa_chat_messages m
             INNER JOIN (
                SELECT conversation_id, MAX(id) AS max_id
                FROM fa_chat_messages
                WHERE conversation_type=1 AND status=1
                GROUP BY conversation_id
             ) t ON m.id = t.max_id
             ORDER BY m.id DESC
             LIMIT {$limit}"
        );
        $peerIds = [];
        foreach ($privates as $m) {
            $peerIds[] = (int)$m['from_user_id'];
            $peerIds[] = (int)$m['to_user_id'];
        }
        $users = $this->usersMap($peerIds);
        foreach ($privates as $m) {
            $a = (int)$m['from_user_id'];
            $b = (int)$m['to_user_id'];
            $title = '私聊 ' . $this->userLabel($users, $a) . ' ↔ ' . $this->userLabel($users, $b);
            if ($kw !== '' && stripos($title, $kw) === false && stripos((string)$m['content'], $kw) === false) {
                continue;
            }
            $items[] = [
                'conversation_type' => 1,
                'conversation_id'   => (string)$m['conversation_id'],
                'group_id'          => 0,
                'peer_a'            => $a,
                'peer_b'            => $b,
                'title'             => $title,
                'last_content'      => (string)$m['content'],
                'last_msg_type'     => (int)$m['msg_type'],
                'updatetime'        => (int)$m['createtime'],
                'last_id'           => (int)$m['id'],
            ];
        }

            $groups = Db::name('chat_groups')->whereIn('status', [1, 3])->order('id', 'desc')->limit($limit)->select();
        foreach ($groups ?: [] as $g) {
            $gid = (int)$g['id'];
            $last = Db::name('chat_messages')
                ->where(['conversation_type' => 2, 'conversation_id' => (string)$gid, 'status' => 1])
                ->order('id', 'desc')
                ->find();
            $title = '群聊 #' . $gid . ' ' . ($g['name'] ?: '');
            $content = $last ? (string)$last['content'] : '(暂无消息)';
            if ($kw !== '' && stripos($title, $kw) === false && stripos($content, $kw) === false) {
                continue;
            }
            $items[] = [
                'conversation_type' => 2,
                'conversation_id'   => (string)$gid,
                'group_id'          => $gid,
                'peer_a'            => 0,
                'peer_b'            => 0,
                'title'             => $title,
                'last_content'      => $content,
                'last_msg_type'     => $last ? (int)$last['msg_type'] : 0,
                'updatetime'        => $last ? (int)$last['createtime'] : (int)($g['updatetime'] ?: $g['createtime']),
                'last_id'           => $last ? (int)$last['id'] : 0,
            ];
        }

        usort($items, function ($x, $y) {
            return ((int)$y['updatetime']) <=> ((int)$x['updatetime']);
        });
        $this->success('ok', null, ['list' => array_slice($items, 0, $limit)]);
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

            $total = $q->count();
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
            $this->success();
        }
        return $this->view->fetch();
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
        ];
    }

    protected function usersMap(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $rows = Db::name('user')->where('id', 'in', $ids)->field('id,nickname,username,mobile')->select();
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
