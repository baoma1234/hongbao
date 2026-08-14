<?php
/**
 * Widen fa_fans_secret.amount so VIP balances > 99,999,999.99 can create secrets.
 * Run once per environment: php scripts/patch_secret_amount_decimal18.php
 */
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
    fwrite(STDERR, 'db fail: ' . $mysqli->connect_error . "\n");
    exit(1);
}
$sql = "ALTER TABLE `fa_fans_secret` MODIFY COLUMN `amount` decimal(18,2) NOT NULL DEFAULT '0.00'";
if (!$mysqli->query($sql)) {
    fwrite(STDERR, 'alter fail: ' . $mysqli->error . "\n");
    exit(1);
}
$r = $mysqli->query("SHOW COLUMNS FROM `fa_fans_secret` LIKE 'amount'");
$row = $r ? $r->fetch_assoc() : null;
echo "ok: " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
