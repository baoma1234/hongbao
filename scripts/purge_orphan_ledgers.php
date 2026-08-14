<?php
/**
 * 清理「账户创建时间之前」的孤儿流水（账户曾删重建留下的）。
 * Dry-run: php scripts/purge_orphan_ledgers.php
 * Apply:   php scripts/purge_orphan_ledgers.php --apply [limit_users=100]
 */
$apply = in_array('--apply', $argv, true);
$limitUsers = 100;
foreach ($argv as $a) {
    if (ctype_digit((string)$a)) {
        $limitUsers = max(1, (int)$a);
    }
}
$ini = @parse_ini_file(dirname(__DIR__) . '/.env', true);
$d = $ini['database'] ?? [];
$m = new mysqli($d['hostname'] ?? '127.0.0.1', $d['username'] ?? 'root', $d['password'] ?? '', $d['database'] ?? '', (int)($d['hostport'] ?? 3306));
if ($m->connect_error) {
    fwrite(STDERR, $m->connect_error . "\n");
    exit(1);
}

$sql = "SELECT a.user_id, a.createtime,
  (SELECT COUNT(*) FROM fa_fans_ledger l WHERE l.user_id=a.user_id AND l.createtime < a.createtime) AS orphan_n,
  (SELECT ROUND(SUM(l.hongbao_change),2) FROM fa_fans_ledger l WHERE l.user_id=a.user_id AND l.createtime < a.createtime) AS orphan_sum
  FROM fa_fans_account a
  HAVING orphan_n > 0
  ORDER BY orphan_n DESC
  LIMIT " . (int)$limitUsers;
$r = $m->query($sql);
$users = [];
while ($row = $r->fetch_assoc()) {
    $users[] = $row;
    echo ($apply ? 'PURGE ' : 'DRY  ') . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}
if (!$apply) {
    echo "dry-run users=" . count($users) . " (pass --apply to delete orphan rows)\n";
    exit(0);
}
$deleted = 0;
foreach ($users as $u) {
    $uid = (int)$u['user_id'];
    $ct = (int)$u['createtime'];
    if ($uid <= 0 || $ct <= 0) {
        continue;
    }
    $m->query("DELETE FROM fa_fans_ledger WHERE user_id={$uid} AND createtime < {$ct}");
    $deleted += (int)$m->affected_rows;
}
echo "applied users=" . count($users) . " deleted_rows={$deleted}\n";
