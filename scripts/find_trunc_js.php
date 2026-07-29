<?php
$path = dirname(__DIR__) . '/public/888/index.html';
$lines = file($path);
$issues = [];
foreach ($lines as $i => $line) {
    $trim = rtrim($line);
    if ($trim === '') continue;
    // Suspicious: ends mid-expression without ; ) } , ] 
    if (!preg_match('/[;{})\],]`?\s*$/', $trim) && !preg_match('/^\s*(\/\/|\/\*|\*|else|else\s*\{|{\s*$)/', $trim)) {
        // function/assignment that looks incomplete
        if (preg_match('/\b(String|Number|parseInt|parseFloat|fc|trim|toE164)\s*\(\s*[^)]*$/', $trim)
            || preg_match('/=\s*\([^;]*$/', $trim) && substr_count($trim, '(') > substr_count($trim, ')')
            || preg_match('/\|\|\s*$/', $trim)
            || preg_match('/\?\s*$/', $trim) && !preg_match('/\?\s*$/', '') // ternary start
        ) {
            $open = substr_count($trim, '(');
            $close = substr_count($trim, ')');
            if ($open > $close || preg_match('/\bString\s*\(\s*\w+\s*$/', $trim) || preg_match('/\|\|\s*$/', $trim)) {
                $issues[] = ($i + 1) . ' :: ' . substr($trim, 0, 140);
            }
        }
    }
}
echo "issues=" . count($issues) . "\n";
foreach (array_slice($issues, 0, 50) as $x) echo $x . "\n";
