<?php
$root = dirname(__DIR__);

$zhPath = $root . '/application/extra/fanshub_h5_copy.php';
$enPath = $root . '/application/extra/i18n/en-PH.php';
$zh = include $zhPath;
$en = include $enPath;

$zhAdd = [
    'promote_earn_title' => '推广收益数据表',
    'promote_earn_live' => '实时更新 >',
    'promote_earn_col_uid' => '用户ID',
    'promote_earn_col_type' => '收益类型',
    'promote_earn_col_detail' => '广细记录',
    'promote_earn_col_amount' => '到手佣金',
    'promote_earn_type_share' => '分享推广',
    'promote_earn_type_group' => '红包返佣',
    'promote_earn_detail_share_n' => '分享链接引流{n}人',
    'promote_earn_detail_groups_n' => '自建{n}群红包返利',
    'promote_earn_detail_multi' => '多群互动返现',
    'promote_earn_detail_exposure' => '推广曝光成交收益',
    'promote_earn_refreshed' => '已刷新模拟收益数据',
];

$enAdd = [
    'promote_earn_title' => 'Promo earnings table',
    'promote_earn_live' => 'Live update >',
    'promote_earn_col_uid' => 'User ID',
    'promote_earn_col_type' => 'Type',
    'promote_earn_col_detail' => 'Details',
    'promote_earn_col_amount' => 'Commission',
    'promote_earn_type_share' => 'Share promo',
    'promote_earn_type_group' => 'Red packet rebate',
    'promote_earn_detail_share_n' => 'Share link · {n} joins',
    'promote_earn_detail_groups_n' => '{n} groups · packet rebate',
    'promote_earn_detail_multi' => 'Multi-group cashback',
    'promote_earn_detail_exposure' => 'Exposure conversion earnings',
    'promote_earn_refreshed' => 'Mock earnings refreshed',
];

$zh = array_merge($zh, $zhAdd);
$en = array_merge($en, $enAdd);
file_put_contents($zhPath, "<?php\nreturn " . var_export($zh, true) . ";\n");
file_put_contents($enPath, "<?php\nreturn " . var_export($en, true) . ";\n");
echo 'zh=' . count($zh) . ' en=' . count($en) . "\n";

$index = file_get_contents($root . '/public/888/index.php');
$index = preg_replace('/\$assetVer\s*=\s*[\'"][^\'"]+[\'"]/', "\$assetVer = '202607260100'", $index, 1);
file_put_contents($root . '/public/888/index.php', $index);
echo "assetVer=202607260100\n";
