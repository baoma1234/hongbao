<?php
$root = dirname(__DIR__);
$c = include $root . '/application/extra/fanshub_h5_copy.php';
$bad = [];
foreach ($c as $k => $v) {
    if (!is_string($v)) continue;
    $hasRepl = (strpos($v, "\xEF\xBF\xBD") !== false) || (strpos($v, '�') !== false);
    // truncated mid-word: ends with incomplete sense or known bad patterns
    $suspicious = $hasRepl;
    // emoji followed by broken CJK often means corruption
    if (preg_match('/[\x{1F300}-\x{1FAFF}].*[�]/u', $v)) $suspicious = true;
    if ($suspicious) {
        $bad[$k] = $v;
    }
}
echo 'bad_count=' . count($bad) . "\n";
foreach ($bad as $k => $v) {
    echo $k . ' HEX=' . bin2hex(mb_substr($v, 0, 40)) . "\n";
    echo $k . ' VAL=' . $v . "\n\n";
}
// also check total keys with replacement in raw file
$raw = file_get_contents($root . '/application/extra/fanshub_h5_copy.php');
echo 'file_efbfbd=' . substr_count($raw, "\xEF\xBF\xBD") . "\n";
echo 'file_size=' . strlen($raw) . "\n";

// seed script?
echo "\n--- seed ---\n";
echo substr(file_get_contents($root . '/scripts/seed_h5_copy_defaults.php'), 0, 500);
