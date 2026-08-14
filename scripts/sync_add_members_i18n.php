<?php
/**
 * 同步「添加群成员」相关 H5 文案到各语言包，并导出 copy.defaults / locales
 * php scripts/sync_add_members_i18n.php
 */
$root = dirname(__DIR__);

$byLocale = [
    'en-PH' => [
        'chat_add_members_btn'   => '+ Add members',
        'chat_add_members_title' => 'Add members',
        'chat_confirm_add'       => 'Add ({count})',
        'chat_invite_search_ph'  => 'Search name / mobile / ID',
        'chat_view_members'      => 'View members',
        'chat_no_candidates'     => 'No users to add',
        'chat_member_search_ph'  => 'Search nickname / ID',
    ],
    'id-ID' => [
        'chat_add_members_btn'   => '+ Tambah anggota',
        'chat_add_members_title' => 'Tambah anggota',
        'chat_confirm_add'       => 'Tambah ({count})',
        'chat_invite_search_ph'  => 'Cari nama / HP / ID',
        'chat_view_members'      => 'Lihat anggota',
        'chat_no_candidates'     => 'Tidak ada pengguna untuk ditambahkan',
        'chat_member_search_ph'  => 'Cari nama panggilan / ID',
    ],
    'vi-VN' => [
        'chat_add_members_btn'   => '+ Thêm thành viên',
        'chat_add_members_title' => 'Thêm thành viên',
        'chat_confirm_add'       => 'Thêm ({count})',
        'chat_invite_search_ph'  => 'Tìm tên / SĐT / ID',
        'chat_view_members'      => 'Xem thành viên',
        'chat_no_candidates'     => 'Không có người dùng để thêm',
        'chat_member_search_ph'  => 'Tìm biệt danh / ID',
    ],
    'ms-MY' => [
        'chat_add_members_btn'   => '+ Tambah ahli',
        'chat_add_members_title' => 'Tambah ahli',
        'chat_confirm_add'       => 'Tambah ({count})',
        'chat_invite_search_ph'  => 'Cari nama / telefon / ID',
        'chat_view_members'      => 'Lihat ahli',
        'chat_no_candidates'     => 'Tiada pengguna untuk ditambah',
        'chat_member_search_ph'  => 'Cari nama panggilan / ID',
    ],
    'km-KH' => [
        'chat_add_members_btn'   => '+ បន្ថែមសមាជិក',
        'chat_add_members_title' => 'បន្ថែមសមាជិក',
        'chat_confirm_add'       => 'បន្ថែម ({count})',
        'chat_invite_search_ph'  => 'ស្វែងរក ឈ្មោះ / ទូរស័ព្ទ / ID',
        'chat_view_members'      => 'មើលសមាជិក',
        'chat_no_candidates'     => 'គ្មានអ្នកប្រើប្រាស់សម្រាប់បន្ថែម',
        'chat_member_search_ph'  => 'ស្វែងរក ឈ្មោះហៅ / ID',
    ],
];

foreach ($byLocale as $code => $keys) {
    $path = $root . '/application/extra/i18n/' . $code . '.php';
    if (!is_file($path)) {
        echo "MISS $code\n";
        continue;
    }
    $data = include $path;
    if (!is_array($data)) {
        echo "BAD $code\n";
        continue;
    }
    $changed = false;
    foreach ($keys as $k => $v) {
        if (!array_key_exists($k, $data) || $data[$k] === '' || $data[$k] === $k) {
            $data[$k] = $v;
            $changed = true;
        }
    }
    if (!$changed) {
        echo "skip $code\n";
        continue;
    }
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");
    echo "patched $code\n";
}

// 确保中文源有完整 key
$zhPath = $root . '/application/extra/fanshub_h5_copy.php';
$zh = include $zhPath;
$zhNeed = [
    'chat_add_members_btn'   => '＋ 添加群成员',
    'chat_add_members_title' => '添加群成员',
    'chat_confirm_add'       => '确认添加 ({count} 人)',
    'chat_invite_search_ph'  => '搜索用户名/手机号/ID',
    'chat_view_members'      => '查看群成员',
    'chat_no_candidates'     => '暂无可添加用户',
    'chat_member_search_ph'  => '搜索成员昵称/ID',
];
$zhChanged = false;
foreach ($zhNeed as $k => $v) {
    if (!isset($zh[$k]) || $zh[$k] === '' || $zh[$k] === $k) {
        $zh[$k] = $v;
        $zhChanged = true;
    }
}
if ($zhChanged) {
    file_put_contents($zhPath, "<?php\nreturn " . var_export($zh, true) . ";\n");
    echo "patched fanshub_h5_copy.php\n";
}

// 同步 fanshub.php 内嵌 h5_copy（若存在）
$fansPath = $root . '/application/extra/fanshub.php';
if (is_file($fansPath)) {
    $fans = include $fansPath;
    if (is_array($fans) && isset($fans['h5_copy']) && is_array($fans['h5_copy'])) {
        $fc = false;
        foreach ($zhNeed as $k => $v) {
            if (!isset($fans['h5_copy'][$k]) || $fans['h5_copy'][$k] === '' || $fans['h5_copy'][$k] === $k) {
                $fans['h5_copy'][$k] = $v;
                $fc = true;
            }
        }
        if ($fc) {
            // 不整文件重写 fanshub.php（可能含复杂结构）；用正则补 key 风险高，改走 seed 脚本
            echo "fanshub.php h5_copy needs merge via seed_h5_copy_defaults\n";
        }
    }
}

echo "run: php scripts/export_copy_defaults.php && php scripts/generate_i18n_locales.php\n";
echo "done\n";
