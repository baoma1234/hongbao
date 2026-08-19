<?php
/**
 * Complete km maps: merge km_a + translate remaining EN keys with glossary + curated rest.
 * Then build ms, emit all JSON, validate.
 */
$dir = __DIR__;
$root = dirname($dir);

function load_parts($dir, $prefix) {
    $out = [];
    foreach (['a','b','c','d'] as $p) {
        $f = "$dir/{$prefix}_{$p}.php";
        if (!is_file($f)) continue;
        $part = include $f;
        if (is_array($part) && $part) $out = array_merge($out, $part);
    }
    return $out;
}

function write_map($path, array $map) {
    $buf = "<?php\nreturn [\n";
    foreach ($map as $k => $v) {
        $buf .= '  ' . var_export((string)$k, true) . ' => ' . var_export((string)$v, true) . ",\n";
    }
    $buf .= "];\n";
    file_put_contents($path, $buf);
}

$en = load_parts($dir, 'en');
$vi = load_parts($dir, 'vi');
$id = load_parts($dir, 'id');
$kmA = is_file("$dir/km_a.php") ? include "$dir/km_a.php" : [];
$kmRestFile = "$dir/km_rest.php";
$kmRest = is_file($kmRestFile) ? include $kmRestFile : [];

echo "en=".count($en)." vi=".count($vi)." id=".count($id)." kmA=".count($kmA)." kmRest=".count($kmRest)."\n";

$km = array_merge($en, $kmRest, $kmA); // prefer kmA/kmRest over EN
$missKm = [];
foreach ($en as $k => $v) {
    if (!isset($kmA[$k]) && !isset($kmRest[$k])) $missKm[] = $k;
}
echo "km still using EN fallback: ".count($missKm)."\n";
if ($missKm) {
    // Write list for rest file generation
    file_put_contents("$dir/_km_miss_keys.txt", implode("\n", $missKm));
    echo "wrote _km_miss_keys.txt\n";
}

// Build MS from ID
include "$dir/_gen_ms_only.php";
