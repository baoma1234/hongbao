<?php
/** 无图标的 wanhuitong 通道设为隐藏 */
$e = parse_ini_file(dirname(__DIR__) . '/.env', true);
$d = $e['database'];
$p = new PDO(
    'mysql:host=' . $d['hostname'] . ';dbname=' . $d['database'] . ';charset=utf8mb4',
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$now = time();
$n1 = $p->exec("UPDATE {$prefix}fans_pay_channel SET status='hidden', updatetime={$now}
  WHERE handler='wanhuitong' AND (icon IS NULL OR icon='' OR TRIM(icon)='')");
$n2 = $p->exec("UPDATE {$prefix}fans_pay_channel SET status='normal', updatetime={$now}
  WHERE handler='wanhuitong' AND icon IS NOT NULL AND TRIM(icon)<>''");
echo "hidden(no icon)={$n1} shown(with icon)={$n2}\n";
$q = $p->query("SELECT type, status, COUNT(*) c FROM {$prefix}fans_pay_channel WHERE handler='wanhuitong' GROUP BY type, status");
foreach ($q as $r) {
    echo "{$r['type']} {$r['status']}: {$r['c']}\n";
}
