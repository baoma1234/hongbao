<?php
/**
 * Patch exchange i18n keys into fanshub_h5_copy + locale PHP + export from fanshub.php h5_copy.
 */
$root = dirname(__DIR__);

$keysZh = [
  'profile_menu_exchange' => '兑换',
  'profile_menu_exchange_sub' => '红宝兑红利 · 红利兑股份',
  'profile_exchange_title' => '兑换中心',
  'profile_ex_r2b_title' => '红宝兑换红利',
  'profile_ex_r2b_desc' => '将持有股份按实时股价兑换为红利余额',
  'profile_ex_r2b_label' => '兑换股数',
  'profile_ex_r2b_btn' => '确认兑换为红利',
  'profile_ex_b2r_title' => '红利兑换股份',
  'profile_ex_b2r_desc' => '用红利余额按实时股价兑换股份',
  'profile_ex_b2r_label' => '兑换金额（元）',
  'profile_ex_b2r_btn' => '确认兑换为股份',
  'profile_ex_min_hint' => '单次最低 {min}',
  'profile_ex_preview_r2b' => '预计到账：￥{amount}',
  'profile_ex_preview_b2r' => '预计获得：{count} 股',
  'profile_ex_closed' => '兑换通道已关闭',
  'profile_ex_r2b_closed' => '红宝兑换红利已关闭',
  'profile_ex_b2r_closed' => '红利兑换股份已关闭',
  'alert_exchange_min' => '单次最少兑换 {min}',
  'alert_exchange_disabled' => '兑换功能已关闭',
  'alert_exchange_reverse_ok' => '🎉 已用 ￥{amount} 兑换 {count} 股',
  'alert_exchange_reverse_fail' => '兑换失败',
  'alert_insufficient_balance' => '红利余额不足',
  'api_exchange_reverse_ok' => '兑换成功',
  'srv_exchange_r2b_disabled' => '红宝兑换红利通道已关闭',
  'srv_exchange_b2r_disabled' => '红利兑换股份通道已关闭',
  'srv_exchange_min' => '单次最低 {min}',
  'srv_exchange_amount_invalid' => '兑换金额无效',
  'srv_insufficient_balance' => '红利余额不足',
];

$keysEn = [
  'profile_menu_exchange' => 'Exchange',
  'profile_menu_exchange_sub' => 'Shares ↔ Dividend',
  'profile_exchange_title' => 'Exchange',
  'profile_ex_r2b_title' => 'Shares → Dividend',
  'profile_ex_r2b_desc' => 'Convert shares to dividend balance at live price',
  'profile_ex_r2b_label' => 'Share count',
  'profile_ex_r2b_btn' => 'Convert to dividend',
  'profile_ex_b2r_title' => 'Dividend → Shares',
  'profile_ex_b2r_desc' => 'Convert dividend balance to shares at live price',
  'profile_ex_b2r_label' => 'Amount',
  'profile_ex_b2r_btn' => 'Convert to shares',
  'profile_ex_min_hint' => 'Minimum {min} per order',
  'profile_ex_preview_r2b' => 'Est. credit: {currency}{amount}',
  'profile_ex_preview_b2r' => 'Est. shares: {count}',
  'profile_ex_closed' => 'Exchange is closed',
  'profile_ex_r2b_closed' => 'Shares → Dividend is closed',
  'profile_ex_b2r_closed' => 'Dividend → Shares is closed',
  'alert_exchange_min' => 'Minimum per order: {min}',
  'alert_exchange_disabled' => 'Exchange is disabled',
  'alert_exchange_reverse_ok' => 'Converted {currency}{amount} into {count} shares',
  'alert_exchange_reverse_fail' => 'Exchange failed',
  'alert_insufficient_balance' => 'Insufficient dividend balance',
  'api_exchange_reverse_ok' => 'Exchange successful',
  'srv_exchange_r2b_disabled' => 'Shares → Dividend is closed',
  'srv_exchange_b2r_disabled' => 'Dividend → Shares is closed',
  'srv_exchange_min' => 'Minimum per order: {min}',
  'srv_exchange_amount_invalid' => 'Invalid amount',
  'srv_insufficient_balance' => 'Insufficient dividend balance',
];

function patch_php_array_file($path, $keys) {
    if (!is_file($path)) {
        echo "skip missing $path\n";
        return;
    }
    $data = include $path;
    if (!is_array($data)) {
        echo "invalid $path\n";
        return;
    }
    foreach ($keys as $k => $v) {
        $data[$k] = $v;
    }
    $export = "<?php\nreturn " . var_export($data, true) . ";\n";
    file_put_contents($path, $export);
    echo "patched $path (+" . count($keys) . ")\n";
}

patch_php_array_file($root . '/application/extra/fanshub_h5_copy.php', $keysZh);

$localeMap = [
  'en-PH.php' => $keysEn,
  'id-ID.php' => $keysEn,
  'vi-VN.php' => $keysEn,
  'ms-MY.php' => $keysEn,
  'km-KH.php' => $keysEn,
];
foreach ($localeMap as $file => $keys) {
    patch_php_array_file($root . '/application/extra/i18n/' . $file, $keys);
}

// Merge into fanshub.php h5_copy
define('APP_PATH', $root . '/application/');
require $root . '/thinkphp/base.php';
\think\App::initCommon();

$cfg = include $root . '/application/extra/fanshub.php';
$saved = (isset($cfg['h5_copy']) && is_array($cfg['h5_copy'])) ? $cfg['h5_copy'] : [];
foreach ($keysZh as $k => $v) {
    $saved[$k] = $v;
}
$zh = \app\common\library\FansHubService::mergeH5CopyDefaults($saved);
$cfg['h5_copy'] = $zh;
\app\common\library\FansHubService::saveFanshubConfig($cfg);

// Export copy.defaults + locales from runtime zh + locale files
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$jsDefaults = 'window.FANSHUB_COPY_DEFAULTS=' . json_encode($zh, $flags) . ';';
foreach (['888', 'fanshub', 'fanshubtest'] as $dir) {
    file_put_contents($root . '/public/' . $dir . '/copy.defaults.js', $jsDefaults);
}

$locales = ['zh-CN' => $zh];
$localeFiles = [
    'en-PH' => 'en-PH.php',
    'id-ID' => 'id-ID.php',
    'vi-VN' => 'vi-VN.php',
    'ms-MY' => 'ms-MY.php',
    'km-KH' => 'km-KH.php',
];
foreach ($localeFiles as $code => $file) {
    $path = $root . '/application/extra/i18n/' . $file;
    $data = is_file($path) ? include $path : [];
    if (!is_array($data)) $data = [];
    $locales[$code] = array_merge($zh, $data);
}

$ver = date('YmdHis');
foreach (['888', 'fanshub', 'fanshubtest'] as $dir) {
    $i18nOut = $root . '/public/' . $dir . '/i18n';
    file_put_contents($i18nOut . '/version.js', "window.FANSHUB_I18N_VER='{$ver}';\nwindow.FANSHUB_LOCALES=window.FANSHUB_LOCALES||{};\n");
    foreach ($locales as $code => $map) {
        $safe = preg_replace('/[^A-Za-z0-9\\-]/', '', $code);
        $js = "window.FANSHUB_LOCALES=window.FANSHUB_LOCALES||{};\n"
            . 'window.FANSHUB_LOCALES[' . json_encode($safe) . ']=' . json_encode($map, $flags) . ";\n";
        file_put_contents($i18nOut . '/locales/' . $safe . '.js', $js);
    }
    file_put_contents($i18nOut . '/locales.bundle.js', 'window.FANSHUB_LOCALES = ' . json_encode($locales, $flags) . ";\n");
}

echo "brand=" . $zh['brand_name'] . " exchange_keys_ok\n";
