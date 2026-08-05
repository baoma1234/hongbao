<?php
/**
 * 重启后完整冒烟：发拼手气红包 → 写 fair_hash → 公开 API 校验
 */
$root = dirname(__DIR__);
require $root . '/im-server/vendor/autoload.php';

use Im\Support\Db;
use Im\Support\FairProof;
use Im\Service\MessageService;
use Im\Service\GroupService;
use Im\Service\RedPacketService;

$cfg = require $root . '/im-server/config/app.php';
Db::init($cfg['db']);
\Im\Support\RedisClient::init($cfg['redis']);

$ok = 0; $fail = 0;
function pass($m){ global $ok; $ok++; echo "[PASS] $m\n"; }
function fail($m){ global $fail; $fail++; echo "[FAIL] $m\n"; }

// health
$ch = curl_init('http://127.0.0.1:17273/health');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
$raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$j = json_decode((string)$raw, true);
($code===200 && !empty($j['ok'])) ? pass('IM admin bridge up') : fail('IM admin bridge');

$fanshub = include $root . '/application/extra/fanshub.php';
$key = (string)($fanshub['im_admin']['bridge_key'] ?? '');
$agentUid = 56960815;
$groupId = 1;

$payload = [
    'admin_key' => $key,
    'agent_user_id' => $agentUid,
    'scope_type' => 2,
    'group_id' => $groupId,
    'packet_type' => 2,
    'total_amount' => 10,
    'total_count' => 5,
    'blessing' => '公平性冒烟',
];
$ch = curl_init('http://127.0.0.1:17273/agent/send_redpacket');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$raw = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$j = json_decode((string)$raw, true);
echo "[INFO] send http=$code body=" . substr((string)$raw, 0, 300) . "\n";

if ($code === 200 && !empty($j['packet_no'])) {
    $pno = $j['packet_no'];
    pass('send packet_no=' . $pno);
    $row = Db::fetch('SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE packet_no=? LIMIT 1', [$pno]);
    if ($row && strlen((string)$row['fair_hash']) === 64) {
        pass('fair_hash written ' . substr($row['fair_hash'], 0, 16) . '...');
    } else {
        fail('fair_hash missing');
    }
    // reveal for verify page
    Db::exec('UPDATE ' . Db::table('chat_red_packets') . ' SET fair_revealed_at=? WHERE packet_no=?', [time(), $pno]);
    $row2 = Db::fetch('SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE packet_no=? LIMIT 1', [$pno]);
    $pv = FairProof::publicView($row2, []);
    (!empty($pv['hash_ok']) && !empty($pv['sum_ok'])) ? pass('hash_ok+sum_ok after reveal') : fail('verify after reveal');

    // HTTP API via fanshub/rpfair
    $url = 'http://127.0.0.1:7111/api/fanshub/rpfair?packet_no=' . urlencode($pno);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $aj = json_decode((string)$raw, true);
    echo "[INFO] rpfair http=$code body=" . substr((string)$raw, 0, 250) . "\n";
    if ($code === 200 && ($aj['code'] ?? 0) == 1 && !empty($aj['data']['fair_hash'])) {
        pass('HTTP /api/fanshub/rpfair');
        $local = hash('sha256', (string)$aj['data']['fair_payload']);
        ($local === strtolower((string)$aj['data']['fair_hash'])) ? pass('browser-side SHA256 match') : fail('SHA256 mismatch');
    } else {
        fail('HTTP /api/fanshub/rpfair');
    }

    // also try chatfair
    $url2 = 'http://127.0.0.1:7111/api/chatfair/fair?packet_no=' . urlencode($pno);
    $ch = curl_init($url2);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $raw2 = curl_exec($ch);
    $code2 = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $aj2 = json_decode((string)$raw2, true);
    if ($code2 === 200 && ($aj2['code'] ?? 0) == 1) {
        pass('HTTP /api/chatfair/fair also ok');
    } else {
        echo "[INFO] chatfair still unavailable via web (use fanshub/rpfair)\n";
    }

    echo "[INFO] verify page: http://127.0.0.1:7111/888/fair-verify.html?packet_no={$pno}\n";
} else {
    fail('live send failed');
}

echo "----\nPASS=$ok FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
