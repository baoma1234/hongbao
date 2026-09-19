<?php
/**
 * 采集 https://www.pgxdy.com/api/json.php 视频，以用户 11111111 发到群 78。
 *
 * Usage:
 *   php scripts/crawl_pgxdy_send_group78.php           # 发首页（默认）
 *   php scripts/crawl_pgxdy_send_group78.php --page=1  # 指定页
 *   php scripts/crawl_pgxdy_send_group78.php --limit=5 # 本轮最多发 N 条
 *   php scripts/crawl_pgxdy_send_group78.php --dry     # 只拉列表不发送
 */
$root = dirname(__DIR__);
$e = parse_ini_file($root . '/.env', true);
$d = $e['database'];
$pdo = new PDO(
    'mysql:host=' . $d['hostname'] . ';dbname=' . $d['database'] . ';charset=utf8mb4',
    $d['username'],
    $d['password']
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$p = $d['prefix'] ?? 'fa_';

$fanshub = include $root . '/application/extra/fanshub.php';
$im = is_array($fanshub['im_admin'] ?? null) ? $fanshub['im_admin'] : [];
$bridgeUrl = rtrim((string)($im['bridge_url'] ?? 'http://127.0.0.1:17273'), '/');
$bridgeKey = (string)($im['bridge_key'] ?? '');

$SENDER = 11111111;
$GROUP = 78;
$API = 'https://www.pgxdy.com/api/json.php';
$STATE = $root . '/runtime/pgxdy_sent_vod_ids.json';

$page = 1;
$limitSend = 0; // 0 = 本页全部
$dry = false;
foreach ($argv as $i => $arg) {
    if ($i === 0) continue;
    if ($arg === '--dry') $dry = true;
    if (preg_match('/^--page=(\d+)$/', $arg, $m)) $page = max(1, (int)$m[1]);
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limitSend = max(0, (int)$m[1]);
}

$sentMap = [];
if (is_file($STATE)) {
    $raw = json_decode((string)file_get_contents($STATE), true);
    if (is_array($raw)) {
        foreach ($raw as $id) {
            $sentMap[(int)$id] = 1;
        }
    }
}

$g = $pdo->query("SELECT id,name,status FROM {$p}chat_groups WHERE id={$GROUP}")->fetch(PDO::FETCH_ASSOC);
if (!$g) {
    fwrite(STDERR, "group {$GROUP} not found\n");
    exit(1);
}
$u = $pdo->query("SELECT id,nickname FROM {$p}user WHERE id={$SENDER}")->fetch(PDO::FETCH_ASSOC);
if (!$u) {
    fwrite(STDERR, "sender {$SENDER} not found\n");
    exit(1);
}
$mem = $pdo->query("SELECT id FROM {$p}chat_group_members WHERE group_id={$GROUP} AND user_id={$SENDER} AND status=1")->fetch();
if (!$mem) {
    fwrite(STDERR, "sender not in group {$GROUP}\n");
    exit(1);
}

echo "group={$g['id']} {$g['name']}\n";
echo "sender={$u['id']} {$u['nickname']}\n";
echo "page={$page} dry=" . ($dry ? '1' : '0') . "\n";

$url = $API . (strpos($API, '?') !== false ? '&' : '?') . 'page=' . $page;
$ctx = stream_context_create([
    'http' => [
        'timeout' => 30,
        'header'  => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
    ],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
]);
$jsonRaw = @file_get_contents($url, false, $ctx);
if ($jsonRaw === false || $jsonRaw === '') {
    fwrite(STDERR, "fetch fail: {$url}\n");
    exit(1);
}
$data = json_decode($jsonRaw, true);
if (!is_array($data) || (int)($data['code'] ?? 0) !== 1) {
    fwrite(STDERR, "bad api response\n");
    exit(1);
}
$list = $data['list'] ?? [];
if (!is_array($list) || !$list) {
    echo "empty list\n";
    exit(0);
}
echo "api total=" . ($data['total'] ?? '?') . " pagecount=" . ($data['pagecount'] ?? '?') . " got=" . count($list) . "\n";

function bridgeSend($bridgeUrl, $bridgeKey, array $body)
{
    $body['admin_key'] = $bridgeKey;
    $ch = curl_init($bridgeUrl . '/agent/send_group');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno) {
        return [false, 'curl_errno=' . $errno, null];
    }
    $json = json_decode((string)$raw, true);
    if ($code >= 400) {
        $msg = is_array($json) ? (string)($json['message'] ?? $raw) : (string)$raw;
        return [false, $msg, $json];
    }
    return [true, 'ok', is_array($json) ? $json : null];
}

function publishFallback($bridgeUrl, $bridgeKey, array $msgRow)
{
    if (empty($msgRow['id'])) return;
    // 最小推送：让在线成员能收到
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
    curl_exec($ch);
    curl_close($ch);
}

$ok = 0;
$skip = 0;
$fail = 0;
$n = 0;
foreach ($list as $item) {
    if (!is_array($item)) continue;
    $vodId = (int)($item['vod_id'] ?? 0);
    $play = trim((string)($item['vod_play_url'] ?? ''));
    $pic = trim((string)($item['vod_pic'] ?? ''));
    $name = trim((string)($item['vod_name'] ?? ''));
    if ($vodId <= 0 || $play === '' || !preg_match('#^https?://#i', $play)) {
        $fail++;
        echo "SKIP bad item vod={$vodId}\n";
        continue;
    }
    if (isset($sentMap[$vodId])) {
        $skip++;
        echo "DUP vod={$vodId}\n";
        continue;
    }
    if ($limitSend > 0 && $n >= $limitSend) {
        break;
    }
    $n++;
    $caption = $name !== '' ? mb_substr($name, 0, 200) : '';
    $extra = [
        'url'     => $play,
        'fullurl' => $play,
        'source'  => 'pgxdy',
        'vod_id'  => $vodId,
    ];
    if ($pic !== '' && preg_match('#^https?://#i', $pic)) {
        $extra['thumb'] = $pic;
        $extra['poster'] = $pic;
    }
    if ($caption !== '') {
        $extra['caption'] = $caption;
    }
    $payload = [
        'agent_user_id' => $SENDER,
        'group_id'      => $GROUP,
        'content'       => $caption !== '' ? $caption : '[视频]',
        'msg_type'      => 5,
        'extra'         => $extra,
        'admin_id'      => 0,
    ];
    echo "SEND #{$n} vod={$vodId} " . mb_substr($name, 0, 40) . "\n";
    if ($dry) {
        $ok++;
        continue;
    }
    list($success, $msg, $res) = bridgeSend($bridgeUrl, $bridgeKey, $payload);
    if (!$success) {
        // 桥接失败则直写库（与 Imagent fallback 一致）
        echo "  bridge fail: {$msg} → db fallback\n";
        $now = time();
        $msgId = sprintf('m%s%04d', date('YmdHis'), random_int(0, 9999));
        $st = $pdo->prepare("INSERT INTO {$p}chat_messages
            (msg_id,conversation_type,conversation_id,group_id,from_user_id,to_user_id,msg_type,content,extra,status,createtime)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([
            $msgId,
            2,
            (string)$GROUP,
            $GROUP,
            $SENDER,
            0,
            5,
            mb_substr($payload['content'], 0, 2000),
            json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            1,
            $now,
        ]);
        $id = (int)$pdo->lastInsertId();
        $row = $pdo->query("SELECT * FROM {$p}chat_messages WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            if (!empty($row['extra']) && is_string($row['extra'])) {
                $decoded = json_decode($row['extra'], true);
                if (is_array($decoded)) {
                    $row['extra'] = $decoded;
                }
            }
            publishFallback($bridgeUrl, $bridgeKey, $row);
        }
        echo "  OK db id={$id}\n";
        $ok++;
    } else {
        $mid = (int)(($res['message']['id'] ?? $res['id'] ?? 0));
        echo "  OK bridge id={$mid}\n";
        $ok++;
    }
    $sentMap[$vodId] = 1;
    usleep(350000); // 略停，避免刷爆
}

// 持久化已发 vod_id（最多留 2 万）
$ids = array_keys($sentMap);
sort($ids);
if (count($ids) > 20000) {
    $ids = array_slice($ids, -20000);
}
if (!is_dir(dirname($STATE))) {
    mkdir(dirname($STATE), 0755, true);
}
file_put_contents($STATE, json_encode($ids));
echo "done ok={$ok} skip_dup={$skip} fail={$fail} state=" . count($ids) . "\n";
