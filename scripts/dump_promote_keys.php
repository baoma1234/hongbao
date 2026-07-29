<?php
$zh = include dirname(__DIR__) . '/application/extra/fanshub_h5_copy.php';
$en = include dirname(__DIR__) . '/application/extra/i18n/en-PH.php';
foreach (['promote_earn_title','promote_earn_col_type','promote_earn_type_share','promote_earn_detail_share_n'] as $k) {
    echo "ZH $k=" . ($zh[$k] ?? 'MISS') . "\n";
    echo "EN $k=" . ($en[$k] ?? 'MISS') . "\n";
}
echo 'js_has=' . (strpos(file_get_contents(dirname(__DIR__).'/public/888/js/chat/06-notice.js'), 'buildPromoteEarnMockRows') !== false ? '1':'0') . "\n";
echo 'html_has=' . (strpos(file_get_contents(dirname(__DIR__).'/public/888/partials/tab-messages.php'), 'chatPromoteEarnWrap') !== false ? '1':'0') . "\n";
