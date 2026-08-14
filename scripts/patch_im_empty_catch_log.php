<?php
/**
 * Replace empty catch (\Throwable $e) {} in im-server/App with CatchLog::quiet.
 * Dry-run: php scripts/patch_im_empty_catch_log.php
 * Apply:   php scripts/patch_im_empty_catch_log.php --apply
 */
$apply = in_array('--apply', $argv, true);
$root = dirname(__DIR__) . '/im-server/App';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$total = 0;
$files = 0;
foreach ($rii as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $path = $f->getPathname();
    if (strpos($path, 'CatchLog.php') !== false) {
        continue;
    }
    $src = file_get_contents($path);
    $orig = $src;
    $ns = '';
    if (preg_match('/namespace\s+([\w\\\\]+)\s*;/', $src, $nm)) {
        $ns = $nm[1];
    }
    $needUse = ($ns !== 'Im\\Support');
    $hasUse = strpos($src, 'use Im\\Support\\CatchLog;') !== false
        || strpos($src, 'use Im\\Support\\CatchLog ') !== false;

    $count = 0;
    $src = preg_replace_callback(
        '/catch\s*\(\s*(\\\\?Throwable)\s+(\$\w+)\s*\)\s*\{\s*\}/s',
        function ($m) use (&$count, $path, $root) {
            $count++;
            $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
            $tag = preg_replace('/\.php$/', '', $rel);
            $tag = str_replace('/', '.', $tag);
            $var = $m[2];
            return "catch ({$m[1]} {$var}) {\n            \\Im\\Support\\CatchLog::quiet({$var}, '{$tag}');\n        }";
        },
        $src
    );
    if ($count <= 0) {
        continue;
    }
    // Prefer short CatchLog if we can add use
    if ($needUse && !$hasUse) {
        if (preg_match('/^(namespace[^\n]+\n)/m', $src, $nm)) {
            $src = preg_replace(
                '/^(namespace[^\n]+\n)/m',
                "$1\nuse Im\\Support\\CatchLog;\n",
                $src,
                1
            );
            $src = str_replace('\\Im\\Support\\CatchLog::quiet', 'CatchLog::quiet', $src);
        }
    } elseif (!$needUse) {
        $src = str_replace('\\Im\\Support\\CatchLog::quiet', 'CatchLog::quiet', $src);
    } else {
        $src = str_replace('\\Im\\Support\\CatchLog::quiet', 'CatchLog::quiet', $src);
    }

    // Avoid duplicate use
    if (substr_count($src, 'use Im\\Support\\CatchLog;') > 1) {
        $first = true;
        $src = preg_replace_callback(
            '/\nuse Im\\\\Support\\\\CatchLog;\n/',
            function ($m) use (&$first) {
                if ($first) {
                    $first = false;
                    return $m[0];
                }
                return "\n";
            },
            $src
        );
    }

    $total += $count;
    $files++;
    $rel = str_replace('\\', '/', substr($path, strlen(dirname(__DIR__)) + 1));
    echo ($apply ? 'PATCH ' : 'DRY  ') . $count . "\t" . $rel . "\n";
    if ($apply && $src !== $orig) {
        file_put_contents($path, $src);
    }
}
echo ($apply ? 'APPLIED' : 'DRY-RUN') . " files={$files} catches={$total}\n";
