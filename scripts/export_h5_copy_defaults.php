<?php
$file = dirname(__DIR__) . '/application/extra/fanshub_h5_copy.php';
$copy = include $file;
if (!is_array($copy)) {
    fwrite(STDERR, "bad copy file\n");
    exit(1);
}
$js = 'window.FANSHUB_COPY_DEFAULTS=' . json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
foreach (['888', 'fanshub', 'fanshubtest'] as $dir) {
    $path = dirname(__DIR__) . '/public/' . $dir . '/copy.defaults.js';
    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $js);
    echo $dir, " ok\n";
}
