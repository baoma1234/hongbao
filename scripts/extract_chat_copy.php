<?php
$js = file_get_contents(dirname(__DIR__) . '/public/888/copy.defaults.js');
$json = preg_replace('/^window\.FANSHUB_COPY_DEFAULTS=/', '', trim($js));
$json = rtrim($json, "; \r\n");
$data = json_decode($json, true);
foreach ($data as $k => $v) {
    if (str_starts_with($k, 'chat_')) {
        echo $k . '=' . $v . "\n";
    }
}
