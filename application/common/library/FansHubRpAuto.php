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
        $now = time();
        $burstWindow = (int)($task['burst_window_sec'] ?? 0);
        $burstCount = max(1, (int)($task['burst_count'] ?? 1));
        $useBurst = ($burstWindow > 0 && $burstCount > 0);

        if (!$force) {
            if ($useBurst) {
                $start = (int)($task['burst_window_start'] ?? 0);
                $sent = (int)($task['burst_sent'] ?? 0);
                $nextAt = (int)($task['burst_next_at'] ?? 0);
                if ($start <= 0 || ($now - $start) >= $burstWindow) {
                    $start = $now;
                    $sent = 0;
                    $nextAt = $now + mt_rand(0, max(1, (int)floor($burstWindow / max(2, $burstCount))));
                    Db::name('chat_rp_auto_task')->where('id', $id)->update([
                        'burst_window_start' => $start,
                        'burst_sent'         => 0,
                        'burst_next_at'      => $nextAt,
                        'updatetime'         => $now,
                    ]);
                    $task['burst_window_start'] = $start;
                    $task['burst_sent'] = 0;
                    $task['burst_next_at'] = $nextAt;
                }
                if ($sent >= $burstCount) {
                    return ['sent' => false, 'packet_id' => 0, 'reason' => '本时间窗已发满 ' . $burstCount . ' 包'];
                }
                if ($nextAt > 0 && $now < $nextAt) {
                    return ['sent' => false, 'packet_id' => 0, 'reason' => '未到窗内下一次计划发包'];
                }
            } else {
                $interval = self::effectiveIntervalSec(
                    max(5, (int)$task['interval_sec']),
                    $task['interval_windows'] ?? null
                );
                $last = (int)$task['last_send_time'];
                if ($last > 0 && ($now - $last) < $interval) {
                    return ['sent' => false, 'packet_id' => 0, 'reason' => '未到发包间隔（剩余 ' . ($interval - ($now - $last)) . ' 秒）'];
                }
            }
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

        $sendUid = self::pickSendUserId($task);
        if ($sendUid <= 0) {
            throw new Exception('未配置发包用户ID');
        }
        $group = Db::name('chat_groups')->where('id', $groupId)->find() ?: [];
        $packetType = (int)$task['packet_type'] ?: 2;
        $amountPlan = self::resolveSendAmountPlan($task, $group, $packetType);
        $amount = (float)($amountPlan['amount'] ?? 0);
        $count = (int)$task['total_count'];
        if ($amount <= 0 || $count <= 0) {
            throw new Exception('金额/个数无效');
        }

        self::ensureAgentAccount($sendUid);

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

        $burstSent = (int)($task['burst_sent'] ?? 0) + 1;
        $burstNext = 0;
        $burstStart = (int)($task['burst_window_start'] ?? 0);
        if ($useBurst) {
            if ($burstStart <= 0) {
                $burstStart = $now;
            }
            if ($burstSent < $burstCount) {
                $left = max(1, ($burstStart + $burstWindow) - $now);
                $remain = max(1, $burstCount - $burstSent);
                $slot = max(1, (int)floor($left / $remain));
                $burstNext = $now + mt_rand(max(1, (int)floor($slot * 0.35)), $slot);
            } else {
                $burstNext = $burstStart + $burstWindow;
            }
        }

        Db::name('chat_rp_auto_task')->where('id', $id)->update([
            'last_send_time'      => $now,
            'last_packet_id'      => $packetId,
            'today_count'         => (int)$task['today_count'] + 1,
            'burst_window_start'  => $useBurst ? $burstStart : 0,
            'burst_sent'          => $useBurst ? $burstSent : 0,
            'burst_next_at'       => $burstNext,
            'amount_mode2_count'  => (int)($amountPlan['next_mode2_count'] ?? 0),
            'amount_mode2_target' => (int)($amountPlan['next_mode2_target'] ?? 0),
            'last_error'          => '',
            'updatetime'          => $now,
        ]);

        return ['sent' => true, 'packet_id' => $packetId, 'reason' => ''];
    }

    protected static function pickSendUserId(array $task)
    {
        if (self::actorMode($task) === 2) {
            $bots = self::listBotUserIds();
            if (!$bots) {
                return 0;
            }
            return (int)$bots[array_rand($bots)];
        }
        $uids = self::parseUserIds((string)($task['send_user_ids'] ?? ''));
        if (!$uids) {
            $one = (int)($task['send_user_id'] ?? 0);
            return $one > 0 ? $one : 0;
        }
        if (count($uids) === 1) {
            return (int)$uids[0];
        }
        return (int)$uids[array_rand($uids)];
    }

    protected static function maybeGrab(array $task, $preferPacketId = 0)
    {
        if (self::actorMode($task) === 2) {
            $uids = self::listBotUserIds();
            if (!$uids) {
                return 0;
            }
        } else {
            $uids = self::parseUserIds((string)($task['grab_user_ids'] ?? ''));
            if (!$uids) {
                return 0;
            }
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

        // CLI 兜底：按任务配置 sleep（上限 15s），避免无 WS 时变成秒级连抢
        $minMs = max(1000, (int)$task['grab_delay_min_ms']);
        $maxMs = max($minMs, (int)$task['grab_delay_max_ms']);
        if ($maxMs <= 120 && $minMs <= 120) {
            $minMs *= 1000;
            $maxMs *= 1000;
        }
        if ($maxMs < 1000) {
            $minMs = 1000;
            $maxMs = 5000;
        }
        $sleepMs = $minMs + ($maxMs > $minMs ? mt_rand(0, $maxMs - $minMs) : 0);
        $sleepMs = min(15000, max(1000, $sleepMs));

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

    protected static function actorMode(array $task)
    {
        return ((int)($task['actor_mode'] ?? 1) === 2) ? 2 : 1;
    }

    /** @return int[] */
    protected static function listBotUserIds($limit = 300)
    {
        static $cache = null;
        static $at = 0;
        if (is_array($cache) && (time() - $at) < 30) {
            return $cache;
        }
        $limit = max(1, min(500, (int)$limit));
        $rows = Db::name('fans_account')
            ->where('is_bot', 1)
            ->where('status', 'normal')
            ->order('id', 'asc')
            ->limit($limit)
            ->column('user_id');
        $out = [];
        foreach ($rows ?: [] as $uid) {
            $uid = (int)$uid;
            if ($uid > 0) {
                $out[] = $uid;
            }
        }
        $cache = $out;
        $at = time();
        return $out;
    }

    /**
     * 发包间隔：时段固定秒数优先，否则用 base。
     * 默认：20–23→30s，0–7→120s；显式 [] 关闭时段规则。
     */
    protected static function effectiveIntervalSec($baseSec, $windowsJson = null)
    {
        $base = max(5, (int)$baseSec);
        $hour = (int)date('G');
        $windows = self::parseIntervalWindows($windowsJson);
        foreach ($windows as $w) {
            $start = (int)($w['start_hour'] ?? -1);
            $end = (int)($w['end_hour'] ?? -1);
            $iv = max(5, (int)($w['interval_sec'] ?? 0));
            if ($start < 0 || $start > 23 || $end < 0 || $end > 23 || $iv < 5) {
                continue;
            }
            if (self::hourInWindow($hour, $start, $end)) {
                return $iv;
            }
        }
        return $base;
    }

    protected static function parseIntervalWindows($raw)
    {
        if ($raw === null) {
            return self::defaultIntervalWindows();
        }
        if (is_array($raw)) {
            $arr = $raw;
        } else {
            $trim = trim((string)$raw);
            if ($trim === '') {
                return self::defaultIntervalWindows();
            }
            if ($trim === '[]') {
                return [];
            }
            $arr = json_decode($trim, true);
            if (!is_array($arr)) {
                return self::defaultIntervalWindows();
            }
        }
        $out = [];
        foreach ($arr as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'start_hour'   => (int)($row['start_hour'] ?? $row['start'] ?? -1),
                'end_hour'     => (int)($row['end_hour'] ?? $row['end'] ?? -1),
                'interval_sec' => (int)($row['interval_sec'] ?? $row['interval'] ?? 0),
            ];
        }
        return $out;
    }

    protected static function defaultIntervalWindows()
    {
        return [
            ['start_hour' => 20, 'end_hour' => 23, 'interval_sec' => 30],
            ['start_hour' => 0, 'end_hour' => 7, 'interval_sec' => 120],
        ];
    }

    protected static function hourInWindow($hour, $start, $end)
    {
        $hour = (int)$hour;
        $start = (int)$start;
        $end = (int)$end;
        if ($start <= $end) {
            return $hour >= $start && $hour <= $end;
        }
        return $hour >= $start || $hour <= $end;
    }

    /**
     * @return array{amount:float,amount_mode:int,jackpot:bool,next_mode2_count:int,next_mode2_target:int}
     */
    protected static function resolveSendAmountPlan(array $task, array $group, $packetType)
    {
        $packetType = (int)$packetType;
        $mode = ((int)($task['amount_mode'] ?? 1) === 2) ? 2 : 1;
        if ($packetType === 5) {
            $mode = 1;
        }

        if ($mode === 2) {
            $streak = max(0, (int)($task['amount_mode2_count'] ?? 0));
            $target = (int)($task['amount_mode2_target'] ?? 0);
            if ($target < 10 || $target > 20) {
                $target = mt_rand(10, 20);
            }
            if ($streak >= $target) {
                return [
                    'amount'            => (float)self::randomAmountByTen(200, 300),
                    'amount_mode'       => 2,
                    'jackpot'           => true,
                    'next_mode2_count'  => 0,
                    'next_mode2_target' => mt_rand(10, 20),
                ];
            }
            return [
                'amount'            => (float)self::randomAmountByTen(10, 100),
                'amount_mode'       => 2,
                'jackpot'           => false,
                'next_mode2_count'  => $streak + 1,
                'next_mode2_target' => $target,
            ];
        }

        return [
            'amount'            => self::resolveSendAmount($task, $group),
            'amount_mode'       => 1,
            'jackpot'           => false,
            'next_mode2_count'  => (int)($task['amount_mode2_count'] ?? 0),
            'next_mode2_target' => (int)($task['amount_mode2_target'] ?? 0),
        ];
    }

    protected static function randomAmountByTen($min, $max)
    {
        $minInt = (int)(ceil(max(10, (int)round($min)) / 10) * 10);
        $maxInt = (int)(ceil(max($minInt, (int)round($max)) / 10) * 10);
        if ($minInt === $maxInt) {
            return $minInt;
        }
        $steps = (int)(($maxInt - $minInt) / 10);
        return $minInt + mt_rand(0, max(0, $steps)) * 10;
    }

    /**
     * 任务金额区间：相等=固定，否则随机；只发整数元；群固定金额优先。
     */
    protected static function resolveSendAmount(array $task, array $group)
    {
        $groupFixed = (float)($group['rp_fixed_amount'] ?? 0);
        if ($groupFixed > 0) {
            return (float)max(1, (int)round($groupFixed));
        }

        $min = (float)($task['amount_min'] ?? 0);
        $max = (float)($task['amount_max'] ?? 0);
        $legacy = (float)($task['total_amount'] ?? 0);
        if ($min <= 0 && $legacy > 0) {
            $min = $legacy;
        }
        if ($max <= 0 && $legacy > 0) {
            $max = $legacy;
        }
        if ($min <= 0 && $max > 0) {
            $min = $max;
        }
        if ($max <= 0 && $min > 0) {
            $max = $min;
        }
        if ($max < $min) {
            $tmp = $min;
            $min = $max;
            $max = $tmp;
        }
        if ($min <= 0) {
            return 0.0;
        }

        $minInt = max(1, (int)round($min));
        $maxInt = max($minInt, (int)round($max));
        if ($minInt === $maxInt) {
            $amount = (float)$minInt;
        } else {
            $amount = (float)mt_rand($minInt, $maxInt);
        }
        $gMin = (float)($group['rp_min_amount'] ?? 0);
        $gMax = (float)($group['rp_max_amount'] ?? 0);
        if ($gMin > 0) {
            $gMinInt = max(1, (int)round($gMin));
            if ($amount < $gMinInt) {
                $amount = (float)$gMinInt;
            }
        }
        if ($gMax > 0) {
            $gMaxInt = max(1, (int)round($gMax));
            if ($amount > $gMaxInt) {
                $amount = (float)$gMaxInt;
            }
        }
        return (float)max(1, (int)round($amount));
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
