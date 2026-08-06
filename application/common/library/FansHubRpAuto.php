<?php

namespace app\common\library;

use think\Db;
use think\Exception;

/**
 * 红包自动发 / 自动抢
 *
 * 主路径已迁入 IM Workerman（RpAutoBotService）。
 * 本类仅作后台「立即执行一次」兜底；若检测到 WS 机器人心跳则跳过，避免双发。
 */
class FansHubRpAuto
{
    /**
     * @param int  $taskId 0=全部启用任务
     * @param bool $force  后台「立即执行」：无视 IM 心跳跳过与发包间隔
     * @return array{send:int,grab:int,skip:int,errors:array,via?:string,message?:string}
     */
    public static function run($taskId = 0, $force = false)
    {
        if (!$force && self::isImBotActive()) {
            return [
                'send'   => 0,
                'grab'   => 0,
                'skip'   => 0,
                'errors' => [],
                'via'    => 'im_ws',
                'message'=> '自动发抢已由 IM WebSocket 进程执行，无需定时任务',
            ];
        }

        $stat = [
            'send'   => 0,
            'grab'   => 0,
            'skip'   => 0,
            'errors' => [],
            'via'    => $force ? 'admin_force' : 'cli',
        ];
        $q = Db::name('chat_rp_auto_task')->where('status', 'normal');
        if ((int)$taskId > 0) {
            $q->where('id', (int)$taskId);
        }
        $rows = $q->order('id', 'asc')->select();
        if (!$rows) {
            if ((int)$taskId > 0) {
                $stat['errors'][] = '任务 #' . (int)$taskId . ' 不存在或未启用';
            } else {
                $stat['errors'][] = '没有启用中的自动发抢任务';
            }
            return $stat;
        }
        foreach ($rows ?: [] as $task) {
            try {
                $one = self::runOne($task, $force);
                $stat['send'] += (int)$one['send'];
                $stat['grab'] += (int)$one['grab'];
                $stat['skip'] += (int)$one['skip'];
                if (!empty($one['reason'])) {
                    $stat['errors'][] = 'task#' . (int)$task['id'] . ': ' . $one['reason'];
                }
            } catch (\Throwable $e) {
                $msg = trim($e->getMessage());
                if ($msg === '') {
                    $msg = get_class($e);
                }
                $stat['errors'][] = 'task#' . (int)$task['id'] . ': ' . $msg;
                self::touchError((int)$task['id'], $msg);
            }
        }
        return $stat;
    }

    /** IM worker0 心跳键（与 RpAutoBotService::HEARTBEAT_KEY 一致） */
    public static function isImBotActive()
    {
        try {
            if (!class_exists('Redis')) {
                return false;
            }
            $host = '127.0.0.1';
            $port = 6379;
            $pass = '';
            $db = 2;
            $rootEnv = dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . '.env';
            if (is_file($rootEnv)) {
                $ini = @parse_ini_file($rootEnv, true);
                if (is_array($ini) && !empty($ini['redis'])) {
                    $host = $ini['redis']['hostname'] ?? $host;
                    $port = (int)($ini['redis']['hostport'] ?? $port);
                    $pass = (string)($ini['redis']['password'] ?? $pass);
                }
            }
            $imLocal = dirname(dirname(dirname(__DIR__))) . '/im-server/config/local.php';
            if (is_file($imLocal)) {
                $local = include $imLocal;
                if (is_array($local) && !empty($local['redis'])) {
                    $host = $local['redis']['host'] ?? $host;
                    $port = (int)($local['redis']['port'] ?? $port);
                    $pass = (string)($local['redis']['password'] ?? $pass);
                    $db = (int)($local['redis']['db'] ?? $db);
                }
            }
            $r = new \Redis();
            if (!$r->connect($host, $port, 1.0)) {
                return false;
            }
            if ($pass !== '') {
                $r->auth($pass);
            }
            $r->select($db);
            $v = $r->get('im:rp_auto:ws_active');
            return $v !== false && $v !== null && $v !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected static function runOne(array $task, $force = false)
    {
        $out = ['send' => 0, 'grab' => 0, 'skip' => 0, 'reason' => ''];
        $id = (int)$task['id'];
        $groupId = (int)$task['group_id'];
        if ($groupId <= 0) {
            throw new Exception('未配置群 ID');
        }
        $group = Db::name('chat_groups')->where('id', $groupId)->find();
        if (!$group) {
            throw new Exception('群不存在: #' . $groupId . '（请核对任务群 ID）');
        }

        $today = date('Y-m-d');
        if ((string)($task['today_date'] ?? '') !== $today) {
            Db::name('chat_rp_auto_task')->where('id', $id)->update([
                'today_date'  => $today,
                'today_count' => 0,
                'updatetime'  => time(),
            ]);
            $task['today_count'] = 0;
            $task['today_date'] = $today;
        }

        $packetId = 0;
        if ((int)$task['auto_send'] === 1) {
            $sent = self::maybeSend($task, $force);
            if ($sent['sent']) {
                $out['send'] = 1;
                $packetId = (int)$sent['packet_id'];
            } else {
                $out['skip']++;
                $out['reason'] = (string)($sent['reason'] ?? '未发包');
            }
        }

        if ((int)$task['auto_grab'] === 1) {
            $grabbed = self::maybeGrab($task, $packetId);
            $out['grab'] += $grabbed;
        }

        return $out;
    }

    protected static function maybeSend(array $task, $force = false)
    {
        $id = (int)$task['id'];
        $interval = self::effectiveIntervalSec(max(5, (int)$task['interval_sec']));
        $last = (int)$task['last_send_time'];
        if (!$force && $last > 0 && (time() - $last) < $interval) {
            return ['sent' => false, 'packet_id' => 0, 'reason' => '未到发包间隔（剩余 ' . ($interval - (time() - $last)) . ' 秒）'];
        }
        $maxDay = (int)$task['max_per_day'];
        if ($maxDay > 0 && (int)$task['today_count'] >= $maxDay) {
            return ['sent' => false, 'packet_id' => 0, 'reason' => '已达今日发包上限 ' . $maxDay];
        }
        $groupId = (int)$task['group_id'];
        // 有待领取则不发（与 WS 机器人一致）；强制执行时也遵守，避免叠包
        $openCnt = (int)Db::name('chat_red_packets')
            ->where('group_id', $groupId)
            ->where('scope_type', 2)
            ->where('status', 1)
            ->where('remain_count', '>', 0)
            ->count();
        if ($openCnt > 0) {
            return ['sent' => false, 'packet_id' => 0, 'reason' => '群内仍有 ' . $openCnt . ' 个待领取红包'];
        }

        $sendUid = (int)$task['send_user_id'];
        if ($sendUid <= 0) {
            throw new Exception('未配置发包用户ID');
        }
        $amount = self::jitterAmount(round((float)$task['total_amount'], 2));
        $count = (int)$task['total_count'];
        if ($amount <= 0 || $count <= 0) {
            throw new Exception('金额/个数无效');
        }

        self::ensureAgentAccount($sendUid);

        $packetType = (int)$task['packet_type'] ?: 2;
        $mineDigit = ($packetType === 3) ? random_int(0, 9) : 0;

        $result = FansHubImBridge::post('/agent/send_redpacket', [
            'agent_user_id' => $sendUid,
            'scope_type'    => 2,
            'group_id'      => $groupId,
            'packet_type'   => $packetType,
            'total_amount'  => $amount,
            'total_count'   => $count,
            'blessing'      => (string)($task['blessing'] ?: '恭喜发财'),
            'mine_digit'    => $mineDigit,
            'bot_mode'      => 1,
        ]);

        $packetId = (int)($result['packet_id'] ?? ($result['packet']['id'] ?? 0));
        if ($packetId <= 0 && !empty($result['message']['extra'])) {
            $extra = $result['message']['extra'];
            if (is_string($extra)) {
                $extra = json_decode($extra, true) ?: [];
            }
            $packetId = (int)($extra['packet_id'] ?? 0);
        }
        if ($packetId <= 0) {
            throw new Exception('发包失败：桥接未返回 packet_id');
        }

        Db::name('chat_rp_auto_task')->where('id', $id)->update([
            'last_send_time' => time(),
            'last_packet_id' => $packetId,
            'today_count'    => (int)$task['today_count'] + 1,
            'last_error'     => '',
            'updatetime'     => time(),
        ]);

        return ['sent' => true, 'packet_id' => $packetId, 'reason' => ''];
    }

    protected static function maybeGrab(array $task, $preferPacketId = 0)
    {
        $uids = self::parseUserIds((string)($task['grab_user_ids'] ?? ''));
        if (!$uids) {
            return 0;
        }
        $groupId = (int)$task['group_id'];
        $packetIds = [];
        if ((int)$preferPacketId > 0) {
            $packetIds[] = (int)$preferPacketId;
        }
        $open = Db::name('chat_red_packets')
            ->where('group_id', $groupId)
            ->where('scope_type', 2)
            ->where('status', 1)
            ->where('remain_count', '>', 0)
            ->order('id', 'desc')
            ->limit(8)
            ->column('id');
        foreach ($open ?: [] as $pid) {
            $pid = (int)$pid;
            if ($pid > 0 && !in_array($pid, $packetIds, true)) {
                $packetIds[] = $pid;
            }
        }
        if (!$packetIds) {
            return 0;
        }

        // CLI 兜底：只抢 1 次，延迟 5～15 秒量级（短 sleep，避免卡死进程太久）
        $minMs = max(1000, (int)$task['grab_delay_min_ms']);
        $maxMs = max($minMs, (int)$task['grab_delay_max_ms']);
        if ($maxMs < 5000) {
            $minMs = 5000;
            $maxMs = 15000;
        }
        // CLI 最多 sleep 3 秒，真正节奏交给 WS
        $sleepMs = min(3000, $minMs + ($maxMs > $minMs ? mt_rand(0, $maxMs - $minMs) : 0));

        shuffle($uids);
        foreach ($packetIds as $packetId) {
            $candidates = [];
            foreach ($uids as $uid) {
                $exists = Db::name('chat_red_packet_records')
                    ->where(['packet_id' => $packetId, 'user_id' => $uid])
                    ->value('id');
                if ($exists) {
                    continue;
                }
                $candidates[] = $uid;
            }
            if (!$candidates) {
                continue;
            }
            $uid = $candidates[array_rand($candidates)];
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
            try {
                FansHubImBridge::post('/agent/grab_redpacket', [
                    'agent_user_id' => $uid,
                    'packet_id'     => $packetId,
                ]);
                return 1;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'already') !== false
                    || stripos($msg, 'empty') !== false
                    || stripos($msg, 'closed') !== false
                    || stripos($msg, 'balance') !== false
                    || stripos($msg, 'grabbed') !== false
                    || stripos($msg, 'not in group') !== false
                ) {
                    continue;
                }
                self::touchError((int)$task['id'], 'grab u' . $uid . ' p' . $packetId . ': ' . $msg);
            }
        }
        return 0;
    }

    protected static function parseUserIds($raw)
    {
        $raw = str_replace(["\xef\xbc\x8c", '、', '|', "\n", "\r"], ',', (string)$raw);
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            $id = (int)$p;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    protected static function effectiveIntervalSec($baseSec)
    {
        $base = max(5, (int)$baseSec);
        $hour = (int)date('G');
        if ($hour >= 20 && $hour <= 23) {
            return max(15, (int)round($base * 0.5));
        }
        if ($hour >= 0 && $hour < 8) {
            return max($base * 2, (int)round($base * 2.0));
        }
        return $base;
    }

    protected static function jitterAmount($baseAmount)
    {
        $base = round((float)$baseAmount, 2);
        if ($base <= 0) {
            return $base;
        }
        $pct = mt_rand(50, 100) / 1000.0;
        if (mt_rand(0, 1) === 0) {
            $pct = -$pct;
        }
        $amount = round($base * (1 + $pct), 2);
        if ($amount < 0.01) {
            $amount = 0.01;
        }
        $floor = round(max(0.01, $base * 0.5), 2);
        if ($amount < $floor) {
            $amount = $floor;
        }
        return $amount;
    }

    protected static function ensureAgentAccount($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }
        $user = Db::name('user')->where('id', $userId)->find();
        if (!$user) {
            throw new Exception('发包用户不存在: ' . $userId);
        }
        $row = Db::name('chat_agent_accounts')->where('user_id', $userId)->find();
        $now = time();
        if ($row) {
            if ((int)$row['status'] !== 1) {
                Db::name('chat_agent_accounts')->where('id', (int)$row['id'])->update([
                    'status'     => 1,
                    'updatetime' => $now,
                ]);
            }
            return;
        }
        try {
            Db::name('chat_agent_accounts')->insert([
                'user_id'      => $userId,
                'admin_id'     => 0,
                'label'        => '红包自动任务',
                'scope'        => 'all',
                'friend_reply' => '',
                'status'       => 1,
                'createtime'   => $now,
                'updatetime'   => $now,
            ]);
        } catch (\Throwable $e) {
            $again = Db::name('chat_agent_accounts')->where('user_id', $userId)->where('status', 1)->find();
            if (!$again) {
                throw new Exception('登记托管客服失败: ' . $e->getMessage());
            }
        }
    }

    protected static function touchError($taskId, $msg)
    {
        $taskId = (int)$taskId;
        if ($taskId <= 0) {
            return;
        }
        Db::name('chat_rp_auto_task')->where('id', $taskId)->update([
            'last_error' => mb_substr((string)$msg, 0, 250),
            'updatetime' => time(),
        ]);
    }
}
