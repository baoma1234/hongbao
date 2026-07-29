<?php
/**
 * Deep audit: compare HTML visible CJK vs data-copy coverage + encoding health
 */
$root = dirname(__DIR__);
$copy = include $root . '/application/extra/fanshub_h5_copy.php';
$files = [
    'public/888/partials/tab-home.php',
    'public/888/partials/tab-exchange.php',
    'public/888/partials/tab-messages.php',
    'public/888/partials/tab-master.php',
    'public/888/partials/tab-profile.php',
    'public/888/partials/profile-subpages.php',
    'public/888/partials/bottom-and-overlays.php',
    'public/888/partials/modals.php',
    'public/888/partials/login.php',
];

function isMojibake($s) {
    // classic GBK-as-UTF8 / replacement / weird emoji+garbled patterns
    if (strpos($s, "\xEF\xBF\xBD") !== false) return true;
    if (preg_match('/�|鐢|閸|閺|鎶|鑲|缃|浼|鍙|椋|闂|閿|馃|棣/u', $s)) return true;
    return false;
}

$report = [];
foreach ($files as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) continue;
    $html = file_get_contents($path);
    $item = ['file' => $rel, 'mojibake' => [], 'unwired' => [], 'missing_keys' => []];

    // data-copy keys used
    preg_match_all('/data-copy(?:-placeholder|-aria|-title|-alt)?="([^"]+)"/', $html, $km);
    foreach (array_unique($km[1]) as $k) {
        if (!isset($copy[$k])) $item['missing_keys'][] = $k;
    }

    // Find text nodes with CJK without data-copy on same open tag
    $work = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);
    if (preg_match_all('/<([a-zA-Z0-9]+)([^>]*)>([^<]*[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}][^<]*)</u', $work, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $attrs = $hit[2];
            $text = trim(html_entity_decode($hit[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === '' || preg_match('/^\{\$/', $text)) continue;
            $bad = isMojibake($text);
            $wired = (bool) preg_match('/\bdata-copy(?:-html)?\s*=/', $attrs);
            if ($bad) {
                $item['mojibake'][] = ['tag' => $hit[1], 'text' => mb_substr($text, 0, 80), 'wired' => $wired];
            }
            if (!$wired && !$bad) {
                // skip pure punctuation/symbols short
                if (mb_strlen($text) <= 1 && !preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) continue;
                $item['unwired'][] = ['tag' => $hit[1], 'text' => mb_substr($text, 0, 80), 'attrs' => trim(preg_replace('/\s+/', ' ', $attrs))];
            } elseif (!$wired && $bad) {
                $item['unwired'][] = ['tag' => $hit[1], 'text' => mb_substr($text, 0, 80) . ' [MOJI]', 'attrs' => trim(preg_replace('/\s+/', ' ', $attrs))];
            }
        }
    }
    // placeholders without data-copy-placeholder
    if (preg_match_all('/placeholder="([^"]*[\x{4e00}-\x{9fff}][^"]*)"/u', $work, $pm, PREG_OFFSET_CAPTURE)) {
        foreach ($pm[0] as $i => $full) {
            $pos = $full[1];
            $start = strrpos(substr($work, 0, $pos), '<');
            $snippet = $start === false ? '' : substr($work, $start, min(200, $pos - $start + 20));
            if (preg_match('/\bdata-copy-placeholder\s*=/', $snippet)) continue;
            $item['unwired'][] = ['tag' => 'placeholder', 'text' => $pm[1][$i][0], 'attrs' => ''];
        }
    }
    $report[] = $item;
}

file_put_contents($root . '/scripts/_deep_i18n_audit.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$lines = [];
foreach ($report as $item) {
    $lines[] = '## ' . $item['file'];
    $lines[] = '  mojibake=' . count($item['mojibake']) . ' unwired=' . count($item['unwired']) . ' missing_keys=' . count($item['missing_keys']);
    foreach (array_slice($item['mojibake'], 0, 25) as $g) {
        $lines[] = '  MOJI <' . $g['tag'] . '> wired=' . ($g['wired'] ? '1' : '0') . ' ' . $g['text'];
    }
    foreach (array_slice($item['unwired'], 0, 40) as $g) {
        $lines[] = '  UNWIRE <' . $g['tag'] . '> ' . $g['text'];
    }
    foreach ($item['missing_keys'] as $k) {
        $lines[] = '  MISSKEY ' . $k;
    }
    $lines[] = '';
}
file_put_contents($root . '/scripts/_deep_i18n_audit.txt', implode("\n", $lines));
echo implode("\n", $lines);
