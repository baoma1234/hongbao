<?php
$path = 'C:/Users/Administrator/.cursor/projects/c-wwwroot-caijin-com-7111/agent-transcripts/ef6dca9a-4416-4983-8d8c-1ab955c7b86b/ef6dca9a-4416-4983-8d8c-1ab955c7b86b.jsonl';
$fh = fopen($path, 'r');
$n = 0;
while (($line = fgets($fh)) !== false) {
    $n++;
    if ($n < 4480 || $n > 4510) continue;
    $j = json_decode($line, true);
    $c = $j['message']['content'] ?? null;
    if (!is_array($c)) continue;
    foreach ($c as $part) {
        if (($part['type'] ?? '') === 'text') {
            echo "LINE $n TEXT: " . mb_substr($part['text'] ?? '', 0, 400) . "\n";
        }
        if (($part['type'] ?? '') === 'tool_use') {
            $name = $part['name'] ?? '';
            $in = $part['input'] ?? [];
            if ($name === 'Shell') {
                echo "LINE $n Shell: " . mb_substr($in['command'] ?? '', 0, 600) . "\n---\n";
            }
            if ($name === 'Write' || $name === 'StrReplace') {
                $p = $in['path'] ?? '';
                if (strpos($p, 'bottom') !== false || strpos($p, 'tab') !== false || strpos($p, 'home.png') !== false) {
                    echo "LINE $n $name path=$p\n";
                    if (isset($in['old_string'])) echo "OLD: " . mb_substr($in['old_string'], 0, 700) . "\n";
                    if (isset($in['new_string'])) echo "NEW: " . mb_substr($in['new_string'], 0, 700) . "\n";
                    if (isset($in['contents'])) echo "CONTENTS head: " . mb_substr($in['contents'], 0, 300) . "\n";
                    echo "---\n";
                }
            }
        }
    }
}
fclose($fh);
