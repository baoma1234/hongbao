<?php
/**
 * 生成按需语言包：public/{888,fanshub,fanshubtest}/i18n/locales/{code}.js
 * 同时保留 locales.bundle.js 供旧入口兼容
 * 用法: php scripts/generate_i18n_locales.php
 */

$root = dirname(__DIR__);
$zh = include $root . '/application/extra/fanshub_h5_copy.php';
if (!is_array($zh)) {
    fwrite(STDERR, "fanshub_h5_copy.php invalid\n");
    exit(1);
}

$zhExtras = [
    'lang_zh'                 => '中文',
    'lang_en'                 => 'English',
    'lang_km'                 => 'ខ្មែរ',
    'lang_id'                 => 'Indonesia',
    'lang_vi'                 => 'Tiếng Việt',
    'lang_ms'                 => 'Melayu',
    'country_cn'              => '中国',
    'country_ph'              => '菲律宾',
    'country_kh'              => '柬埔寨',
    'country_id'              => '印尼',
    'country_vn'              => '越南',
    'country_my'              => '马来西亚',
    'login_phone_placeholder_ph' => '请输入10位菲律宾手机号（以9开头）',
    'login_phone_placeholder_kh' => '请输入8-9位柬埔寨手机号',
    'login_phone_placeholder_id' => '请输入印尼手机号（以8开头，9-12位）',
    'login_phone_placeholder_vn' => '请输入10位越南手机号',
    'login_phone_placeholder_my' => '请输入马来西亚手机号（以1开头，9-10位）',
    'aria_lang_select'        => '选择语言',
    'aria_country_select'     => '选择国家区号',
    'alert_phone_invalid'     => '❌ 手机号格式不正确，请检查后重试。',
    'alert_phone_required'    => '请输入合法的手机号码！',
];
$zh = array_merge($zh, $zhExtras);

$locales = ['zh-CN' => $zh];
$localeFiles = [
    'en-PH' => 'en-PH.php',
    'id-ID' => 'id-ID.php',
    'vi-VN' => 'vi-VN.php',
    'ms-MY' => 'ms-MY.php',
    'km-KH' => 'km-KH.php',
];
$i18nDir = $root . '/application/extra/i18n/';
$fallback = null;
$rawLocales = [];

foreach ($localeFiles as $code => $file) {
    $path = $i18nDir . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "skip missing locale: $file\n");
        continue;
    }
    $data = include $path;
    if (!is_array($data)) {
        fwrite(STDERR, "invalid locale file: $file\n");
        continue;
    }
    $rawLocales[$code] = $data;
}

// EN first: zh defaults <- EN overrides
if (isset($rawLocales['en-PH'])) {
    $fallback = array_merge($zh, $rawLocales['en-PH']);
    $locales['en-PH'] = $fallback;
}

foreach ($rawLocales as $code => $data) {
    if ($code === 'en-PH') {
        continue;
    }
    // Other langs: zh <- EN fallback <- locale overrides (missing keys show EN, not Chinese)
    $locales[$code] = array_merge($zh, $fallback ?: [], $data);
}

if ($fallback) {
    foreach (array_keys($localeFiles) as $code) {
        if (isset($locales[$code])) {
            continue;
        }
        $locales[$code] = $fallback;
    }
}

$ver = date('YmdHis');
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

$publicDirs = [
    $root . '/public/888/i18n',
    $root . '/public/fanshub/i18n',
    $root . '/public/fanshubtest/i18n',
];

foreach ($publicDirs as $i18nOut) {
    if (!is_dir($i18nOut)) {
        mkdir($i18nOut, 0755, true);
    }
    $locDir = $i18nOut . '/locales';
    if (!is_dir($locDir)) {
        mkdir($locDir, 0755, true);
    }

    // 版本号：前端按需加载带 ?v=
    file_put_contents(
        $i18nOut . '/version.js',
        "window.FANSHUB_I18N_VER='" . $ver . "';\nwindow.FANSHUB_LOCALES=window.FANSHUB_LOCALES||{};\n"
    );

    foreach ($locales as $code => $map) {
        $json = json_encode($map, $flags);
        if ($json === false) {
            fwrite(STDERR, "json encode failed for $code\n");
            exit(1);
        }
        $safe = preg_replace('/[^A-Za-z0-9\\-]/', '', $code);
        $js = "window.FANSHUB_LOCALES=window.FANSHUB_LOCALES||{};\n"
            . "window.FANSHUB_LOCALES[" . json_encode($safe) . "]=" . $json . ";\n";
        file_put_contents($locDir . '/' . $safe . '.js', $js);
    }

    // 旧入口兼容：全量 bundle
    $bundleJson = json_encode($locales, $flags);
    file_put_contents($i18nOut . '/locales.bundle.js', "window.FANSHUB_LOCALES = " . $bundleJson . ";\n");

    echo "Wrote " . $i18nOut . " (" . count($locales) . " locales, ver={$ver})\n";
}

foreach ($locales as $code => $map) {
    echo "  $code: " . count($map) . " keys, ~" . round(strlen(json_encode($map, $flags)) / 1024, 1) . "KB\n";
}
