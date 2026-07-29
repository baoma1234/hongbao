<?php
$c = include dirname(__DIR__) . '/application/extra/fanshub_h5_copy.php';
foreach (['threshold_modal_title','threshold_modal_desc','threshold_modal_btn_open','threshold_modal_btn_later'] as $k) {
    echo "== $k ==\n" . ($c[$k] ?? 'MISS') . "\n\n";
}
