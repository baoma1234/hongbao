<?php
/**
 * 对账：fa_fans_account.hongbao vs 流水累计（抽样/全量）。
 * php scripts/recon_hongbao_ledger.php [limit=50]
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
$sql = "SELECT a.user_id, ROUND(a.hongbao,2) AS bal,
  ROUND(IFNULL((SELECT SUM(hongbao_change) FROM fa_fans_ledger l WHERE l.user_id=a.user_id),0),2) AS led
  FROM fa_fans_account a
  HAVING ABS(bal - led) > 0.009
  ORDER BY ABS(bal - led) DESC
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
