<?php
foreach (['en-PH','vi-VN','id-ID','ms-MY','km-KH'] as $c) {
    $d = json_decode(file_get_contents(dirname(__DIR__) . "/_tr_{$c}.json"), true);
    $n = 0;
    foreach ($d as $v) {
        if (mb_strpos($v, '红宝') !== false) $n++;
    }
    echo "$c keys=" . count($d) . " brand_hongbao_char=$n\n";
}
