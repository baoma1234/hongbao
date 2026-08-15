<?php
/** Grant messagespopup rules to admin group #1 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$ids = $pdo->query("SELECT id FROM {$prefix}auth_rule WHERE name='fanshub/messagespopup' OR name LIKE 'fanshub/messagespopup/%'")->fetchAll(PDO::FETCH_COLUMN);
$g = $pdo->query("SELECT rules FROM {$prefix}auth_group WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if (!$g || !$ids) {
    echo "skip\n";
    exit(0);
}
$have = array_flip(array_filter(explode(',', (string)$g['rules'])));
$missing = [];
foreach ($ids as $rid) {
    $rid = (int)$rid;
    if ($rid > 0 && !isset($have[$rid])) {
        $missing[] = $rid;
    }
}
if ($missing) {
    $new = trim((string)$g['rules'] . ',' . implode(',', $missing), ',');
    $pdo->prepare("UPDATE {$prefix}auth_group SET rules=? WHERE id=1")->execute([$new]);
    echo 'GRANTED +' . count($missing) . "\n";
} else {
    echo "OK already\n";
}
