<?php
/**
 * 复制钱包图标到 public/assets/img/wallets/ 并写入通道 icon
 */
$root = dirname(__DIR__);
$srcDir = 'C:/Users/Administrator/.cursor/projects/c-wwwroot-caijin-com-7111/assets';
$destDir = $root . '/public/assets/img/wallets';
if (!is_dir($destDir)) {
    mkdir($destDir, 0755, true);
}

// 文件名片段 => 通道编码（优先用可辨识文件名）
$map = [
    'images_365__'   => 'Sanliuwu',
    'images_234__'   => 'Ersansi',
    'images_808__'   => 'Balingba',
    'images_988-'    => 'Jiubaba',
    'images_ABpay-'  => 'Abpay',
    'images_AG__-'   => 'Agpay',
    'images_CB__-'   => 'Cbi',
    'images_FPAY-'   => 'Fpay',
    'images_gopay-'  => 'Gopay',
    'images_K_-'     => 'Kdou',
    'images_HD__-'   => 'Hdpay',
    'images_Mpay-'   => 'Mpay',
    'images_JD__-'   => 'Jdpay',
    'images_M___-'   => 'Mbpay',
    'images_NO__-'   => 'Nopay',
    'images_Okpay-'  => 'Okpay',
    'images_TOpay-'  => 'Topay',
    'images_UB-'     => 'Bobi',
    'images_vip__-'  => 'Vippay',
    // 币趣支付（带中文）
    'images_____-65ba5e68' => 'Biqu',
    // 钱能
    'images___-12a254b7'   => 'Qianneng',
    // 波币 bobi.co 备选（若 UB 已作 Bobi 则覆盖为更清晰的）
    'images_____2-904acc60' => 'Bobi',
];

$files = glob($srcDir . '/*.png') ?: [];
$copied = [];
foreach ($files as $file) {
    $base = basename($file);
    foreach ($map as $needle => $code) {
        if (strpos($base, $needle) !== false) {
            $dest = $destDir . '/' . $code . '.png';
            if (!copy($file, $dest)) {
                echo "FAIL copy {$code}\n";
                continue 2;
            }
            $copied[$code] = '/assets/img/wallets/' . $code . '.png';
            echo "OK {$code} <= " . substr($base, -40) . "\n";
            continue 2;
        }
    }
}

// Upay / Wanbi / Goubaopay 若缺图标，跳过
$missing = array_diff(
    ['Kdou','Abpay','Cbi','Jdpay','Sanliuwu','Hdpay','Mbpay','Qianneng','Fpay','Jiubaba','Balingba','Ersansi','Vippay','Upay','Okpay','Topay','Gopay','Nopay','Goubaopay','Agpay','Wanbi','Biqu','Bobi','Mpay'],
    array_keys($copied)
);
if ($missing) {
    echo "NO ICON YET: " . implode(', ', $missing) . "\n";
}

$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    'mysql:host=' . $d['hostname'] . ';dbname=' . $d['database'] . ';charset=utf8mb4',
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$st = $pdo->prepare("UPDATE {$prefix}fans_pay_channel SET icon=?, tip='', updatetime=? WHERE handler='wanhuitong' AND pay_channel=?");
$n = 0;
$now = time();
foreach ($copied as $code => $url) {
    $st->execute([$url, $now, $code]);
    $n += $st->rowCount();
}
echo "DB icon updated rows={$n}\n";
echo "done. icons in {$destDir}\n";
