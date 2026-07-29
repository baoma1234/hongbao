<?php
function u($s){return json_decode('"'.$s.'"');}
$c=file_get_contents('application/admin/view/fanshub/config/basic.html');
$s='\\u4fdd\\u5b58\\u672c\\u9875\\u914d\\u7f6e'; $n=u($s);
echo bin2hex($s).PHP_EOL; echo bin2hex($n).PHP_EOL; $p=strpos($c,$n); var_export($p); echo PHP_EOL; $q='{:url(\'fanshub.config/save\')}'; var_export(strpos($c,$q)); echo PHP_EOL;
