<?php
$root = dirname(__DIR__);
$c = include $root . '/application/extra/fanshub_h5_copy.php';
$js = 'window.FANSHUB_COPY_DEFAULTS = ' . json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
foreach (['888', 'fanshub', 'fanshubtest'] as $d) {
    $p = $root . '/public/' . $d . '/copy.defaults.js';
    if (is_dir(dirname($p))) {
        file_put_contents($p, $js);
        echo "wrote $p bytes=" . strlen($js) . "\n";
    }
}
