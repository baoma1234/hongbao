<?php
/**
 * 重启后冒烟：FairProof + DB 字段 + 公开 API +（可选）发一笔拼手气
 * php scripts/test_fair_after_restart.php
 */
$root = dirname(__DIR__);
require $root . '/im-server/vendor/autoload.php';

use Im\Support\FairProof;
use Im\Support\Db;

$cfg = require $root . '/im-server/config/app.php';
Db::init($cfg['db']);

$ok = 0;
$fail = 0;
function pass($m) { global $ok; $ok++; echo "[PASS] $m\n"; }
function fail($m) { global $fail; $fail++; echo "[FAIL] $m\n"; }

// 1) FairProof unit
$cents = [101, 202, 303, 404, 505];
$sum = array_sum($cents);
$fair = FairProof::create('RP_SMOKE_' . time(), 2, $sum, count($cents), 0, $cents);
if (FairProof::verifyPayload($fair['fair_payload'], $fair['fair_hash'])) {
    pass('FairProof SHA-256 verify');
} else {
    fail('FairProof SHA-256 verify');
}

// 2) columns exist
$cols = Db::fetchAll('SHOW COLUMNS FROM ' . Db::table('chat_red_packets') . " LIKE 'fair_%'");
$names = array_column($cols, 'Field');
$need = ['fair_hash', 'fair_seed', 'fair_cents', 'fair_payload', 'fair_revealed_at'];
$missing = array_diff($need, $names);
if (!$missing) {
    pass('DB fair_* columns');
} else {
    fail('DB missing: ' . implode(',', $missing));
}

// 3) admin bridge health
$ch = curl_init('http://127.0.0.1:7273/health');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
$raw = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$hj = json_decode((string)$raw, true);
if ($code === 200 && !empty($hj['ok'])) {
    pass('admin bridge /health');
} else {
    fail('admin bridge /health code=' . $code . ' body=' . substr((string)$raw, 0, 120));
}

// 4) public API (expect not found for fake no)
$api = 'http://127.0.0.1/api/chatfair/fair?packet_no=RP_NOT_EXIST_XYZ';
// try common ports from site
$candidates = [
    'http://127.0.0.1:7111/api/chatfair/fair?packet_no=RP_NOT_EXIST_XYZ',
    'http://127.0.0.1/api/chatfair/fair?packet_no=RP_NOT_EXIST_XYZ',
];
$apiOk = false;
$apiMsg = '';
foreach ($candidates as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($errno = 0) {}
    $j = json_decode((string)$raw, true);
    if (is_array($j) && isset($j['code'])) {
        $apiOk = true;
        $apiMsg = $url . ' => code=' . $j['code'] . ' msg=' . ($j['msg'] ?? '');
        break;
    }
    $apiMsg = $url . ' http=' . $code . ' err=' . $err . ' body=' . substr((string)$raw, 0, 80);
}
if ($apiOk) {
    pass('API chatfair/fair reachable (' . $apiMsg . ')');
} else {
    fail('API chatfair/fair (' . $apiMsg . ')');
}

// 5) latest packet with fair_hash (if any new ones after restart)
$row = Db::fetch(
    'SELECT packet_no,packet_type,fair_hash,fair_revealed_at,status FROM ' . Db::table('chat_red_packets')
    . " WHERE fair_hash<>'' ORDER BY id DESC LIMIT 1"
);
if ($row) {
    pass('found fair packet ' . $row['packet_no'] . ' type=' . $row['packet_type'] . ' revealed=' . (int)$row['fair_revealed_at']);
    $view = FairProof::publicView($row, []);
    if ($view['fair_hash'] === $row['fair_hash']) {
        pass('publicView hash present');
    } else {
        fail('publicView hash');
    }
} else {
    echo "[INFO] no fair_hash packets yet (send a 拼手气/扫雷 after restart to populate)\n";
}

// 6) optional: send via admin bridge if agent exists with balance
$fanshub = include $root . '/application/extra/fanshub.php';
$key = (string)($fanshub['im_admin']['bridge_key'] ?? '');
$agent = Db::fetch('SELECT user_id FROM ' . Db::table('chat_agent_accounts') . ' WHERE status=1 ORDER BY id ASC LIMIT 1');
$group = Db::fetch('SELECT id FROM ' . Db::table('chat_groups') . ' WHERE status IN (1,3) ORDER BY id DESC LIMIT 1');
if ($key && $agent && $group) {
    $uid = (int)$agent['user_id'];
    $gid = (int)$group['id'];
    // ensure membership
    $mem = Db::fetch(
        'SELECT id FROM ' . Db::table('chat_group_members') . ' WHERE group_id=? AND user_id=? LIMIT 1',
        [$gid, $uid]
    );
    if (!$mem) {
        echo "[INFO] agent not in group $gid, skip live send\n";
    } else {
        $payload = [
            'admin_key'     => $key,
            'agent_user_id' => $uid,
            'scope_type'    => 2,
            'group_id'      => $gid,
            'packet_type'   => 2,
            'total_amount'  => 10,
            'total_count'   => 5,
            'blessing'      => '公平性冒烟测试',
        ];
        $ch = curl_init('http://127.0.0.1:7273/agent/send_redpacket');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode((string)$raw, true);
        if ($code === 200 && !empty($j['packet_no'])) {
            $pno = $j['packet_no'];
            $dbp = Db::fetch('SELECT fair_hash,fair_seed,fair_cents,fair_payload,fair_revealed_at FROM ' . Db::table('chat_red_packets') . ' WHERE packet_no=? LIMIT 1', [$pno]);
            if ($dbp && strlen((string)$dbp['fair_hash']) === 64 && (string)$dbp['fair_seed'] !== '') {
                pass('live send packet_no=' . $pno . ' fair_hash=' . substr($dbp['fair_hash'], 0, 16) . '...');
                // reveal simulation: mark revealed and verify API-style view
                Db::exec('UPDATE ' . Db::table('chat_red_packets') . ' SET fair_revealed_at=? WHERE packet_no=?', [time(), $pno]);
                $dbp2 = Db::fetch('SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE packet_no=? LIMIT 1', [$pno]);
                $pv = FairProof::publicView($dbp2, []);
                if (!empty($pv['hash_ok']) && !empty($pv['sum_ok'])) {
                    pass('reveal + hash_ok + sum_ok');
                } else {
                    fail('reveal verify hash_ok=' . json_encode($pv['hash_ok'] ?? null) . ' sum_ok=' . json_encode($pv['sum_ok'] ?? null));
                }
                // hit HTTP API with real packet
                foreach ($candidates as $baseUrl) {
                    $url = preg_replace('/packet_no=.*/', 'packet_no=' . urlencode($pno), $baseUrl);
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
                    $raw = curl_exec($ch);
                    curl_close($ch);
                    $aj = json_decode((string)$raw, true);
                    if (is_array($aj) && ($aj['code'] ?? 0) == 1 && !empty($aj['data']['fair_hash'])) {
                        pass('HTTP fair API real packet ' . $pno);
                        break;
                    }
                }
            } else {
                fail('live send ok but fair_hash missing: ' . substr((string)$raw, 0, 200));
            }
        } else {
            echo "[INFO] live send skipped/failed: http=$code body=" . substr((string)$raw, 0, 200) . "\n";
        }
    }
} else {
    echo "[INFO] no agent/group/key for live send\n";
}

echo "----\nPASS=$ok FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
