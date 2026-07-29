<?php
$cfg = include dirname(__DIR__) . '/application/extra/fanshub.php';
$s = $cfg['marquee_text'];
$p = strpos($s, '成功');
echo "around 成功:\n";
echo bin2hex(substr($s, $p, 24)) . "\n";
$lines = preg_split('/\R+/', $s);
echo "line count: " . count($lines) . "\n";
foreach ($lines as $i => $line) {
    echo "[$i] valid=" . (mb_check_encoding($line, 'UTF-8') ? 'yes' : 'no') . " len=" . strlen($line) . " $line\n";
}
