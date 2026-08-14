<?php
/**
 * 裂变资格表增加 claimed / claimed_at；历史已开奖金额视为已入账
 * php scripts/patch_fans_fission_claim.php
 */
$root = dirname(__DIR__);
$ini = parse_ini_file($root . '/.env', true);
if (empty($ini['database'])) {
    fwrite(STDERR, "missing .env database\n");
    exit(1);
}
$d = $ini['database'];
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $d['hostname'],
        (int)($d['hostport'] ?? 3306),
        $d['database']
    ),
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pre = $d['prefix'] ?? 'fa_';
$qual = $pre . 'fans_fission_qual';

$cols = [];
foreach ($pdo->query("SHOW COLUMNS FROM `{$qual}`") as $r) {
    $cols[$r['Field']] = true;
}
if (empty($cols['claimed'])) {
    $pdo->exec(
        "ALTER TABLE `{$qual}` ADD COLUMN `claimed` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT '是否已领奖入账' AFTER `win_amount`"
    );
    echo "OK added claimed\n";
} else {
    echo "skip claimed\n";
}
if (empty($cols['claimed_at'])) {
    $pdo->exec(
        "ALTER TABLE `{$qual}` ADD COLUMN `claimed_at` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '领奖时间' AFTER `claimed`"
    );
    echo "OK added claimed_at\n";
} else {
    echo "skip claimed_at\n";
}

// 旧版开奖已自动入账：有金额且未标记的视为已领，避免重复发奖
$n = $pdo->exec(
    "UPDATE `{$qual}` SET `claimed`=1, `claimed_at`=IF(`claimed_at`>0,`claimed_at`,UNIX_TIMESTAMP())"
    . " WHERE `win_amount` IS NOT NULL AND `claimed`=0"
);
echo "OK marked legacy claimed rows={$n}\n";
