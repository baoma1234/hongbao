<?php
/**
 * Backfill fans_account.turnover from red_packet_send ledger (and mine/worst pay).
 * Uses GREATEST(current, calculated) so manual higher values are kept.
 */
$root = dirname(__DIR__);
$ini = @parse_ini_file($root . '/.env', true);
$d = $ini['database'] ?? [];
$m = new mysqli(
    $d['hostname'] ?? '127.0.0.1',
    $d['username'] ?? 'root',
    $d['password'] ?? '',
    $d['database'] ?? '',
    (int)($d['hostport'] ?? 3306)
);
if ($m->connect_error) {
    fwrite(STDERR, $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');

$sql = "UPDATE fa_fans_account a
INNER JOIN (
  SELECT user_id, ROUND(SUM(ABS(hongbao_change)), 2) AS calc_turnover
  FROM fa_fans_ledger
  WHERE type IN ('red_packet_send','red_packet_mine_pay','red_packet_worst_pay')
  GROUP BY user_id
) t ON t.user_id = a.user_id
SET a.turnover = GREATEST(COALESCE(a.turnover, 0), t.calc_turnover),
    a.updatetime = UNIX_TIMESTAMP()
WHERE t.calc_turnover > COALESCE(a.turnover, 0)";

if (!$m->query($sql)) {
    fwrite(STDERR, $m->error . PHP_EOL);
    exit(1);
}
echo 'affected_rows=' . $m->affected_rows . PHP_EOL;

$uid = 58904307;
$r = $m->query("SELECT user_id, turnover, hongbao FROM fa_fans_account WHERE user_id={$uid}");
$row = $r->fetch_assoc();
echo "user {$uid}: turnover={$row['turnover']} hongbao={$row['hongbao']}\n";
