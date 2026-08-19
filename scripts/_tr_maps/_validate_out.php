<?php
$root = dirname(__DIR__);
$zh = json_decode(file_get_contents("$root/_zh_keys.json"), true);
$extras = [
    'fission_home_entry_title','fission_home_entry_sub_active','fission_home_entry_sub_ended_drawn',
    'fission_home_entry_sub_ended_void','fission_home_entry_go','fission_home_entry_ended',
    'profile_payee_bound','profile_payee_address_label','profile_payee_address_ph','profile_payee_name_optional',
    'profile_payee_optional_ph','profile_payee_bind_btn','profile_payee_update_btn','profile_payee_submitting',
    'profile_payee_usdt_trc20_only','profile_payee_addr_with_chain','profile_payee_bound_prefix',
];
$files = [
    'en-PH' => '_tr_en-PH.json',
    'vi-VN' => '_tr_vi-VN.json',
    'id-ID' => '_tr_id-ID.json',
    'ms-MY' => '_tr_ms-MY.json',
    'km-KH' => '_tr_km-KH.json',
];
$crit = array_merge([
    'uid_label','uid_placeholder','uid_submit_btn','uid_submit_pending','uid_hint_idle','uid_hint_pending','uid_hint_approved','uid_hint_rejected',
    'settle_title_low','settle_sub_low','settle_title_high','settle_sub_high',
    'loading_generic','page_hero_claim_title','page_hero_claim_sub',
], $extras);

function placeholders($s) {
    preg_match_all('/\{[a-zA-Z0-9_]+\}/', $s, $m);
    sort($m[0]);
    return $m[0];
}

foreach ($files as $code => $file) {
    $path = "$root/$file";
    $raw = file_get_contents($path);
    $bom = (substr($raw, 0, 3) === "\xEF\xBB\xBF");
    $data = json_decode($raw, true);
    $err = json_last_error_msg();
    $count = is_array($data) ? count($data) : 0;
    $missZh = [];
    $han = [];
    $phBad = [];
    if (is_array($data)) {
        foreach ($zh as $k => $zv) {
            if (!isset($data[$k])) $missZh[] = $k;
            else {
                $check = str_replace(['红宝','中文'], '', $data[$k]);
                if (preg_match('/\p{Han}/u', $check)) $han[] = $k;
                $pz = placeholders($zv);
                $pt = placeholders($data[$k]);
                // only compare if zh had placeholders
                if ($pz && $pz !== $pt) {
                    // allow extra placeholders only if missing from target is the issue
                    $missing = array_diff($pz, $pt);
                    if ($missing) $phBad[] = "$k missing " . implode(',', $missing);
                }
            }
        }
        $missExtra = [];
        foreach ($extras as $k) {
            if (!isset($data[$k]) || $data[$k] === '') $missExtra[] = $k;
        }
        $critOk = true;
        foreach ($crit as $k) {
            if (!isset($data[$k]) || $data[$k] === '') $critOk = false;
        }
    }
    echo "$code parse=" . (is_array($data)?'OK':'FAIL:'.$err) . " bom=" . ($bom?'YES':'no') .
        " keys=$count missZh=" . count($missZh) . " leftoverHan=" . count($han) .
        " missExtra=" . count($missExtra ?? []) . " phBad=" . count($phBad) . " crit=" . (!empty($critOk)?'OK':'BAD') . "\n";
    if ($han) echo "  han sample: " . implode(', ', array_slice($han, 0, 10)) . "\n";
    if ($phBad) echo "  ph sample: " . implode('; ', array_slice($phBad, 0, 5)) . "\n";
    if ($missExtra) echo "  missExtra: " . implode(', ', $missExtra) . "\n";
}

// show critical key samples
$en = json_decode(file_get_contents("$root/_tr_en-PH.json"), true);
$vi = json_decode(file_get_contents("$root/_tr_vi-VN.json"), true);
$km = json_decode(file_get_contents("$root/_tr_km-KH.json"), true);
echo "\nSample fission_home_entry_title:\n";
echo "  EN: {$en['fission_home_entry_title']}\n";
echo "  VI: {$vi['fission_home_entry_title']}\n";
echo "  KM: {$km['fission_home_entry_title']}\n";
echo "Sample uid_submit_btn:\n";
echo "  EN: {$en['uid_submit_btn']}\n";
echo "  VI: {$vi['uid_submit_btn']}\n";
echo "  KM: {$km['uid_submit_btn']}\n";
