<?php

namespace Im\Support;

/**
 * Log swallowed exceptions so empty catch blocks stay non-fatal but searchable.
 */
class CatchLog
{
    public static function quiet(\Throwable $e, $tag = '')
    {
        $tag = trim((string)$tag);
        $prefix = $tag !== '' ? '[IM][' . $tag . '] ' : '[IM] ';
        error_log($prefix . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
}
