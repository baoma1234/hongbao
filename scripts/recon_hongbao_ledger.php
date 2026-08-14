<?php
/**
 * 对账：余额 vs 末笔 hongbao_after vs 自账户创建以来的流水合计。
 * php scripts/recon_hongbao_ledger.php [limit=50]
 *
 * 说明：账户被删后重建会导致「历史流水仍在、余额从 0 重计」，
 * 此时 SUM(全量流水)≠余额属预期噪声；以 last_after / led_since_account 为准。
 */
$limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 50;
$ini = @parse_ini_file(dirname(__DIR__) . '/.env', true);
if (!is_array($ini)) {
    fwrite(STDERR, ".env missing\n");
    exit(1);
}
$d = $ini['database'] ?? [];
$mysqli = new mysqli(
    $d['hostname'] ?? '127.0.0.1',
    $d['username'] ?? 'root',
    $d['password'] ?? '',
    $d['database'] ?? '',
    (int)($d['hostport'] ?? 3306)
);
if ($mysqli->connect_error) {
    fwrite(STDERR, $mysqli->connect_error . "\n");
    exit(1);
}

$sql = "SELECT a.user_id,
  ROUND(a.hongbao,2) AS bal,
  a.createtime AS acc_ct,
  (SELECT ROUND(l.hongbao_after,2) FROM fa_fans_ledger l WHERE l.user_id=a.user_id ORDER BY l.id DESC LIMIT 1) AS last_after,
  (SELECT ROUND(SUM(l2.hongbao_change),2) FROM fa_fans_ledger l2 WHERE l2.user_id=a.user_id AND l2.createtime>=a.createtime) AS led_since,
  (SELECT ROUND(SUM(l3.hongbao_change),2) FROM fa_fans_ledger l3 WHERE l3.user_id=a.user_id) AS led_all
  FROM fa_fans_account a
  HAVING ABS(bal - IFNULL(last_after, bal)) > 0.009
      OR ABS(bal - IFNULL(led_since, 0)) > 0.009
  ORDER BY GREATEST(ABS(bal - IFNULL(last_after, bal)), ABS(bal - IFNULL(led_since, 0))) DESC
  LIMIT " . (int)$limit;

$r = $mysqli->query($sql);
if (!$r) {
    fwrite(STDERR, $mysqli->error . "\n");
    exit(1);
}
$n = 0;
while ($row = $r->fetch_assoc()) {
    $n++;
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}
echo "mismatches_shown={$n} limit={$limit}\n";
