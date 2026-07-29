<?php
/**
 * 添加好友：手机号/8位ID + 会员ID复制 文案
 * php scripts/patch_add_friend_id_i18n.php
 */
$root = dirname(__DIR__);

$keysZh = [
    'chat_add_friend_hint' => '仅支持手机号或8位会员ID精确查找，二选一；对方通过后才能发消息',
    'chat_add_friend_or' => '或',
    'chat_add_friend_id_label' => '对方会员ID',
    'chat_add_friend_id_placeholder' => '请输入8位数字会员ID',
    'chat_add_friend_id_invalid' => '会员ID须为8位数字',
    'chat_add_friend_choose_one' => '请只填写手机号或会员ID其中一项',
    'chat_add_friend_phone_invalid' => '请输入手机号或8位会员ID',
    'profile_uid_copy_btn' => '复制',
    'profile_uid_copied' => '会员ID已复制',
    'profile_uid_copy_empty' => '暂无会员ID',
];

$keysEn = [
    'chat_add_friend_hint' => 'Search by phone or 8-digit member ID only. Messaging unlocks after they accept.',
    'chat_add_friend_or' => 'or',
    'chat_add_friend_id_label' => 'Member ID',
    'chat_add_friend_id_placeholder' => 'Enter 8-digit member ID',
    'chat_add_friend_id_invalid' => 'Member ID must be 8 digits',
    'chat_add_friend_choose_one' => 'Fill either phone or member ID, not both',
    'chat_add_friend_phone_invalid' => 'Enter a phone number or 8-digit member ID',
    'profile_uid_copy_btn' => 'Copy',
    'profile_uid_copied' => 'Member ID copied',
    'profile_uid_copy_empty' => 'No member ID yet',
];

function patch_js_locale($file, array $keys)
{
    if (!is_file($file)) {
        echo "skip missing $file\n";
        return;
    }
    $src = file_get_contents($file);
    if (!preg_match('/=\s*(\{.*\})\s*;?\s*$/s', $src, $m)) {
        // FANSHUB_LOCALES["xx"]={...};
        if (!preg_match('/(\{[^{].*\})\s*;?\s*$/s', $src, $m)) {
            echo "parse fail $file\n";
            return;
        }
    }
    $json = $m[1];
    $data = json_decode($json, true);
    if (!is_array($data)) {
        echo "json fail $file\n";
        return;
    }
    foreach ($keys as $k => $v) {
        $data[$k] = $v;
    }
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strpos($src, 'FANSHUB_LOCALES[') !== false) {
        $out = preg_replace('/=\{.*\}\s*;?\s*$/s', '=' . $encoded . ";\n", $src, 1);
    } elseif (strpos($src, 'FANSHUB_COPY_DEFAULTS') !== false) {
        $out = 'window.FANSHUB_COPY_DEFAULTS=' . $encoded . ";\n";
    } elseif (strpos($src, 'FANSHUB_LOCALES =') !== false) {
        // bundle handled separately
        $out = $src;
    } else {
        $out = preg_replace('/=\{.*\}\s*;?\s*$/s', '=' . $encoded . ";\n", $src, 1);
    }
    if ($out && $out !== $src) {
        file_put_contents($file, $out);
        echo "ok $file\n";
    } else {
        // rewrite by known patterns
        if (preg_match('/^(window\.FANSHUB_LOCALES\["[^"]+"\]=)/', $src, $hm)) {
            file_put_contents($file, $hm[1] . $encoded . ";\n");
            echo "ok $file\n";
        } elseif (strpos($src, 'FANSHUB_COPY_DEFAULTS') !== false) {
            file_put_contents($file, 'window.FANSHUB_COPY_DEFAULTS=' . $encoded . ";\n");
            echo "ok $file\n";
        } else {
            echo "no change $file\n";
        }
    }
}

function patch_php_copy($file, array $keys)
{
    if (!is_file($file)) {
        return;
    }
    $arr = include $file;
    if (!is_array($arr)) {
        echo "php include fail $file\n";
        return;
    }
    foreach ($keys as $k => $v) {
        $arr[$k] = $v;
    }
    $export = "<?php\nreturn " . var_export($arr, true) . ";\n";
    file_put_contents($file, $export);
    echo "ok $file\n";
}

patch_php_copy($root . '/application/extra/fanshub_h5_copy.php', $keysZh);

$localeDir = $root . '/public/888/i18n/locales';
foreach (glob($localeDir . '/*.js') as $f) {
    $base = basename($f);
    $keys = ($base === 'zh-CN.js') ? $keysZh : $keysEn;
    patch_js_locale($f, $keys);
}
patch_js_locale($root . '/public/888/copy.defaults.js', $keysZh);

// rebuild bundle
$bundle = [];
foreach (glob($localeDir . '/*.js') as $f) {
    $src = file_get_contents($f);
    if (preg_match('/FANSHUB_LOCALES\["([^"]+)"\]=(\{.*\})\s*;?\s*$/s', $src, $m)) {
        $data = json_decode($m[2], true);
        if (is_array($data)) {
            $bundle[$m[1]] = $data;
        }
    }
}
if ($bundle) {
    $path = $root . '/public/888/i18n/locales.bundle.js';
    file_put_contents($path, 'window.FANSHUB_LOCALES = ' . json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n");
    echo "ok locales.bundle.js\n";
}

echo "done\n";
