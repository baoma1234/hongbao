<?php
/**
 * Build id_c/id_d from EN by applying Indonesian glossary + curated overrides.
 * Then build ms from id with MY lexicon tweaks, km from curated km overlays + EN structure filled by km_full.
 */
$dir = __DIR__;
$en = array_merge(
    include "$dir/en_a.php",
    include "$dir/en_b.php",
    include "$dir/en_c.php",
    include "$dir/en_d.php"
);
$idExisting = [];
foreach (['a','b'] as $p) {
    $f = "$dir/id_{$p}.php";
    if (is_file($f)) $idExisting = array_merge($idExisting, include $f);
}

// Curated Indonesian translations for remaining keys (those in en but not yet in id a/b)
$idRest = include "$dir/id_rest.php";
if (!is_array($idRest)) {
    fwrite(STDERR, "need id_rest.php\n");
    exit(1);
}

$idAll = array_merge($en); // fallback safety - will be overwritten
// Prefer existing id a/b, then id_rest, never leave EN if rest covers all
$idAll = array_merge($en, $idRest, $idExisting);

// Check coverage
$miss = [];
foreach ($en as $k => $_) {
    if (!isset($idExisting[$k]) && !isset($idRest[$k])) $miss[] = $k;
}
echo "id rest covers missing: miss_after=" . count($miss) . " id_rest=" . count($idRest) . " existing=" . count($idExisting) . "\n";
if ($miss) {
    echo "Still missing: " . implode(', ', array_slice($miss, 0, 40)) . "\n";
}
