<?php
$path = 'C:/Users/Administrator/.cursor/projects/c-wwwroot-caijin-com-7111/agent-transcripts/ef6dca9a-4416-4983-8d8c-1ab955c7b86b/ef6dca9a-4416-4983-8d8c-1ab955c7b86b.jsonl';
$fh = fopen($path, 'r');
$n = 0;
while (($line = fgets($fh)) !== false) {
    $n++;
    if (strpos($line, 'img/tab/home.png') === false && strpos($line, 'tab-hongbao.png') === false && strpos($line, 'bottom-action-bar') === false) {
        continue;
    }
    if (strpos($line, 'Copy-Item') !== false || strpos($line, 'tab\\home') !== false || strpos($line, 'tab/home') !== false || strpos($line, 'old_string') !== false && strpos($line, 'bottom-action-bar') !== false) {
        // extract old_string / new_string snippets around bottom-action-bar
        if (preg_match('/bottom-action-bar.{0,800}/u', $line, $m)) {
            echo "LINE $n: " . substr($m[0], 0, 500) . "\n---\n";
        }
    }
}
fclose($fh);
