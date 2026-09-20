<?php

namespace app\common\library;

use think\Db;

/**
 * 视频源采集：倒序采页，发到指定群（默认发送号 11111111）。
 */
class FansHubVideoCrawl
{
    /**
     * 跑一个任务的一页（倒序）。
     *
     * @return array{ok:bool,page:int,sent:int,skip:int,fail:int,msg:string,pagecount:int}
     */
    public static function runOnePage($taskId, $force = false)
    {
        $taskId = (int)$taskId;
        $task = Db::name('chat_video_crawl_task')->where('id', $taskId)->find();
        if (!$task) {
            return self::fail(0, '任务不存在');
        }
        if (!$force && (string)($task['status'] ?? '') !== 'normal' && (int)($task['force_run'] ?? 0) !== 1) {
            return self::fail(0, '任务未启用');
        }

        $api = trim((string)($task['api_url'] ?? ''));
        if ($api === '') {
            return self::fail(0, '未配置采集地址');
        }
        $groupId = (int)($task['group_id'] ?? 0);
        $sendUid = (int)($task['send_user_id'] ?? 11111111);
        if ($groupId <= 0) {
            return self::fail(0, '未选择发送群');
        }
        if ($sendUid <= 0) {
            $sendUid = 11111111;
        }

        $g = Db::name('chat_groups')->where('id', $groupId)->whereIn('status', [1, 3])->find();
        if (!$g) {
            return self::touchErr($taskId, '群不存在或已解散');
        }
        $u = Db::name('user')->where('id', $sendUid)->find();
        if (!$u) {
            return self::touchErr($taskId, '发送用户不存在: ' . $sendUid);
        }
        self::ensureMember($groupId, $sendUid);

        $page = (int)($task['current_page'] ?? 0);
        if ($page <= 0) {
            $page = max(1, (int)($task['start_page'] ?? 100));
        }

        $fetch = self::fetchPage($api, $page);
        if (!$fetch['ok']) {
            return self::touchErr($taskId, $fetch['msg']);
        }

        $pagecount = max(0, (int)$fetch['pagecount']);
        $list = $fetch['list'];
        // 若起始页大于总页数，落到总页数再采
        if ($pagecount > 0 && $page > $pagecount) {
            $page = $pagecount;
            $fetch = self::fetchPage($api, $page);
            if (!$fetch['ok']) {
                return self::touchErr($taskId, $fetch['msg']);
            }
            $pagecount = max($pagecount, (int)$fetch['pagecount']);
            $list = $fetch['list'];
        }

        $pageLimit = max(0, (int)($task['page_limit'] ?? 0));
        $sent = 0;
        $skip = 0;
        $fail = 0;
        $n = 0;
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $vodId = (int)($item['vod_id'] ?? 0);
            $play = trim((string)($item['vod_play_url'] ?? ''));
            $pic = trim((string)($item['vod_pic'] ?? ''));
            $name = trim((string)($item['vod_name'] ?? ''));
            if ($vodId <= 0 || $play === '' || !preg_match('#^https?://#i', $play)) {
                $fail++;
                continue;
            }
            if (self::alreadySent($taskId, $vodId)) {
                $skip++;
                continue;
            }
            if ($pageLimit > 0 && $n >= $pageLimit) {
                break;
            }
            $n++;
            $mid = self::sendVideo($sendUid, $groupId, $vodId, $play, $pic, $name);
            if ($mid > 0) {
                self::markSent($taskId, $vodId, $groupId, $mid);
                $sent++;
                usleep(250000);
            } else {
                $fail++;
            }
        }

        $next = $page > 1 ? ($page - 1) : 0;
        $now = time();
        Db::name('chat_video_crawl_task')->where('id', $taskId)->update([
            'force_run'     => 0,
            'pagecount'     => $pagecount > 0 ? $pagecount : (int)($task['pagecount'] ?? 0),
            'current_page'  => $next,
            'pages_done'    => (int)($task['pages_done'] ?? 0) + 1,
            'sent_count'    => (int)($task['sent_count'] ?? 0) + $sent,
            'skip_count'    => (int)($task['skip_count'] ?? 0) + $skip,
            'last_page'     => $page,
            'last_error'    => $fail > 0 && $sent <= 0 ? '本页发送失败较多' : '',
            'last_run_time' => $now,
            'updatetime'    => $now,
        ]);

        $msg = sprintf('第 %d 页：发送 %d / 跳过 %d / 失败 %d；下一页 %s', $page, $sent, $skip, $fail, $next > 0 ? (string)$next : '已采完');
        return [
            'ok'        => true,
            'page'      => $page,
            'sent'      => $sent,
            'skip'      => $skip,
            'fail'      => $fail,
            'msg'       => $msg,
            'pagecount' => $pagecount,
            'next_page' => $next,
        ];
    }

    /**
     * 定时：所有启用且 auto_run=1 的任务各采一页。
     */
    public static function tickCron($limit = 20)
    {
        $limit = max(1, min(50, (int)$limit));
        $rows = Db::name('chat_video_crawl_task')
            ->where('status', 'normal')
            ->where(function ($q) {
                $q->where('auto_run', 1)->whereOr('force_run', 1);
            })
            ->where('current_page', '>=', 0)
            ->order('id', 'asc')
            ->limit($limit)
            ->select();
        $out = [];
        foreach ($rows ?: [] as $row) {
            $cur = (int)($row['current_page'] ?? 0);
            $start = (int)($row['start_page'] ?? 0);
            // current_page=0 且已采过页 → 结束；未采过则从 start_page 开始
            if ($cur <= 0 && (int)($row['pages_done'] ?? 0) > 0 && (int)($row['force_run'] ?? 0) !== 1) {
                continue;
            }
            if ($cur <= 0 && $start <= 0) {
                continue;
            }
            $out[] = self::runOnePage((int)$row['id'], true);
        }
        return $out;
    }

    protected static function fail($page, $msg)
    {
        return ['ok' => false, 'page' => (int)$page, 'sent' => 0, 'skip' => 0, 'fail' => 0, 'msg' => (string)$msg, 'pagecount' => 0, 'next_page' => 0];
    }

    protected static function touchErr($taskId, $msg)
    {
        $msg = mb_substr(trim((string)$msg), 0, 250);
        Db::name('chat_video_crawl_task')->where('id', (int)$taskId)->update([
            'force_run'  => 0,
            'last_error' => $msg,
            'updatetime' => time(),
        ]);
        return self::fail(0, $msg);
    }

    protected static function fetchPage($api, $page)
    {
        $page = max(1, (int)$page);
        $base = preg_replace('/([?&])page=\d+/i', '$1', $api);
        $base = rtrim($base, '?&');
        $url = $base . (strpos($base, '?') !== false ? '&' : '?') . 'page=' . $page;
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 30,
                'header'  => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') {
            return ['ok' => false, 'msg' => '拉取失败: ' . $url, 'list' => [], 'pagecount' => 0];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || (int)($data['code'] ?? 0) !== 1) {
            return ['ok' => false, 'msg' => '接口返回异常', 'list' => [], 'pagecount' => 0];
        }
        $list = $data['list'] ?? [];
        return [
            'ok'        => true,
            'msg'       => 'ok',
            'list'      => is_array($list) ? $list : [],
            'pagecount' => (int)($data['pagecount'] ?? 0),
            'total'     => (int)($data['total'] ?? 0),
        ];
    }

    protected static function alreadySent($taskId, $vodId)
    {
        $row = Db::name('chat_video_crawl_sent')
            ->where('task_id', (int)$taskId)
            ->where('vod_id', (int)$vodId)
            ->find();
        return !!$row;
    }

    protected static function markSent($taskId, $vodId, $groupId, $messageId)
    {
        try {
            Db::name('chat_video_crawl_sent')->insert([
                'task_id'    => (int)$taskId,
                'vod_id'     => (int)$vodId,
                'group_id'   => (int)$groupId,
                'message_id' => (int)$messageId,
                'createtime' => time(),
            ]);
        } catch (\Throwable $e) {
            // unique 冲突忽略
        }
    }

    protected static function ensureMember($groupId, $userId)
    {
        $mem = Db::name('chat_group_members')
            ->where('group_id', (int)$groupId)
            ->where('user_id', (int)$userId)
            ->where('status', 1)
            ->find();
        if ($mem) {
            return;
        }
        $now = time();
        try {
            Db::name('chat_group_members')->insert([
                'group_id'   => (int)$groupId,
                'user_id'    => (int)$userId,
                'role'       => 1,
                'status'     => 1,
                'mute_until' => 0,
                'jointime'   => $now,
                'updatetime' => $now,
            ]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * @return int message id
     */
    protected static function sendVideo($sendUid, $groupId, $vodId, $play, $pic, $name)
    {
        $caption = $name !== '' ? mb_substr($name, 0, 200) : '';
        $extra = [
            'url'     => $play,
            'fullurl' => $play,
            'source'  => 'pgxdy',
            'vod_id'  => (int)$vodId,
        ];
        if ($pic !== '' && preg_match('#^https?://#i', $pic)) {
            $extra['thumb'] = $pic;
            $extra['poster'] = $pic;
        }
        if ($caption !== '') {
            $extra['caption'] = $caption;
        }

        $fanshub = config('fanshub.');
        if (!is_array($fanshub)) {
            $path = ROOT_PATH . 'application/extra/fanshub.php';
            $fanshub = is_file($path) ? include $path : [];
        }
        $im = is_array($fanshub['im_admin'] ?? null) ? $fanshub['im_admin'] : [];
        $bridgeUrl = rtrim((string)($im['bridge_url'] ?? 'http://127.0.0.1:17273'), '/');
        $bridgeKey = (string)($im['bridge_key'] ?? '');

        $payload = [
            'admin_key'     => $bridgeKey,
            'agent_user_id' => (int)$sendUid,
            'group_id'      => (int)$groupId,
            'content'       => $caption !== '' ? $caption : '[视频]',
            'msg_type'      => 5,
            'extra'         => $extra,
            'admin_id'      => 0,
        ];

        $mid = self::bridgeSend($bridgeUrl, $payload);
        if ($mid > 0) {
            return $mid;
        }
        return self::dbFallback($bridgeUrl, $bridgeKey, $sendUid, $groupId, $payload['content'], $extra);
    }

    protected static function bridgeSend($bridgeUrl, array $payload)
    {
        if ($bridgeUrl === '' || empty($payload['admin_key'])) {
            return 0;
        }
        $ch = curl_init($bridgeUrl . '/agent/send_group');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno || $code >= 400) {
            return 0;
        }
        $json = json_decode((string)$raw, true);
        return (int)(($json['message']['id'] ?? $json['id'] ?? 0));
    }

    protected static function dbFallback($bridgeUrl, $bridgeKey, $sendUid, $groupId, $content, array $extra)
    {
        $now = time();
        $msgId = sprintf('m%s%04d', date('YmdHis'), random_int(0, 9999));
        $id = (int)Db::name('chat_messages')->insertGetId([
            'msg_id'            => $msgId,
            'conversation_type' => 2,
            'conversation_id'   => (string)$groupId,
            'group_id'          => (int)$groupId,
            'from_user_id'      => (int)$sendUid,
            'to_user_id'        => 0,
            'msg_type'          => 5,
            'content'           => mb_substr((string)$content, 0, 2000),
            'extra'             => json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'            => 1,
            'createtime'        => $now,
        ]);
        if ($id <= 0) {
            return 0;
        }
        $row = Db::name('chat_messages')->where('id', $id)->find();
        if (is_array($row)) {
            if (!empty($row['extra']) && is_string($row['extra'])) {
                $decoded = json_decode($row['extra'], true);
                if (is_array($decoded)) {
                    $row['extra'] = $decoded;
                }
            }
            self::pushFallback($bridgeUrl, $bridgeKey, $row);
        }
        return $id;
    }

    protected static function pushFallback($bridgeUrl, $bridgeKey, array $msgRow)
    {
        if ($bridgeUrl === '' || $bridgeKey === '') {
            return;
        }
        $body = [
            'admin_key'  => $bridgeKey,
            'type'       => 'group.message',
            'message'    => $msgRow,
            'admin_only' => 0,
        ];
        $ch = curl_init($bridgeUrl . '/internal/push');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        @curl_exec($ch);
        @curl_close($ch);
    }
}
