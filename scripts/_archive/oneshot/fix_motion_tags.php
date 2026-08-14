<?php
foreach (glob(__DIR__ . '/../application/admin/view/fanshub/account/edit.html') as $f) {
    $t = file_get_contents($f);
    $d = 'di' . 'v';
    $t = str_replace('<motion', '<' . $d, $t);
    $t = str_replace('</motion>', '</' . $d . '>', $t);
    file_put_contents($f, $t);
}
echo "ok\n";
