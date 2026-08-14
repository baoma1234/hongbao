<?php
$en = include dirname(__DIR__) . '/application/extra/i18n/en-PH.php';
$keys = [
  'chat_community_title','chat_conn_ok','chat_conn_fail','chat_conn_off','chat_my_id','chat_my_id_admin',
  'chat_my_id_with_balance','chat_my_id_admin_balance','chat_tab_chat','chat_tab_community','chat_tab_commission',
  'chat_tab_notice','chat_community_official','chat_my_groups','chat_friend_list','chat_commission_total',
  'chat_commission_withdraw_btn','chat_commission_withdrawable','chat_commission_today','chat_commission_rebate',
  'chat_commission_nav_promo','chat_commission_nav_rebate','chat_commission_nav_ledger','chat_commission_nav_withdraw',
  'chat_commission_recent','chat_commission_login_hint','chat_notice_latest','chat_notice_promote','chat_notice_ads','chat_notice_rules'
];
$miss = [];
$zhish = [];
foreach ($keys as $k) {
  if (!isset($en[$k]) || $en[$k] === '') { $miss[] = $k; continue; }
  if (preg_match('/[\x{4e00}-\x{9fff}]/u', $en[$k])) $zhish[] = $k . '=' . $en[$k];
}
echo 'en_count=' . count($en) . "\n";
echo 'miss=' . implode(',', $miss) . "\n";
echo 'still_zh=' . implode("\n", $zhish) . "\n";
