<?php
$root = dirname(__DIR__);
$env = @parse_ini_file($root . '/.env', true);
$d = is_array($env) ? ($env['database'] ?? []) : [];
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $d['hostname'] ?? '127.0.0.1',
    $d['hostport'] ?? 3306,
    $d['database'] ?? 'caijin_com_7111'
);
$pdo = new PDO($dsn, $d['username'] ?? 'root', $d['password'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$codeMap = [];
$manifest = json_decode(file_get_contents($root . '/public/888/data/stickers.json'), true);
foreach (($manifest['packs'] ?? []) as $pack) {
    $packId = (string)($pack['id'] ?? 'wechat');
    foreach (($pack['categories'] ?? []) as $cat) {
        foreach (($cat['items'] ?? []) as $item) {
            $code = (string)($item['code'] ?? '');
            $url = (string)($item['url'] ?? '');
            if ($code === '' || $url === '') continue;
            if ($url[0] !== '/') $url = '/888/' . ltrim($url, '/');
            $codeMap[$code] = ['pack' => $packId, 'code' => $code, 'url' => $url];
        }
    }
}
foreach ($pdo->query('SELECT name,url FROM fa_chat_user_stickers WHERE status=1')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $code = (string)$row['name'];
    $url = (string)$row['url'];
    if ($code === '' || $url === '') continue;
    $codeMap[$code] = ['pack' => 'custom', 'code' => $code, 'url' => $url];
}

echo 'map_size=' . count($codeMap) . ' has_泪=' . (isset($codeMap['流泪']) ? '1' : '0') . PHP_EOL;

$rows = $pdo->query("SELECT id,content FROM fa_chat_messages WHERE msg_type=1 AND content LIKE '[%]%'")->fetchAll(PDO::FETCH_ASSOC);
$upd = $pdo->prepare('UPDATE fa_chat_messages SET msg_type=6, extra=? WHERE id=?');
$fixed = 0;
foreach ($rows as $row) {
    $content = (string)$row['content'];
    if (!preg_match('/^\[(.+)\]$/u', $content, $m)) continue;
    $code = $m[1];
    if (!isset($codeMap[$code])) {
        echo "skip #{$row['id']} code={$code}\n";
        continue;
    }
    $upd->execute([json_encode($codeMap[$code], JSON_UNESCAPED_UNICODE), (int)$row['id']]);
    $fixed++;
    echo "fixed #{$row['id']} => {$code} url={$codeMap[$code]['url']}\n";
}
echo "done fixed={$fixed}\n";

foreach ($pdo->query("SELECT id,name,url,status FROM fa_chat_user_stickers ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo 'custom ' . json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
