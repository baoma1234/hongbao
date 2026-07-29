<?php
/**
 * 福利大厅 v24：品牌 789bingo → 555.bio，移除琥珀绿/极致黑金皮肤
 * php scripts/patch_fanshub_v24_brand_skin.php
 */
$root = dirname(__DIR__);

function fanshub_brand_replace($text)
{
    if (!is_string($text)) {
        return $text;
    }
    $text = str_replace('789bingo', '555.bio', $text);
    $text = str_replace('789官方', '555.bio官方', $text);
    $text = str_replace('789 官方', '555.bio 官方', $text);
    return $text;
}

function fanshub_brand_replace_deep($data)
{
    if (is_string($data)) {
        return fanshub_brand_replace($data);
    }
    if (!is_array($data)) {
        return $data;
    }
    foreach ($data as $k => $v) {
        $data[$k] = fanshub_brand_replace_deep($v);
    }
    return $data;
}

function fanshub_remove_skin_keys(array $copy)
{
    unset($copy['skin_option_c'], $copy['skin_option_e']);
    return $copy;
}

function fanshub_patch_php_return_file($path)
{
    $data = include $path;
    if (!is_array($data)) {
        echo "SKIP  invalid php array {$path}\n";
        return;
    }
    $data = fanshub_brand_replace_deep($data);
    $data = fanshub_remove_skin_keys($data);
    $export = "<?php\n\nreturn " . var_export($data, true) . ";\n";
    file_put_contents($path, $export);
    echo "OK    {$path}\n";
}

function fanshub_patch_text_file($path)
{
    if (!is_file($path)) {
        echo "SKIP  missing {$path}\n";
        return;
    }
    $raw = file_get_contents($path);
    $new = fanshub_brand_replace($raw);
    if ($new !== $raw) {
        file_put_contents($path, $new);
        echo "OK    {$path}\n";
    } else {
        echo "SKIP  no change {$path}\n";
    }
}

// 默认文案源 + 多语言
fanshub_patch_php_return_file($root . '/application/extra/fanshub_h5_copy.php');
foreach (glob($root . '/application/extra/i18n/*.php') as $file) {
    fanshub_patch_php_return_file($file);
}

// 运行配置
define('APP_PATH', $root . '/application/');
require $root . '/thinkphp/base.php';
\think\App::initCommon();

$config = \think\Config::get('fanshub') ?: [];
if (!is_array($config)) {
    $config = [];
}
$config = fanshub_brand_replace_deep($config);
if (!empty($config['h5_copy']) && is_array($config['h5_copy'])) {
    $config['h5_copy'] = fanshub_remove_skin_keys($config['h5_copy']);
}
if (!\app\common\library\FansHubService::saveFanshubConfig($config)) {
    fwrite(STDERR, "FAIL  save fanshub.php\n");
    exit(1);
}
echo "OK    application/extra/fanshub.php\n";

\app\common\library\FansHubService::exportH5CopyDefaultsJs();
echo "OK    public/fanshub/copy.defaults.js\n";
\app\common\library\FansHubService::regenerateI18nBundle();
echo "OK    public/fanshub/i18n/locales.bundle.js\n";

fanshub_patch_text_file($root . '/application/api/controller/Fanshub.php');
echo "DONE  refresh H5 /fanshub/ to see changes\n";
