<?php
/**
 * 补全 home_quick_fission 文案到各语言包 JS
 * php scripts/patch_home_quick_fission_i18n.php
 */
$root = dirname(__DIR__);
$dirs = [
    $root . '/public/999/i18n',
    $root . '/public/888/i18n',
    $root . '/public/fanshub/i18n',
];

$zh = [
    'home_quick_fission' => '🧧 裂变红宝',
    'home_quick_fission_sub' => '邀请瓜分奖金池',
];
$en = [
    'home_quick_fission' => '🧧 Fission',
    'home_quick_fission_sub' => 'Invite & share the pool',
];

function patchLocaleFile($path, array $add)
{
    if (!is_file($path)) {
        return false;
    }
    $src = file_get_contents($path);
    $obj = null;
    if (preg_match('/FANSHUB_LOCALES\["([^"]+)"\]\s*=\s*(\{.*\})\s*;?\s*$/s', $src, $m)) {
        $code = $m[1];
        $obj = json_decode($m[2], true);
        if (!is_array($obj)) {
            return false;
        }
        foreach ($add as $k => $v) {
            $obj[$k] = $v;
        }
        $out = 'window.FANSHUB_LOCALES=window.FANSHUB_LOCALES||{};' . "\n"
            . 'window.FANSHUB_LOCALES[' . json_encode($code) . ']='
            . json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n";
        file_put_contents($path, $out);
        return true;
    }
    if (preg_match('/window\.FANSHUB_LOCALES\s*=\s*(\{.*\})\s*;?\s*$/s', $src, $m)) {
        $bundle = json_decode($m[1], true);
        if (!is_array($bundle)) {
            return false;
        }
        foreach ($bundle as $code => &$one) {
            if (!is_array($one)) {
                continue;
            }
            $map = (strpos($code, 'zh') === 0) ? $GLOBALS['zhAdds'] : $GLOBALS['enAdds'];
            foreach ($map as $k => $v) {
                $one[$k] = $v;
            }
        }
        unset($one);
        file_put_contents(
            $path,
            'window.FANSHUB_LOCALES = ' . json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n"
        );
        return true;
    }
    return false;
}

$GLOBALS['zhAdds'] = $zh;
$GLOBALS['enAdds'] = $en;

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $zhFile = $dir . '/locales/zh-CN.js';
    $enFile = $dir . '/locales/en-PH.js';
    $bundle = $dir . '/locales.bundle.js';
    echo patchLocaleFile($zhFile, $zh) ? "OK $zhFile\n" : "SKIP $zhFile\n";
    echo patchLocaleFile($enFile, $en) ? "OK $enFile\n" : "SKIP $enFile\n";
    // other locales use English fission text
    foreach (glob($dir . '/locales/*.js') as $f) {
        $base = basename($f);
        if ($base === 'zh-CN.js' || $base === 'en-PH.js') {
            continue;
        }
        echo patchLocaleFile($f, $en) ? "OK $f\n" : "SKIP $f\n";
    }
    echo patchLocaleFile($bundle, []) ? "OK $bundle\n" : "SKIP $bundle\n";
    $ver = $dir . '/version.js';
    if (is_file($ver)) {
        file_put_contents(
            $ver,
            "window.FANSHUB_I18N_VER='" . date('YmdHis') . "';\nwindow.FANSHUB_LOCALES=window.FANSHUB_LOCALES||{};\n"
        );
        echo "OK $ver\n";
    }
}
echo "DONE\n";
