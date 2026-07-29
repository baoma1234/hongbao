<?php
$path = dirname(__DIR__) . '/public/888/index.html';
$lines = file($path);
$fixed = 0;
foreach ($lines as $i => $l) {
    // Pattern: code // comment  then more code or } on same line after spaces
    if (!str_contains($l, '//')) continue;
    if (preg_match('/^(\s*)(.*?)(\/\/[^\r\n]*?)([ \t]{2,})(\}.*|[a-zA-Z_$].*)$/u', $l, $m)) {
        $indent = $m[1];
        $before = $m[2]; // may be empty or code before comment
        $comment = rtrim($m[3]);
        $after = $m[5];
        // Only if comment looks like it ate the rest (garbled end or after is statement/brace)
        $new = $indent . $before . $comment . "\n";
        // Determine indent for after
        if (preg_match('/^\}/', $after)) {
            // closing brace - use indent of the line that opened, typically less than comment indent
            $braceIndent = preg_replace('/    $/', '', $indent);
            if ($braceIndent === $indent && strlen($indent) >= 4) {
                $braceIndent = substr($indent, 0, -4);
            }
            $new .= $braceIndent . $after;
        } else {
            $new .= $indent . $after;
        }
        if (!str_ends_with($new, "\n")) $new .= "\n";
        echo 'FIX L' . ($i + 1) . "\n  OLD: " . rtrim($l) . "\n  NEW: " . str_replace("\n", " | ", rtrim($new)) . "\n";
        $lines[$i] = $new;
        $fixed++;
    }
}
file_put_contents($path, implode('', $lines));
echo "fixed=$fixed\n";
