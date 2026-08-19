<?php
/**
 * Merge language maps and write scripts/_tr_{locale}.json
 * Usage: php scripts/_tr_maps/_emit_json.php
 */
$root = dirname(__DIR__);
$zh = json_decode(file_get_contents($root . '/_zh_keys.json'), true);
$extras = [
    'fission_home_entry_title', 'fission_home_entry_sub_active', 'fission_home_entry_sub_ended_drawn',
    'fission_home_entry_sub_ended_void', 'fission_home_entry_go', 'fission_home_entry_ended',
    'profile_payee_bound', 'profile_payee_address_label', 'profile_payee_address_ph', 'profile_payee_name_optional',
    'profile_payee_optional_ph', 'profile_payee_bind_btn', 'profile_payee_update_btn', 'profile_payee_submitting',
    'profile_payee_usdt_trc20_only', 'profile_payee_addr_with_chain', 'profile_payee_bound_prefix',
];
$wanted = array_keys($zh);
foreach ($extras as $k) {
    if (!in_array($k, $wanted, true)) $wanted[] = $k;
}

function load_parts($dir, $prefix) {
    $out = [];
    foreach (['a', 'b', 'c', 'd'] as $p) {
        $f = "$dir/{$prefix}_{$p}.php";
        if (is_file($f)) {
            $part = include $f;
            if (is_array($part)) $out = array_merge($out, $part);
        }
    }
    return $out;
}

function has_leftover_han($s) {
    $c = str_replace(['红宝', '中文'], '', $s);
    return (bool)preg_match('/\p{Han}/u', $c);
}

$locales = [
    'en-PH' => 'en',
    'vi-VN' => 'vi',
    'id-ID' => 'id',
    'ms-MY' => 'ms',
    'km-KH' => 'km',
];

$dir = __DIR__;
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

foreach ($locales as $code => $prefix) {
    $map = load_parts($dir, $prefix);
    $out = [];
    $miss = [];
    $han = 0;
    foreach ($wanted as $k) {
        if (!isset($map[$k])) {
            $miss[] = $k;
            $out[$k] = isset($zh[$k]) ? $zh[$k] : '';
            continue;
        }
        $out[$k] = $map[$k];
        if ($code !== 'en-PH' && has_leftover_han($map[$k])) $han++;
        if ($code === 'en-PH' && has_leftover_han($map[$k])) $han++;
    }
    $path = $root . '/_tr_' . $code . '.json';
    file_put_contents($path, json_encode($out, $flags) . "\n");
    echo "$code keys=" . count($out) . " miss=" . count($miss) . " han=$han file=$path\n";
    if ($miss) echo "  MISS: " . implode(', ', array_slice($miss, 0, 30)) . (count($miss) > 30 ? '...' : '') . "\n";
}
