<?php
$root = dirname(__DIR__);
function dumpRange($file, $from, $to) {
    $lines = file($file);
    echo "==== $file $from-$to ====\n";
    for ($i = $from - 1; $i < $to && $i < count($lines); $i++) {
        echo ($i + 1) . ': ' . $lines[$i];
    }
}
dumpRange($root . '/public/888/js/app-core.js', 49, 120);
dumpRange($root . '/public/888/js/chat/04-net.js', 770, 820);
dumpRange($root . '/public/888/js/chat/05-community.js', 698, 760);
dumpRange($root . '/public/888/js/chat/06-notice.js', 360, 430);
