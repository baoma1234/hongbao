<?php
$home = file_get_contents(dirname(__DIR__) . '/public/888/partials/tab-home.php');
preg_match('/id="openAccountBtnLabel"[^>]*>([^<]*)</u', $home, $m);
$v = $m[1] ?? '';
echo "val=$v\n";
echo "hex=" . bin2hex($v) . "\n";
echo "has_song=" . (strpos($v, '送') !== false ? '1' : '0') . "\n";
echo "has_period=" . (strpos($v, '。') !== false ? '1' : '0') . "\n";

preg_match('/data-copy="footer_line1"[^>]*>([^<]*)</u', $home, $m2);
echo "footer_ok=" . (strpos($m2[1] ?? '', '【') !== false ? '1' : '0') . "\n";

preg_match('/data-copy="page_hero_claim_sub"[^>]*>([^<]*)</u', $home, $m3);
file_put_contents(dirname(__DIR__) . '/scripts/_claim_sub.txt', $m3[1] ?? '');
echo "claim_sub=" . ($m3[1] ?? '') . "\n";

// notice titles
$n = file_get_contents(dirname(__DIR__) . '/public/888/js/chat/06-notice.js');
echo "has_chatT_promo=" . (strpos($n, "chatT('chat_commission_nav_promo')") !== false ? '1' : '0') . "\n";
echo "has_hard_promo=" . (strpos($n, "title.textContent = '推广结算'") !== false ? '1' : '0') . "\n";

// en-PH has swap_title
$js = file_get_contents(dirname(__DIR__) . '/public/888/i18n/locales/en-PH.js');
preg_match('/FANSHUB_LOCALES\["en-PH"\]\s*=\s*(\{.*\})\s*;?\s*$/s', $js, $mm);
$d = json_decode($mm[1], true);
echo "en_swap=" . ($d['swap_title'] ?? 'MISS') . "\n";
echo "en_footer=" . (isset($d['footer_line1']) ? mb_substr($d['footer_line1'], 0, 40) : 'MISS') . "\n";
echo "en_uid=" . ($d['uid_label'] ?? 'MISS') . "\n";
