<?php
$key = '9eT8zVu3z1ubzUPxkpFlDuZpX6A1q42';
$base = 'playername=fhdx888&pages=1&pageLength=10';
$baseNo = 'playername=fhdx888';
$kz = '6df47d40052c55e487092859d1c81d28';
$our = md5($base . '&sKey=' . $key);
$ourNo = md5($baseNo . '&sKey=' . $key);

echo "base=$base\n";
echo "our_with_pages=$our\n";
echo "our_no_pages=$ourNo\n";
echo "kz_with_pages=$kz\n\n";

function postSign($sign) {
    $ch = curl_init('https://sgcrm.rsei686nnw5n.com/sugarcrm/plist');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => http_build_query([
            'playername' => 'fhdx888',
            'pages' => 1,
            'pageLength' => 10,
            'sign' => $sign,
        ], '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'X-ENV: 555bioprod',
            'X-KZAPI-LANGUAGE: cn',
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $r];
}

list($code1, $r1) = postSign($kz);
echo "kz_live_http=$code1 body=$r1\n";
list($code2, $r2) = postSign($our);
echo "our_live_http=$code2 body=$r2\n";
