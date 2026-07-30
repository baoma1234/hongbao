<?php
/**
 * 简易 HTTP 管理桥：后台用托管账号发私聊/群聊/红包
 * 监听: http://0.0.0.0:7273
 *
 * POST /agent/send_private  {agent_user_id, to_user_id, content, msg_type?, extra?, admin_key}
 * POST /agent/send_group    {agent_user_id, group_id, content, msg_type?, extra?, admin_key}
 * POST /agent/send_redpacket {agent_user_id, scope_type, group_id|to_user_id, packet_type, total_amount, total_count, blessing?, admin_key}
 * POST /agent/grab_redpacket {agent_user_id, packet_id, admin_key}
 * GET  /health
 */

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

$http = new Worker('http://0.0.0.0:7273');
$http->count = 1;
$http->name = 'FansHubIM-AdminBridge';

$http->onMessage = function (TcpConnection $connection, Request $request) use ($cfg, $adminKey) {
    $path = parse_url($request->uri(), PHP_URL_PATH) ?: '/';
    if ($path === '/health') {
        $connection->send(new Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true])));
        return;
    }

    $body = json_decode($request->rawBody(), true);
    if (!is_array($body)) {
        $body = $request->post();
    }
    if (!is_array($body)) {
        $body = [];
    }
    $key = (string)($body['admin_key'] ?? $request->header('x-im-admin-key') ?? '');
    if (!hash_equals((string)$adminKey, $key)) {
        $connection->send(jsonResponse(403, ['message' => 'forbidden']));
        return;
    }

    $messages = new MessageService();
    $groups = new GroupService();
    $redPackets = new RedPacketService($cfg, $messages, $groups);

    try {
        if ($path === '/agent/send_private' && strtoupper($request->method()) === 'POST') {
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
            $connection->send(jsonResponse(200, ['message' => $msg]));
            return;
        }
        if ($path === '/internal/push' && strtoupper($request->method()) === 'POST') {
            $type = (string)($body['type'] ?? '');
            $msg = $body['message'] ?? null;
            if ($type === '' || !is_array($msg)) {
                $connection->send(jsonResponse(400, ['message' => 'type and message required']));
                return;
            }
            publishNotify($type, $msg, !empty($body['admin_only']));
            $connection->send(jsonResponse(200, ['ok' => true]));
            return;
        }
        if ($path === '/agent/send_group' && strtoupper($request->method()) === 'POST') {
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
            $connection->send(jsonResponse(200, ['message' => $msg]));
            return;
        }
        if ($path === '/agent/send_redpacket' && strtoupper($request->method()) === 'POST') {
            assertAgent((int)$body['agent_user_id'], (int)($body['admin_id'] ?? 0));
            $scopeType = (int)($body['scope_type'] ?? 0);
            if ($scopeType !== 1 && $scopeType !== 2) {
                throw new InvalidArgumentException('invalid scope_type');
            }
            $result = $redPackets->send([
                'from_user_id' => (int)$body['agent_user_id'],
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
            $connection->send(jsonResponse(200, $result));
            return;
        }
        if ($path === '/agent/grab_redpacket' && strtoupper($request->method()) === 'POST') {
            $agentUid = (int)($body['agent_user_id'] ?? 0);
            $packetId = (int)($body['packet_id'] ?? 0);
            if ($agentUid <= 0 || $packetId <= 0) {
                throw new InvalidArgumentException('agent_user_id and packet_id required');
            }
            // 抢包机器人只需是合法用户（admin_key 已鉴权），不必登记为托管客服
            $userOk = Db::fetch('SELECT id FROM ' . Db::table('user') . ' WHERE id=? LIMIT 1', [$agentUid]);
            if (!$userOk) {
                throw new RuntimeException('grab user not found');
            }
            $result = $redPackets->grab($packetId, $agentUid);
            $packet = $result['packet'] ?? null;
            if (is_array($packet)) {
                $event = [
                    'packet_id'  => $packetId,
                    'grab'       => $result,
                    'by_user_id' => $agentUid,
                ];
                if ((int)($packet['scope_type'] ?? 0) === 2) {
                    $uids = $groups->memberUserIds((int)$packet['group_id']);
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
            $connection->send(jsonResponse(200, $result));
            return;
        }
        if ($path === '/agent/settle_packet' && strtoupper($request->method()) === 'POST') {
            $packetId = (int)($body['packet_id'] ?? 0);
            $result = $redPackets->adminSettle($packetId);
            $connection->send(jsonResponse(200, $result));
            return;
        }
        if ($path === '/agent/refund_packet' && strtoupper($request->method()) === 'POST') {
            $packetId = (int)($body['packet_id'] ?? 0);
            $force = !empty($body['force']);
            $result = $redPackets->adminRefund($packetId, $force);
            $connection->send(jsonResponse(200, $result));
            return;
        }
        if ($path === '/agent/close_packet' && strtoupper($request->method()) === 'POST') {
            $packetId = (int)($body['packet_id'] ?? 0);
            $result = $redPackets->adminClose($packetId);
            $connection->send(jsonResponse(200, $result));
            return;
        }
        $connection->send(jsonResponse(404, ['message' => 'not found']));
    } catch (\Throwable $e) {
        $connection->send(jsonResponse(400, ['message' => $e->getMessage()]));
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

function jsonResponse($code, array $data)
{
    return new Response($code, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($data, JSON_UNESCAPED_UNICODE));
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
