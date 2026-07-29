<?php
$js = file_get_contents(dirname(__DIR__) . '/public/888/i18n/locales/en-PH.js');
if (!preg_match('/FANSHUB_LOCALES\[[\'"]en-PH[\'"]\]\s*=\s*(\{.*?\});/s', $js, $m)) {
    // try JSON extract differently
    $pos = strpos($js, '{');
    $end = strrpos($js, '}');
    $json = substr($js, $pos, $end - $pos + 1);
} else {
    $json = $m[1];
}
$data = json_decode($json, true);
if (!$data) {
    echo "json_error=" . json_last_error_msg() . "\n";
    // strip assignment wrapper
    if (preg_match('/=\s*(\{.*\})\s*;?\s*$/s', $js, $m2)) {
        $data = json_decode($m2[1], true);
    }
}
$keys = ['uid_label','uid_submit_btn','footer_line1','footer_line2','footer_line3','settle_btn_low','settle_btn_ready','marquee_text','marquee_fallback','match_card_title'];
foreach ($keys as $k) {
    $v = $data[$k] ?? 'MISS';
    $isZh = preg_match('/[\x{4e00}-\x{9fff}]/u', $v);
    echo $k . '|' . ($isZh ? 'ZH' : 'OK') . '|' . mb_substr($v, 0, 60) . "\n";
}
echo 'total=' . count($data) . "\n";
