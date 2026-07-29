<?php
$t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fh_check.js';
$lines = file($t);
for ($i = 420; $i <= 440; $i++) {
    echo ($i + 1) . ': ' . $lines[$i];
}
