<?php
/**
 * Build remaining locale maps from EN by applying curated full translations
 * stored as JSON overlays. Writes id/ms/km a-d merged files, then emits all JSON.
 *
 * Strategy: start from EN map; overlay language-specific complete dict from
 * *_full.php if present; otherwise require parts.
 */
$dir = __DIR__;
$en = array_merge(
    include "$dir/en_a.php",
    include "$dir/en_b.php",
    include "$dir/en_c.php",
    include "$dir/en_d.php"
);

function write_php_map($path, array $map) {
    $buf = "<?php\nreturn [\n";
    foreach ($map as $k => $v) {
        $buf .= '  ' . var_export($k, true) . ' => ' . var_export($v, true) . ",\n";
    }
    $buf .= "];\n";
    file_put_contents($path, $buf);
}

// If id/ms/km full overlays exist, merge onto EN and split isn't needed —
// emit_json loads parts; so write single id_a.php containing ALL keys (same for ms/km).
foreach (['id', 'ms', 'km'] as $lang) {
    $overlayFile = "$dir/{$lang}_full.php";
    if (!is_file($overlayFile)) {
        echo "MISSING $overlayFile — skip generate\n";
        continue;
    }
    $overlay = include $overlayFile;
    if (!is_array($overlay)) {
        echo "BAD $overlayFile\n";
        continue;
    }
    $merged = array_merge($en, $overlay);
    // Clear old parts and write one combined as _a.php (emit loads a-d)
    foreach (['a','b','c','d'] as $p) {
        $f = "$dir/{$lang}_{$p}.php";
        if ($p === 'a') {
            write_php_map($f, $merged);
        } elseif (is_file($f) && $p !== 'a') {
            // keep empty stubs so merge doesn't pull stale partials
            file_put_contents($f, "<?php\nreturn [];\n");
        }
    }
    echo "$lang full keys=" . count($merged) . " overlay=" . count($overlay) . "\n";
}
