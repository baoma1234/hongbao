<?php
/**
 * Install online-coop withdraw partition/channel + set rates for channel 51/52.
 */
$root = dirname(__DIR__);
$envFile = $root . '/.env';
$section = '';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
        if ($line[0] === '[' && substr($line, -1) === ']') {
            $section = trim($line, '[]');
            continue;
        }
        if (strpos($line, '=') === false) continue;
        list($k, $v) = array_map('trim', explode('=', $line, 2));
        $v = trim($v, "\"'");
        $full = $section !== '' ? ($section . '.' . $k) : $k;
        putenv($full . '=' . $v);
        putenv($k . '=' . $v);
    }
}
$m = new mysqli(
    getenv('database.hostname') ?: '127.0.0.1',
    getenv('database.username') ?: 'root',
    getenv('database.password') ?: '',
    getenv('database.database') ?: 'fastadmin',
    (int)(getenv('database.hostport') ?: 3306)
);
if ($m->connect_error) {
    fwrite(STDERR, $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');
$p = getenv('database.prefix') ?: 'fa_';
$now = time();

// 1) rates for 51 / 52
foreach ([51, 52] as $cid) {
    $r = $m->query("SELECT id, config FROM {$p}fans_pay_channel WHERE id=" . (int)$cid);
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row) {
        echo "channel {$cid} missing\n";
        continue;
    }
    $cfg = json_decode((string)$row['config'], true);
    if (!is_array($cfg)) $cfg = [];
    $cfg['callback_exchange_rate'] = '7';
    $cfg['exchange_rate'] = '7';
    $json = $m->real_escape_string(json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $m->query("UPDATE {$p}fans_pay_channel SET config='{$json}', updatetime={$now} WHERE id=" . (int)$cid);
    echo "channel {$cid} exchange_rate=7\n";
}

// 2) partition online_coop
$partId = 0;
$r = $m->query("SELECT id FROM {$p}fans_pay_partition WHERE code='online_coop' AND type='withdraw' LIMIT 1");
if ($r && ($row = $r->fetch_assoc())) {
    $partId = (int)$row['id'];
    echo "partition online_coop exists id={$partId}\n";
} else {
    $m->query("INSERT INTO {$p}fans_pay_partition (type,code,name,bind_mode,weigh,status,createtime,updatetime) VALUES (
        'withdraw','online_coop','线上合作','none',50,'normal',{$now},{$now}
    )");
    $partId = (int)$m->insert_id;
    echo "created partition online_coop id={$partId}\n";
}

// 3) channel
$cfg = json_encode([
    'withdraw_mode' => 'online_coop',
    'platforms' => ['555'],
    'message' => '线上合作提现已提交，等待人工审核出款。',
], JSON_UNESCAPED_UNICODE);
$cfgEsc = $m->real_escape_string($cfg);
$r = $m->query("SELECT id FROM {$p}fans_pay_channel WHERE type='withdraw' AND handler='manual' AND name='线上合作' LIMIT 1");
if ($r && ($row = $r->fetch_assoc())) {
    $id = (int)$row['id'];
    $m->query("UPDATE {$p}fans_pay_channel SET partition_id={$partId}, config='{$cfgEsc}', status='normal', tip='主站账号提现，人工审核', updatetime={$now} WHERE id={$id}");
    echo "updated channel 线上合作 id={$id}\n";
} else {
    $m->query("INSERT INTO {$p}fans_pay_channel
        (type,partition_id,name,icon,tip,handler,pay_channel,config,min_amount,max_amount,weigh,status,createtime,updatetime)
        VALUES (
            'withdraw',{$partId},'线上合作','','主站账号提现，人工审核','manual','',
            '{$cfgEsc}',10,50000,80,'normal',{$now},{$now}
        )");
    echo "created channel 线上合作 id=" . $m->insert_id . "\n";
}

echo "done\n";
