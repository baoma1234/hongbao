<?php
/**
 * Find CJK text nodes whose nearest element does NOT have data-copy / data-copy-placeholder / data-copy-aria
 */
$root = dirname(__DIR__);
$files = glob($root . '/public/888/partials/*.php');
$files = array_merge($files, glob($root . '/public/888/*.php'));
$out = [];

foreach ($files as $path) {
    $html = file_get_contents($path);
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $gaps = [];

    // Strip script/style
    $work = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html);

    // Match tags with optional attrs and immediate text
    if (preg_match_all('/<([a-zA-Z0-9]+)([^>]*)>([^<]*[\x{4e00}-\x{9fff}][^<]*)</u', $work, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $tag = strtolower($hit[1]);
            $attrs = $hit[2];
            $text = trim(html_entity_decode($hit[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === '' || preg_match('/^\{\$/', $text)) continue;
            if (preg_match('/\bdata-copy(?:-placeholder|-aria)?\s*=/', $attrs)) continue;
            // skip option sometimes handled separately
            $gaps[] = ['kind' => 'text', 'tag' => $tag, 'text' => $text, 'attrs' => trim(preg_replace('/\s+/', ' ', $attrs))];
        }
    }

    // placeholders / aria / title / value without data-copy-*
    foreach (['placeholder', 'aria-label', 'title', 'value'] as $attr) {
        if (preg_match_all('/\b' . $attr . '="([^"]*[\x{4e00}-\x{9fff}][^"]*)"/u', $work, $pm, PREG_OFFSET_CAPTURE)) {
            foreach ($pm[0] as $i => $full) {
                $pos = $full[1];
                // look back for opening tag attrs
                $start = strrpos(substr($work, 0, $pos), '<');
                $snippet = $start === false ? '' : substr($work, $start, $pos - $start + strlen($full[0]));
                if (preg_match('/\bdata-copy(?:-placeholder|-aria)?\s*=/', $snippet)) continue;
                $gaps[] = ['kind' => $attr, 'tag' => '', 'text' => $pm[1][$i][0], 'attrs' => ''];
            }
        }
    }

    // dedupe by text+kind
    $seen = [];
    $uniq = [];
    foreach ($gaps as $g) {
        $k = $g['kind'] . '|' . $g['text'];
        if (isset($seen[$k])) continue;
        $seen[$k] = 1;
        $uniq[] = $g;
    }
    if ($uniq) {
        $out[$rel] = $uniq;
    }
}

file_put_contents($root . '/scripts/_i18n_gaps.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo 'files_with_gaps=' . count($out) . PHP_EOL;
$total = 0;
foreach ($out as $rel => $gaps) {
    $total += count($gaps);
    echo "\n## $rel (" . count($gaps) . ")\n";
    foreach ($gaps as $g) {
        echo '  [' . $g['kind'] . '] ' . ($g['tag'] ? '<' . $g['tag'] . '> ' : '') . $g['text'] . "\n";
    }
}
echo "\nTOTAL_GAPS=$total\n";
