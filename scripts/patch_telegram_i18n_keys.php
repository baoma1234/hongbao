<?php
/**
 * Append Telegram bot i18n keys into application/extra/i18n/*.php (textual, keep format)
 * php scripts/patch_telegram_i18n_keys.php
 */
$root = dirname(__DIR__);

function phpString($s)
{
    $s = str_replace(['\\', "'"], ['\\\\', "\\'"], $s);
    return "'" . $s . "'";
}

function blockFor(array $keys)
{
    $lines = ["\n  // Telegram Bot"];
    foreach ($keys as $k => $v) {
        if (strpos($v, "\n") !== false) {
            $esc = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $v);
            $lines[] = "  '{$k}' => \"{$esc}\",";
        } else {
            $lines[] = '  ' . phpString($k) . ' => ' . phpString($v) . ',';
        }
    }
    return implode("\n", $lines) . "\n";
}

$packs = include $root . '/scripts/data/telegram_i18n_packs.php';
$dir = $root . '/application/extra/i18n';
foreach ($packs as $code => $keys) {
    $path = $dir . '/' . $code . '.php';
    if (!is_file($path)) {
        fwrite(STDERR, "missing {$path}\n");
        continue;
    }
    $raw = file_get_contents($path);
    if (strpos($raw, "'tg_kb_enter'") !== false) {
        echo "SKIP {$code} (already has tg keys)\n";
        continue;
    }
    $block = blockFor($keys);
    $pos = strrpos($raw, ');');
    if ($pos === false) {
        fwrite(STDERR, "no close {$path}\n");
        exit(1);
    }
    $new = substr($raw, 0, $pos) . $block . substr($raw, $pos);
    if (file_put_contents($path, $new) === false) {
        fwrite(STDERR, "write fail {$path}\n");
        exit(1);
    }
    echo "OK {$code}\n";
}
echo "done\n";
