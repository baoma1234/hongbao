<?php
/**
 * Drill one user: account vs ledger + recent rows.
 * php scripts/recon_hongbao_user.php <user_id>
 */
$uid = (int)($argv[1] ?? 0);
if ($uid <= 0) {
    fwrite(STDERR, "usage: php scripts/recon_hongbao_user.php <user_id>\n");
    exit(1);
}
$ini = @parse_ini_file(dirname(__DIR__) . '/.env', true);
$d = $ini['database'] ?? [];
$m = new mysqli($d['hostname'] ?? '127.0.0.1', $d['username'] ?? 'root', $d['password'] ?? '', $d['database'] ?? '', (int)($d['hostport'] ?? 3306));
if ($m->connect_error) {
    fwrite(STDERR, $m->connect_error . "\n");
    exit(1);
}
$acc = $m->query("SELECT user_id, hongbao, hongbao_frozen, rights, status, createtime FROM fa_fans_account WHERE user_id={$uid}")->fetch_assoc();
echo "account: " . json_encode($acc, JSON_UNESCAPED_UNICODE) . "\n";
$sum = $m->query("SELECT COUNT(*) c, ROUND(SUM(hongbao_change),2) s, MIN(createtime) t0, MAX(createtime) t1 FROM fa_fans_ledger WHERE user_id={$uid}")->fetch_assoc();
echo "ledger_sum: " . json_encode($sum, JSON_UNESCAPED_UNICODE) . "\n";
$by = $m->query("SELECT type, COUNT(*) c, ROUND(SUM(hongbao_change),2) s FROM fa_fans_ledger WHERE user_id={$uid} GROUP BY type ORDER BY ABS(SUM(hongbao_change)) DESC");
echo "by_type:\n";
while ($r = $by->fetch_assoc()) {
    echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
$recent = $m->query("SELECT id,type,hongbao_change,hongbao_after,remark,channel,biz_no,createtime FROM fa_fans_ledger WHERE user_id={$uid} ORDER BY id DESC LIMIT 15");
echo "recent:\n";
while ($r = $recent->fetch_assoc()) {
    echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
$first = $m->query("SELECT id,type,hongbao_change,hongbao_after,remark,createtime FROM fa_fans_ledger WHERE user_id={$uid} ORDER BY id ASC LIMIT 5");
echo "first:\n";
while ($r = $first->fetch_assoc()) {
    echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
