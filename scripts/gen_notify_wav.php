<?php
$dir = dirname(__DIR__) . '/uni-999/src/static/sound';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$sr = 22050;
$dur = 0.18;
$freq = 880.0;
$n = (int)($sr * $dur);
$data = '';
for ($i = 0; $i < $n; $i++) {
    $t = $i / $sr;
    $env = exp(-$t * 12);
    $s = (int)(16000 * $env * sin(2 * M_PI * $freq * $t));
    $data .= pack('v', $s & 0xffff);
}
$dataSize = strlen($data);
$hdr = pack('a4Va4a4VvvVVvva4V', 'RIFF', 36 + $dataSize, 'WAVE', 'fmt ', 16, 1, 1, $sr, $sr * 2, 2, 16, 'data', $dataSize);
$path = $dir . '/notify.wav';
file_put_contents($path, $hdr . $data);
echo "OK {$path} bytes=" . filesize($path) . PHP_EOL;
