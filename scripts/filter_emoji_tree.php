<?php
/**
 * Filter emoji-tree.json: drop glyphs poorly supported on Win10/old WebView
 * (esp. Symbols and Pictographs Extended-A U+1FA00–U+1FAFF, e.g. 🫡).
 */
$path = dirname(__DIR__) . '/public/888/data/emoji-tree.json';
$raw = file_get_contents($path);
$data = json_decode($raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "invalid json\n");
    exit(1);
}

$ignored = [
    0xFE0E => true, 0xFE0F => true, 0x200D => true, 0x20E3 => true,
    0x1F3FB => true, 0x1F3FC => true, 0x1F3FD => true, 0x1F3FE => true, 0x1F3FF => true,
];

function emojiTooNew($codes, $ignored)
{
    $parts = preg_split('/[^0-9A-Fa-f]+/', (string)$codes, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($parts as $hex) {
        $cp = hexdec($hex);
        if (isset($ignored[$cp])) {
            continue;
        }
        // Extended-A: 🫠🫡🫥🫵🫶 等（Unicode 13–15）在旧系统常显示方块
        if ($cp >= 0x1FA00 && $cp <= 0x1FAFF) {
            return true;
        }
        // Unicode 14+ 部分新增脸/手（1F979 等之后仍有漏网，按常用方块再剔一批）
        if (in_array($cp, [
            0x1F90C, // pinched fingers
            0x1F972, // smiling tear (ok on many; keep)
        ], true)) {
            // keep 1F972; only drop pinched fingers if needed
        }
        if ($cp === 0x1F90C) {
            return true;
        }
    }
    return false;
}

function filterList($list, $ignored, &$removed)
{
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    foreach ($list as $node) {
        if (!is_array($node)) {
            continue;
        }
        if (isset($node['list']) && is_array($node['list'])) {
            $child = filterList($node['list'], $ignored, $removed);
            if ($child) {
                $node['list'] = $child;
                $out[] = $node;
            }
            continue;
        }
        if (!empty($node['char']) && !empty($node['codes'])) {
            if (emojiTooNew($node['codes'], $ignored)) {
                $removed++;
                continue;
            }
            $out[] = $node;
        }
    }
    return $out;
}

$removed = 0;
$filtered = filterList($data, $ignored, $removed);
$json = json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "encode failed\n");
    exit(1);
}
file_put_contents($path, $json);
echo "removed={$removed} bytes=" . strlen($json) . PHP_EOL;
