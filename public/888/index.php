<?php
/**
 * 旧 H5 入口 /888 → 正式入口 /999（保留 query / hash）
 */
$q = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
    ? ('?' . $_SERVER['QUERY_STRING'])
    : '';
// hash 只能由前端带；PHP 跳转保留 query
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: /999/' . $q, true, 302);
exit;
