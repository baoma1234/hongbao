<?php
$path = dirname(__DIR__) . '/application/extra/fanshub.php';
$s = file_get_contents($path);
if ($s === false) {
    fwrite(STDERR, "read fail\n");
    exit(1);
}
if (strpos($s, "'app_update_enabled'") !== false) {
    echo "SKIP already has app_update_enabled\n";
    exit(0);
}
$needle = "  'app_download_url' => 'https://6r1ihgq.baibohaidun.com:1008/d/3rd7ddc9ic5rr2b',";
$add = <<<'PHP'
  'app_download_url' => 'https://6r1ihgq.baibohaidun.com:1008/d/3rd7ddc9ic5rr2b',
  'app_update_enabled' => true,
  'app_android_version_name' => '4.8.0',
  'app_android_version_code' => 260,
  'app_android_download_url' => '',
  'app_android_force_update' => false,
  'app_android_update_note' => '',
  'app_ios_version_name' => '4.8.0',
  'app_ios_version_code' => 260,
  'app_ios_download_url' => '',
  'app_ios_force_update' => false,
  'app_ios_update_note' => '',
PHP;
if (strpos($s, $needle) === false) {
    fwrite(STDERR, "needle missing\n");
    exit(1);
}
$s = str_replace($needle, $add, $s);
if (file_put_contents($path, $s) === false) {
    fwrite(STDERR, "write fail\n");
    exit(1);
}
echo "OK injected app_update keys\n";
