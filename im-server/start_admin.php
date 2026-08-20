<?php
/**
 * 简易 HTTP 桥：
 * - 用户 API：会话/历史/红包/好友/群管理（token 鉴权）→ 减轻 WS Worker 压力
 * - 后台代聊：发私聊/群聊/红包（admin_key）
 * 监听: http://0.0.0.0:17273
 *
 * POST /im/*              {token, ...}
 * GET  /health
 * GET|POST /health/deep   admin_key（DB/Redis/Worker/Cron/积压）
 * POST /agent/*           admin_key
 */

use Im\Http\UserApi;
use Im\Service\GroupService;
use Im\Service\MessageService;
use Im\Service\RedPacketService;
use Im\Support\Db;
use Im\Support\HealthProbe;
use Im\Support\NotifyPublisher;
use Im\Support\RedisClient;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/bootstrap_logger.php';

$cfg = require __DIR__ . '/config/app.php';
$GLOBALS['im_cfg'] = $cfg;
Db::init($cfg['db']);
RedisClient::init($cfg['redis']);

$adminKey = $cfg['admin_bridge']['key'] ?? 'change-me-im-admin';
$httpListen = (string)($cfg['http_api']['listen'] ?? 'http://0.0.0.0:17273');
$httpCount = (int)($cfg['http_api']['count'] ?? ((PHP_OS_FAMILY === 'Windows') ? 1 : 8));

$http = new Worker($httpListen);
$http->count = max(1, $httpCount);
$http->name = 'FansHubIM-HttpApi';
if (!empty($cfg['http_api']['reuse_port']) && PHP_OS_FAMILY !== 'Windows') {
    $http->reusePort = true;
}

$http->onWorkerStart = function () use ($cfg) {
    // 子进程独立初始化，避免 fork 后共用失效连接；并定时保活
    Db::init($cfg['db']);
    RedisClient::init($cfg['redis']);
    Timer::add(60, function () {
        Db::keepalive();
    });
};

$http->onMessage = function (TcpConnection $connection, Request $request) use ($cfg, $adminKey) {
    $path = parse_url($request->uri(), PHP_URL_PATH) ?: '/';
    $method = strtoupper($request->method());

    // CORS：H5 与 17273 跨端口
    if ($method === 'OPTIONS') {
        $connection->send(corsResponse(204, ''));
        return;
    }

    if ($path === '/health') {
        $connection->send(corsJson(200, ['ok' => true]));
        return;
    }

    if ($path === '/health/deep') {
        $body0 = json_decode($request->rawBody(), true);
        if (!is_array($body0)) {
            $body0 = $request->post();
        }
        if (!is_array($body0)) {
            $body0 = [];
        }
        $key = (string)($body0['admin_key'] ?? $request->get('admin_key') ?? $request->header('x-im-admin-key') ?? '');
        if ($key === '' || !hash_equals((string)$adminKey, $key)) {
            $connection->send(corsJson(403, ['ok' => false, 'error' => 'forbidden']));
            return;
        }
        try {
            $report = HealthProbe::run($cfg);
            $connection->send(corsJson($report['ok'] ? 200 : 503, $report));
        } catch (\Throwable $e) {
            $connection->send(corsJson(500, ['ok' => false, 'error' => $e->getMessage()]));
        }
        return;
    }

    $body = json_decode($request->rawBody(), true);
    if (!is_array($body)) {
        $body = $request->post();
    }
    if (!is_array($body)) {
        $body = [];
    }

    // -------- 用户 API（会员 token，无需 admin_key）--------
    if (strpos($path, '/im/') === 0) {
        try {
            $token = (string)($body['token'] ?? $request->header('x-fans-token') ?? $request->get('token') ?? '');
            $api = new UserApi($cfg);
            $uid = $api->userIdByToken($token);
            if ($uid <= 0) {
                $connection->send(corsJson(401, ['message' => 'unauthorized']));
                return;
            }
            if ($method !== 'POST') {
                $connection->send(corsJson(405, ['message' => 'method not allowed']));
                return;
            }
            $meta = ['ip' => ''];
            try {
                $meta['ip'] = (string)$connection->getRemoteIp();
            } catch (\Throwable $eIp) {
            }
            $out = $api->handle($path, $uid, $body, $meta);
            $data = isset($out['data']) ? $out['data'] : $out;
            $payload = ['code' => 1, 'data' => $data];
            if (!empty($out['ws_type'])) {
                $payload['ws_type'] = (string)$out['ws_type'];
            }
            // 兼容旧客户端：conversations/history 仍可从顶层读 list
            if (is_array($data)) {
                foreach (['list', 'group', 'policy', 'my_role', 'mute_all', 'member_count', 'can_speak', 'member_list_hidden'] as $k) {
                    if (array_key_exists($k, $data) && !array_key_exists($k, $payload)) {
                        $payload[$k] = $data[$k];
                    }
                }
            }
            $connection->send(corsJson(200, $payload));
        } catch (\Throwable $e) {
            $raw = $e->getMessage();
            $msg = friendlyImHttpError($raw);
            $code = ($raw === 'not found') ? 404 : 400;
            $connection->send(corsJson($code, ['code' => 0, 'message' => $msg ?: 'error']));
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
                'robot_send'   => true,
                'trusted_robot'=> true,
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
            // 私聊红包禁止机器人代抢（仅对方本人可领）
            $probe = \Im\Support\Db::fetch(
                'SELECT scope_type FROM ' . \Im\Support\Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
                [$packetId]
            );
            if ($probe && (int)($probe['scope_type'] ?? 0) === 1) {
                throw new RuntimeException('private red packet: robot grab disabled');
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
                    $gid = (int)$packet['group_id'];
                    \Im\Support\RedPacketUpdateBus::publish($event, ['group_id' => $gid]);
                } else {
                    \Im\Support\RedPacketUpdateBus::publish($event, [
                        'user_ids' => [(int)$packet['from_user_id'], (int)$packet['to_user_id']],
                    ]);
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

function friendlyImHttpError($msg)
{
    $msg = trim((string)$msg);
    if ($msg === '') {
        return 'error';
    }
    // 已是中文直接返回
    if (preg_match('/[\x{4e00}-\x{9fff}]/u', $msg)) {
        return $msg;
    }
    if (stripos($msg, 'server has gone away') !== false || strpos($msg, '2006') !== false) {
        return '数据库连接已断开，请重试一次（若持续出现请重启 IM 进程）';
    }
    if ($msg === 'mine count must be 5, 7 or 9') {
        return '扫雷红包个数仅可选 5 / 7 / 9';
    }
    if (strpos($msg, 'count must be') === 0) {
        $range = trim(substr($msg, strlen('count must be')));
        if (preg_match('/^(\d+)\s*-\s*\1$/', $range, $m)) {
            return '本群红包个数固定为 ' . $m[1] . ' 个';
        }
        return '红包个数须为 ' . str_replace('-', '～', $range);
    }
    if (strpos($msg, 'amount must be') === 0) {
        return '金额须为 ' . trim(substr($msg, strlen('amount must be'))) . ' 元';
    }
    if (strpos($msg, 'amount below group min') === 0) {
        return '金额不能低于本群最低 ' . trim(substr($msg, strlen('amount below group min'))) . ' 元';
    }
    if ($msg === 'insufficient balance') {
        return '红宝不足，请先闪兑凑够红宝';
    }
    if ($msg === 'balance_not_enough_for_compensate' || strpos($msg, 'balance_not_enough_for_compensate:') === 0) {
        $need = trim((string)substr($msg, strlen('balance_not_enough_for_compensate:')));
        if ($need !== '' && is_numeric($need)) {
            return '红宝不足，需至少 ￥' . number_format((float)$need, 2, '.', '') . ' 才能领取（用于赔付/续发）';
        }
        return '红宝不足，无法覆盖赔付金额，不能领取';
    }
    if ($msg === 'balance_below_mine_min') {
        return '红宝须大于本群最低金额限制，才能领取扫雷红包';
    }
    if ($msg === 'too many packets' || $msg === 'amount too small' || $msg === 'amount too small after fee') {
        return '红宝金额或个数不符合规则';
    }
    if ($msg === 'packet type not allowed in this group') {
        return '当前群不允许该红宝类型';
    }
    if ($msg === 'robot only: members cannot send red packets') {
        return '本群仅自动机器人可发红宝';
    }
    if ($msg === 'grab mode: only admin can send red packets') {
        return '红宝模式下仅管理员/机器人可发红宝';
    }
    if ($msg === 'relay: only admin can send') {
        return '接龙红宝仅群主/管理员可发，领取最少后由系统代发下一包';
    }
    if ($msg === 'not in group' || $msg === 'target not in group') {
        return '你不在该群内';
    }
    return $msg;
}

function corsJson($code, array $data)
{
    // 坏 UTF-8 内容不可让 json_encode 失败成空 body（前端会整页「加载失败」）
    return new Response((int)$code, corsHeaders(), \Im\Support\Json::encode($data));
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
