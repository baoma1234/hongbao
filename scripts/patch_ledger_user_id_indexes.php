<?php
$ini = @parse_ini_file(dirname(__DIR__) . '/.env', true);
$d = $ini['database'] ?? [];
$m = new mysqli($d['hostname'] ?? '127.0.0.1', $d['username'] ?? 'root', $d['password'] ?? '', $d['database'] ?? '', (int)($d['hostport'] ?? 3306));
if ($m->connect_error) {
    fwrite(STDERR, $m->connect_error . "\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_OFF);
$stmts = [
    "ALTER TABLE `fa_fans_ledger` ADD INDEX `idx_user_id_id` (`user_id`, `id`)",
    "ALTER TABLE `fa_fans_ledger` ADD INDEX `idx_user_type_id` (`user_id`, `type`, `id`)",
];
foreach ($stmts as $sql) {
    if ($m->query($sql)) {
        echo "OK: {$sql}\n";
    } else {
        $err = $m->error;
        if (stripos($err, 'Duplicate') !== false) {
            echo "SKIP exists: {$sql}\n";
        } else {
            echo "FAIL: {$err}\n{$sql}\n";
        }
    }
}
