<?php
/**
 * Sync brand copy to 红宝, rebuild locales/copy.defaults, scan DB.
 */
$root = dirname(__DIR__);

// 1) Align fanshub_h5_copy.php brand strings (555.bio -> 红宝), keep example UIDs like 555bio
$h5 = $root . '/application/extra/fanshub_h5_copy.php';
$raw = file_get_contents($h5);
$before = $raw;
$raw = str_replace(['抢红包', '抢红宝'], '红宝', $raw);
// Brand domain -> 红宝 (order matters: longer phrases first already covered by substring)
$raw = str_replace('555.bio', '红宝', $raw);
if ($raw !== $before) {
    file_put_contents($h5, $raw);
    echo "OK fanshub_h5_copy.php brand synced\n";
} else {
    echo "SKIP fanshub_h5_copy.php (no change)\n";
}

// Also ensure i18n locale PHP files
foreach (glob($root . '/application/extra/i18n/*.php') as $f) {
    $t = file_get_contents($f);
    $n = str_replace(['抢红包', '抢红宝'], '红宝', $t);
    $n = str_replace('555.bio', '红宝', $n);
    if ($n !== $t) {
        file_put_contents($f, $n);
        echo "OK " . basename($f) . "\n";
    }
}

// mobile-app user facing
$mobileFiles = [
    $root . '/mobile-app/README.md',
    $root . '/mobile-app/src/manifest.json',
    $root . '/mobile-app/src/index.html',
    $root . '/mobile-app/package.json',
    $root . '/mobile-app/app.config.json',
    $root . '/mobile-app/capacitor.config.json',
    $root . '/mobile-app/android/app/src/main/assets/capacitor.config.json',
    $root . '/mobile-app/dist/index.html',
    $root . '/mobile-app/android/app/src/main/assets/public/index.html',
    $root . '/mobile-app/android/app/src/main/res/values/strings.xml',
];
foreach ($mobileFiles as $f) {
    if (!is_file($f)) continue;
    $t = file_get_contents($f);
    $n = str_replace(['抢红包', '抢红宝'], '红宝', $t);
    if ($n !== $t) {
        file_put_contents($f, $n);
        echo "OK mobile " . str_replace($root . DIRECTORY_SEPARATOR, '', $f) . "\n";
    }
}

// IM user-facing remaining (skip lua comments)
foreach ([
    $root . '/im-server/App/Handler/MessageRouter.php',
    $root . '/im-server/App/Service/RedPacketService.php',
    $root . '/im-server/App/Service/GroupService.php',
    $root . '/public/888/js/chat/02-room.js',
    $root . '/public/888/js/chat/03-rp.js',
] as $f) {
    if (!is_file($f)) continue;
    $t = file_get_contents($f);
    $n = str_replace(['抢红包', '抢红宝'], '红宝', $t);
    if ($n !== $t) {
        file_put_contents($f, $n);
        echo "OK " . basename($f) . "\n";
    }
}

// DB scan + update fa_config name if needed
try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=caijin_com_7111;charset=utf8mb4',
        'caijin_com_7111',
        'zJ3EkWE47y',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "--- DB fa_config ---\n";
    $stmt = $pdo->query("SELECT id, name, title, LEFT(CAST(value AS CHAR), 160) AS v FROM fa_config WHERE CAST(value AS CHAR) LIKE '%抢红%' OR CAST(value AS CHAR) LIKE '%红宝%' OR name IN ('name') LIMIT 40");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo "(no matching rows for 抢红/红宝; checking name)\n";
        $rows = $pdo->query("SELECT id, name, title, LEFT(CAST(value AS CHAR), 160) AS v FROM fa_config WHERE name='name'")->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($rows as $r) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
    $upd = $pdo->exec("UPDATE fa_config SET value='红宝' WHERE name='name' AND value IN ('抢红包','抢红宝','555.bio')");
    echo "fa_config name updated_rows={$upd}\n";

    // Any text columns with 抢红包 in fanshub-related tables
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='caijin_com_7111' AND (TABLE_NAME LIKE '%fanshub%' OR TABLE_NAME LIKE '%group%' OR TABLE_NAME LIKE '%config%')")->fetchAll(PDO::FETCH_COLUMN);
    echo "--- tables scanned for 抢红包 ---\n";
    foreach ($tables as $table) {
        $cols = $pdo->query("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='caijin_com_7111' AND TABLE_NAME=" . $pdo->quote($table) . " AND DATA_TYPE IN ('varchar','text','mediumtext','longtext','char')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            $c = $col['COLUMN_NAME'];
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE `{$c}` LIKE '%抢红包%' OR `{$c}` LIKE '%抢红宝%'")->fetchColumn();
            if ($cnt > 0) {
                echo "HIT {$table}.{$c} count={$cnt}\n";
                $pdo->exec("UPDATE `{$table}` SET `{$c}` = REPLACE(REPLACE(`{$c}`, '抢红包', '红宝'), '抢红宝', '红宝') WHERE `{$c}` LIKE '%抢红包%' OR `{$c}` LIKE '%抢红宝%'");
                echo "  updated\n";
            }
        }
    }
} catch (Throwable $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
}

echo "DONE prep\n";
