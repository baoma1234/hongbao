<?php
$en = include dirname(__DIR__) . '/application/extra/i18n/en-PH.php';
$keys = [
  'page_hero_master_title','page_hero_master_sub','master_lock_title','master_lock_desc','master_lock_btn',
  'phase2_honor_title','phase2_honor_hint','phase2_honor_progress','phase2_honor_capped',
  'phase2_checkin_streak','phase2_checkin_ledger','phase2_checkin_toggle','phase2_checkin_violent_btn',
  'phase2_checkin_normal_btn','phase2_radar_title','phase2_radar_empty','phase2_radar_progress',
  'phase2_radar_done','phase2_radar_urge','phase2_radar_fail','loading_generic'
];
$miss = [];
$zh = [];
foreach ($keys as $k) {
  if (!isset($en[$k]) || $en[$k]==='') { $miss[]=$k; continue; }
  if (preg_match('/[\x{4e00}-\x{9fff}]/u', $en[$k])) $zh[]=$k;
}
echo 'miss='.implode(',', $miss)."\n";
echo 'still_zh='.implode(',', $zh)."\n";
