<?php
$path = __DIR__ . '/../public/888/index.html';
$text = file_get_contents($path);
$d = 'di' . 'v';
$text = str_replace('?/motion>', '</' . $d . '>', $text);
$text = preg_replace('/锛\?strong/', '：<strong>', $text);
$text = preg_replace('/placeholder="([^"]*)\?>/', 'placeholder="$1">', $text);
$text = str_replace(
    '<' . $d . ' class="profile-sub-title">鍏呭€</' . $d . '>',
    '<' . $d . ' class="profile-sub-title">充值</' . $d . '>',
    $text
);
$text = preg_replace(
    '/\s*<\/' . $d . '>\s*\n\s*<\/' . $d . '>\s*\n\s*<!-- 涓/',
    "\n    </{$d}>\n\n    <!-- 充",
    $text,
    1
);
file_put_contents($path, $text);
echo "fixed\n";
