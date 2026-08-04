<?php
/**
 * Ensure auth rule: fanshub/withdraworder/approve
 */
$ini = @parse_ini_file(dirname(__DIR__) . '/.env', true);
$d = $ini['database'] ?? [];
$m = new mysqli($d['hostname'] ?? '127.0.0.1', $d['username'] ?? 'root', $d['password'] ?? '', $d['database'] ?? '', (int)($d['hostport'] ?? 3306));
if ($m->connect_error) {
    fwrite(STDERR, $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');

$name = 'fanshub/withdraworder/approve';
$exists = $m->query("SELECT id FROM fa_auth_rule WHERE name='" . $m->real_escape_string($name) . "' LIMIT 1");
if ($exists && $exists->num_rows) {
    echo "SKIP rule exists\n";
    $rid = (int)$exists->fetch_assoc()['id'];
} else {
    $pidRow = $m->query("SELECT id FROM fa_auth_rule WHERE name='fanshub/withdraworder' LIMIT 1")->fetch_assoc();
    $pid = (int)($pidRow['id'] ?? 0);
    if ($pid <= 0) {
        fwrite(STDERR, "parent menu missing\n");
        exit(1);
    }
    $now = time();
    $m->query("INSERT INTO fa_auth_rule (`type`,`pid`,`name`,`title`,`icon`,`condition`,`remark`,`ismenu`,`menutype`,`extend`,`py`,`pinyin`,`createtime`,`updatetime`,`weigh`,`status`)
        VALUES ('file',{$pid},'{$name}','审核通过','fa fa-circle-o','','',0,NULL,'','','',{$now},{$now},0,'normal')");
    $rid = (int)$m->insert_id;
    echo "OK inserted rule id={$rid}\n";
}

// Attach to groups that already have markpaid
$mark = $m->query("SELECT id FROM fa_auth_rule WHERE name='fanshub/withdraworder/markpaid' LIMIT 1")->fetch_assoc();
$mid = (int)($mark['id'] ?? 0);
if ($mid > 0 && $rid > 0) {
    $groups = $m->query("SELECT id, rules FROM fa_auth_group WHERE FIND_IN_SET({$mid}, rules) OR rules='*'");
    while ($groups && ($g = $groups->fetch_assoc())) {
        $rules = trim((string)$g['rules']);
        if ($rules === '*') {
            echo "group {$g['id']}: * skip\n";
            continue;
        }
        $parts = array_filter(array_map('intval', explode(',', $rules)));
        if (in_array($rid, $parts, true)) {
            echo "group {$g['id']}: already has approve\n";
            continue;
        }
        $parts[] = $rid;
        $newRules = implode(',', $parts);
        $m->query("UPDATE fa_auth_group SET rules='" . $m->real_escape_string($newRules) . "' WHERE id=" . (int)$g['id']);
        echo "group {$g['id']}: added approve\n";
    }
}
echo "done\n";
