<?php
/**
 * 简易 HTTP 桥：
 * - 用户只读：会话列表 / 历史（token 鉴权）→ 减轻 WS Worker 压力
 * - 后台代聊：发私聊/群聊/红包（admin_key）
 * 监听: http://0.0.0.0:7273
 *
 * POST /im/conversations  {token, limit?}
 * POST /im/history        {token, conversation_type, conversation_id|group_id|to_user_id, before_id?, limit?}
 * GET  /health
 * POST /agent/*           admin_key
 */

use Im\Http\UserReadApi;
use Im\Service\GroupService;
use Im\Service\MessageService;
use Im\Service\RedPacketService;
use Im\Support\Db;
use Im\Support\NotifyPublisher;
use Im\Support\RedisClient;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

$cfg = require __DIR__ . '/config/app.php';
$GLOBALS['im_cfg'] = $cfg;
Db::init($cfg['db']);
RedisClient::init($cfg['redis']);

$adminKey = $cfg['admin_bridge']['key'] ?? 'change-me-im-admin';
$httpCount = (int)($cfg['http_api']['count'] ?? ((PHP_OS_FAMILY === 'Windows') ? 1 : 4));

$http = new Worker('http://0.0.0.0:7273');
$http->count = max(1, $httpCount);
$http->name = 'FansHubIM-HttpApi';

$http->onMessage = function (TcpConnection $connection, Request $request) use ($cfg, $adminKey) {
    $path = parse_url($request->uri(), PHP_URL_PATH) ?: '/';
    $method = strtoupper($request->method());

    // CORS：H5 与 7273 跨端口
    if ($method === 'OPTIONS') {
        $connection->send(corsResponse(204, ''));
        return;
    }

    if ($path === '/health') {
        $connection->send(corsJson(200, ['ok' => true]));
        return;
    }

    $body = json_decode($request->rawBody(), true);
    if (!is_array($body)) {
        $body = $request->post();
    }
    if (!is_array($body)) {
        $body = [];
    }

    // -------- 用户只读 API（会员 token，无需 admin_key）--------
    if (strpos($path, '/im/') === 0) {
        try {
            $token = (string)($body['token'] ?? $request->header('x-fans-token') ?? $request->get('token') ?? '');
            $api = new UserReadApi($cfg);
            $uid = $api->userIdByToken($token);
            if ($uid <= 0) {
                $connection->send(corsJson(401, ['message' => 'unauthorized']));
                return;
            }
            if ($path === '/im/conversations' && $method === 'POST') {
                $data = $api->conversations($uid, (int)($body['limit'] ?? 50));
                $connection->send(corsJson(200, array_merge(['code' => 1], $data)));
                return;
            }
            if ($path === '/im/history' && $method === 'POST') {
                $data = $api->history($uid, $body);
                $connection->send(corsJson(200, array_merge(['code' => 1], $data)));
                return;
            }
            $connection->send(corsJson(404, ['message' => 'not found']));
        } catch (\Throwable $e) {
            $connection->send(corsJson(400, ['message' => $e->getMessage()]));
        }
        return;
    }

    $key = (string)($body['admin_key'] ?? $request->header('x-im-admin-key') ?? '');
    if (!hash_equals((string)$adminKey, $key)) {
        $connection->send(corsJson(403, ['message' => 'forbidden']));
        return;
    }

    $messages = new MessageService();
    $groups = new GroupService();
    $redPackets = new RedPacketService($cfg, $messages, $groups);

    try {
        if ($path === '/agent/send_private' && $method === 'POST') {
            assertAgent((int)$body['agent_user_id'], (int)($body['admin_id'] ?? 0));
            $extra = parseExtra($body['extra'] ?? null);
            $msg = $messages->sendPrivate(
                (int)$body['agent_user_id'],
                (int)$body['to_user_id'],
                (string)($body['content'] ?? ''),
                (int)($body['msg_type'] ?? 1),
                $extra
            );
            publishNotify('private.message', $msg);
            $connection->send(corsJson(200, ['message' => $msg]));
            return;
        }
        if ($path === '/internal/push' && $method === 'POST') {
            $type = (string)($body['type'] ?? '');
            $msg = $body['message'] ?? null;
            if ($type === '' || !is_array($msg)) {
                $connection->send(corsJson(400, ['message' => 'type and message required']));
                return;
            }
            publishNotify($type, $msg, !empty($body['admin_only']));
            $connection->send(corsJson(200, ['ok' => true]));
            return;
        }
        if ($path === '/agent/send_group' && $method === 'POST') {
            assertAgent((int)$body['agent_user_id'], (int)($body['admin_id'] ?? 0));
            $extra = parseExtra($body['extra'] ?? null);
            $msg = $messages->sendGroup(
                (int)$body['agent_user_id'],
                (int)$body['group_id'],
                (string)($body['content'] ?? ''),
                (int)($body['msg_type'] ?? 1),
                $extra
            );
            publishNotify('group.message', $msg);
            $connection->send(corsJson(200, ['message' => $msg]));
            return;
        }
        if ($path === '/agent/send_redpacket' && $method === 'POST') {
            $sendUid = (int)($body['agent_user_id'] ?? 0);
            // 自动任务 / 托管客服均可：admin_key 已鉴权，只需用户存在
            assertUserExists($sendUid);
            $scopeType = (int)($body['scope_type'] ?? 0);
            if ($scopeType !== 1 && $scopeType !== 2) {
                throw new InvalidArgumentException('invalid scope_type');
            }
            $result = $redPackets->send([
                'from_user_id' => $sendUid,
                'scope_type'   => $scopeType,
                'group_id'     => (int)($body['group_id'] ?? 0),
                'to_user_id'   => (int)($body['to_user_id'] ?? 0),
                'packet_type'  => (int)($body['packet_type'] ?? 2),
                'total_amount' => (float)($body['total_amount'] ?? 0),
                'total_count'  => (int)($body['total_count'] ?? 1),
                'blessing'     => (string)($body['blessing'] ?? '恭喜发财'),
                'mine_digit'   => (int)($body['mine_digit'] ?? 0),
                'skin_id'      => (int)($body['skin_id'] ?? 0),
            ]);
            $msg = $result['message'] ?? null;
            if (is_array($msg)) {
                $type = $scopeType === 2 ? 'group.message' : 'private.message';
                publishNotify($type, $msg);
            }
            $connection->send(corsJson(200, $result));
            return;
        }
        if ($path === '/agent/grab_redpacket' && $method === 'POST') {
            $agentUid = (int)($body['agent_user_id'] ?? 0);
            $packetId = (int)($body['packet_id'] ?? 0);
            if ($agentUid <= 0 || $packetId <= 0) {
                throw new InvalidArgumentException('agent_user_id and packet_id required');
            }
            // 抢包机器人只需是合法用户（admin_key 已鉴权），不必登记为托管客服
            assertUserExists($agentUid);
            $result = $redPackets->grab($packetId, $agentUid);
            $packet = $result['packet'] ?? null;
            if (is_array($packet)) {
                $event = [
                    'packet_id'  => $packetId,
                    'grab'       => $result,
                    'by_user_id' => $agentUid,
                ];
                if ((int)($packet['scope_type'] ?? 0) === 2) {
                    $uids = $groups->onlineMemberIds((int)$packet['group_id']);
                    if ($uids) {
                        \Im\Support\PushBus::toUsers($uids, 'redpacket.update', $event);
                    }
                } else {
                    \Im\Support\PushBus::toUsers(
                        [(int)$packet['from_user_id'], (int)$packet['to_user_id']],
                        'redpacket.update',
                        $event
                    );
                }
            }
            $connection->send(corsJson(200, $result));
            return;
        }
        if ($path === '/agent/settle_packet' && $method === 'POST') {
            $packetId = (int)($body['packet_id'] ?? 0);
            $result = $redPackets->adminSettle($packetId);
            $connection->send(corsJson(200, $result));
            return;
        }
        if ($path === '/agent/refund_packet' && $method === 'POST') {
            $packetId = (int)($body['packet_id'] ?? 0);
            $force = !empty($body['force']);
            $result = $redPackets->adminRefund($packetId, $force);
            $connection->send(corsJson(200, $result));
            return;
        }
        if ($path === '/agent/close_packet' && $method === 'POST') {
            $packetId = (int)($body['packet_id'] ?? 0);
            $result = $redPackets->adminClose($packetId);
            $connection->send(corsJson(200, $result));
            return;
        }
        $connection->send(corsJson(404, ['message' => 'not found']));
    } catch (\Throwable $e) {
        $connection->send(corsJson(400, ['message' => $e->getMessage()]));
    }
};

function parseExtra($extra)
{
    if (is_array($extra)) {
        return $extra;
    }
    if (is_string($extra) && $extra !== '') {
        $decoded = json_decode($extra, true);
        return is_array($decoded) ? $decoded : null;
    }
    return null;
}

function corsHeaders()
{
    return [
        'Content-Type'                => 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods'=> 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers'=> 'Content-Type, X-Fans-Token, X-Im-Admin-Key',
    ];
}

function corsJson($code, array $data)
{
    return new Response((int)$code, corsHeaders(), json_encode($data, JSON_UNESCAPED_UNICODE));
}

function corsResponse($code, $body)
{
    $headers = corsHeaders();
    $headers['Content-Type'] = 'text/plain; charset=utf-8';
    return new Response((int)$code, $headers, (string)$body);
}

function assertUserExists($userId)
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        throw new InvalidArgumentException('agent_user_id required');
    }
    $row = Db::fetch('SELECT id FROM ' . Db::table('user') . ' WHERE id=? LIMIT 1', [$userId]);
    if (!$row) {
        throw new RuntimeException('user not found: ' . $userId);
    }
}

function assertAgent($agentUserId, $adminId = 0)
{
    $agentUserId = (int)$agentUserId;
    if ($agentUserId <= 0) {
        throw new InvalidArgumentException('agent_user_id required');
    }
    $row = Db::fetch(
        'SELECT * FROM ' . Db::table('chat_agent_accounts') . ' WHERE user_id=? AND status=1 LIMIT 1',
        [$agentUserId]
    );
    if (!$row) {
        throw new RuntimeException('agent account not registered');
    }
    if ($adminId > 0 && (int)$row['admin_id'] > 0 && (int)$row['admin_id'] !== $adminId) {
        throw new RuntimeException('agent not bound to this admin');
    }
}

function publishNotify($type, array $message, $adminOnly = false)
{
    NotifyPublisher::publish($type, $message, $adminOnly, $GLOBALS['im_cfg'] ?? null);
}

Worker::runAll();
