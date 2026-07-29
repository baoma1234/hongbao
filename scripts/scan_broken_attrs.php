<?php
/**
 * Find HTML attributes with unclosed quotes (common after encoding corruption).
 */
$path = dirname(__DIR__) . '/public/888/index.html';
$lines = file($path);
$n = 0;
foreach ($lines as $i => $line) {
    // Match aria-label="..." or similar where closing quote is missing before >
    if (preg_match_all('/\b([\w:-]+)="([^"]*?)(?:>|$)/u', $line, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            $attr = $m[1];
            $val = $m[2];
            // If the match ate up to > without a proper closing quote before >
            if (str_ends_with($m[0], '>') && !preg_match('/^[\w:-]+="[^"]*"$/', rtrim($m[0], '>'))) {
                // more precise: attribute value contains ? or garbled and no closing quote
            }
        }
    }
    // Simpler: line has attr="....> without closing quote before >
    if (preg_match('/\b[\w:-]+="[^"]*[?>][^"]*$/u', $line) || preg_match('/\b[\w:-]+="[^"]*\?>/u', $line)) {
        echo 'L' . ($i + 1) . '|' . rtrim($line) . "\n";
        $n++;
    }
    // aria-label="....?> pattern specifically
    if (preg_match('/="[^"]*\?>/', $line) || preg_match('/="[^"]*$/', rtrim($line)) && str_contains($line, 'aria-')) {
        if (!str_contains(rtrim($line), '="') ) continue;
        // count quotes after =
        if (preg_match_all('/aria-[a-z]+="([^"]*)(")?/i', $line, $am, PREG_SET_ORDER)) {
            foreach ($am as $a) {
                if (!isset($a[2]) || $a[2] !== '"') {
                    echo "UNCLOSED_ARIA L" . ($i + 1) . '|' . rtrim($line) . "\n";
                    $n++;
                }
            }
        }
    }
}
echo "found=$n\n";
