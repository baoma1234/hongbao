<?php
/**
 * Apply deep SEA translations into locale PHP + zh defaults + regenerate JS bundles.
 * php scripts/apply_deep_i18n.php
 */
$root = dirname(__DIR__);

$extraZh = [
    'fission_home_entry_title' => '全网裂变红宝',
    'fission_home_entry_sub_active' => '¥{pool} 奖金池 · {quals}/{cap} 份资格',
    'fission_home_entry_sub_ended_drawn' => '已开奖 · 入口已关闭',
    'fission_home_entry_sub_ended_void' => '未集齐已作废 · 关系仍保留',
    'fission_home_entry_go' => '去参与 ›',
    'fission_home_entry_ended' => '已结束',
    'profile_payee_bound' => '已绑定',
    'profile_payee_address_label' => '钱包地址',
    'profile_payee_address_ph' => '请输入钱包收款地址',
    'profile_payee_name_optional' => '备注姓名（可选）',
    'profile_payee_optional_ph' => '可选',
    'profile_payee_bind_btn' => '确认绑定',
    'profile_payee_update_btn' => '更新绑定',
    'profile_payee_submitting' => '提交中…',
    'profile_payee_usdt_trc20_only' => 'USDT 目前仅支持 TRC20 地址绑定',
    'profile_payee_addr_with_chain' => '{chain} 地址',
    'profile_payee_bound_prefix' => '已绑：',
    'profile_payee_pwd_len' => '支付密码需 6-32 位',
    'profile_payee_pwd_mismatch' => '两次密码不一致',
    'profile_payee_cancelled' => '已取消',
    'profile_payee_addr_short' => '地址长度至少 6 位',
    'profile_payee_bind_ok' => '绑定成功',
    'profile_payee_bind_fail' => '绑定失败',
    'profile_payee_need_one' => '请至少填写一条地址',
    'profile_payee_chain_addr_short' => '{chain} 地址至少 6 位',
    'profile_payee_default_name' => '钱包用户',
    'profile_payee_set_pwd_fail' => '设置失败',
    'profile_pay_password_updated' => '支付密码已更新',
    'profile_pay_password_set_ok' => '支付密码已设置',
    'profile_pay_password_sms_required' => '请填写短信验证码',
    'profile_payee_wallet_current' => '当前钱包：',
    'profile_payee_wallet_address_line' => '钱包地址：',
    'alert_cancel' => '取消',
    'alert_confirm' => '确认',
    'alert_load_fail' => '加载失败',
    'alert_operation_fail' => '操作失败',
];

function exportPhpArray(array $data)
{
    $export = var_export($data, true);
    // pretty: array ( → [
    return "<?php\n\n/**\n * FansHub H5 copy — auto\n */\nreturn " . $export . ";\n";
}

function writeLocalePhp($path, array $data, $code)
{
    $export = var_export($data, true);
    $body = "<?php\n\n/**\n * FansHub H5 copy — {$code}\n */\nreturn " . $export . ";\n";
    if (file_put_contents($path, $body) === false) {
        throw new RuntimeException("write fail: $path");
    }
}

// 1) Patch zh defaults
$h5Path = $root . '/application/extra/fanshub_h5_copy.php';
$zh = include $h5Path;
if (!is_array($zh)) {
    fwrite(STDERR, "bad fanshub_h5_copy.php\n");
    exit(1);
}
foreach ($extraZh as $k => $v) {
    if (!isset($zh[$k]) || $zh[$k] === '' || $zh[$k] === $k) {
        $zh[$k] = $v;
    }
}
// Force-update fission/payee helper keys to known Chinese
foreach ($extraZh as $k => $v) {
    if (strpos($k, 'fission_home_') === 0 || strpos($k, 'profile_payee_') === 0 || strpos($k, 'profile_pay_password_') === 0) {
        $zh[$k] = $v;
    }
}
file_put_contents($h5Path, "<?php\nreturn " . var_export($zh, true) . ";\n");
echo "zh defaults keys=" . count($zh) . "\n";

// Also patch fanshub.php embedded h5_copy if present
$fhPath = $root . '/application/extra/fanshub.php';
$fhRaw = file_get_contents($fhPath);
$fh = include $fhPath;
if (is_array($fh) && isset($fh['h5_copy']) && is_array($fh['h5_copy'])) {
    $changed = false;
    foreach ($extraZh as $k => $v) {
        if (!isset($fh['h5_copy'][$k])) {
            $fh['h5_copy'][$k] = $v;
            $changed = true;
        }
    }
    if ($changed) {
        // Safer: don't rewrite whole fanshub.php (has other config). Append via separate merge at runtime is enough via defaults.
        echo "fanshub.php h5_copy missing keys will come from defaults merge\n";
    }
}

$locales = ['en-PH', 'vi-VN', 'id-ID', 'ms-MY', 'km-KH'];

$extraTr = [
    'en-PH' => [
        'fission_home_entry_title' => 'Global fission Hongbao',
        'fission_home_entry_sub_active' => '¥{pool} prize pool · {quals}/{cap} slots',
        'fission_home_entry_sub_ended_drawn' => 'Drawn · entry closed',
        'fission_home_entry_sub_ended_void' => 'Not filled · voided (links kept)',
        'fission_home_entry_go' => 'Join ›',
        'fission_home_entry_ended' => 'Ended',
        'profile_payee_bound' => 'Bound',
        'profile_payee_address_label' => 'Wallet address',
        'profile_payee_address_ph' => 'Enter receiving wallet address',
        'profile_payee_name_optional' => 'Remark name (optional)',
        'profile_payee_optional_ph' => 'Optional',
        'profile_payee_bind_btn' => 'Confirm bind',
        'profile_payee_update_btn' => 'Update bind',
        'profile_payee_submitting' => 'Submitting…',
        'profile_payee_usdt_trc20_only' => 'USDT currently supports TRC20 only',
        'profile_payee_addr_with_chain' => '{chain} address',
        'profile_payee_bound_prefix' => 'Bound: ',
        'profile_payee_pwd_len' => 'Payment password must be 6–32 characters',
        'profile_payee_pwd_mismatch' => 'Passwords do not match',
        'profile_payee_cancelled' => 'Cancelled',
        'profile_payee_addr_short' => 'Address must be at least 6 characters',
        'profile_payee_bind_ok' => 'Bound successfully',
        'profile_payee_bind_fail' => 'Bind failed',
        'profile_payee_need_one' => 'Enter at least one address',
        'profile_payee_chain_addr_short' => '{chain} address must be at least 6 characters',
        'profile_payee_default_name' => 'Wallet user',
        'profile_payee_set_pwd_fail' => 'Failed to set',
        'profile_pay_password_updated' => 'Payment password updated',
        'profile_pay_password_set_ok' => 'Payment password set',
        'profile_pay_password_sms_required' => 'Enter SMS verification code',
        'profile_payee_wallet_current' => 'Current wallet: ',
        'profile_payee_wallet_address_line' => 'Wallet address: ',
        'alert_cancel' => 'Cancel',
        'alert_confirm' => 'Confirm',
        'alert_load_fail' => 'Load failed',
        'alert_operation_fail' => 'Operation failed',
    ],
    'vi-VN' => [
        'fission_home_entry_title' => 'Hồng bao phân nhánh toàn mạng',
        'fission_home_entry_sub_active' => 'Quỹ thưởng ¥{pool} · {quals}/{cap} suất',
        'fission_home_entry_sub_ended_drawn' => 'Đã mở thưởng · cửa vào đã đóng',
        'fission_home_entry_sub_ended_void' => 'Chưa đủ số · đã hủy (quan hệ vẫn giữ)',
        'fission_home_entry_go' => 'Tham gia ›',
        'fission_home_entry_ended' => 'Đã kết thúc',
        'profile_payee_bound' => 'Đã gắn',
        'profile_payee_address_label' => 'Địa chỉ ví',
        'profile_payee_address_ph' => 'Nhập địa chỉ ví nhận',
        'profile_payee_name_optional' => 'Tên ghi chú (tuỳ chọn)',
        'profile_payee_optional_ph' => 'Tuỳ chọn',
        'profile_payee_bind_btn' => 'Xác nhận gắn',
        'profile_payee_update_btn' => 'Cập nhật gắn',
        'profile_payee_submitting' => 'Đang gửi…',
        'profile_payee_usdt_trc20_only' => 'USDT hiện chỉ hỗ trợ địa chỉ TRC20',
        'profile_payee_addr_with_chain' => 'Địa chỉ {chain}',
        'profile_payee_bound_prefix' => 'Đã gắn: ',
        'profile_payee_pwd_len' => 'Mật khẩu thanh toán cần 6–32 ký tự',
        'profile_payee_pwd_mismatch' => 'Hai lần nhập không khớp',
        'profile_payee_cancelled' => 'Đã hủy',
        'profile_payee_addr_short' => 'Địa chỉ tối thiểu 6 ký tự',
        'profile_payee_bind_ok' => 'Gắn thành công',
        'profile_payee_bind_fail' => 'Gắn thất bại',
        'profile_payee_need_one' => 'Vui lòng nhập ít nhất một địa chỉ',
        'profile_payee_chain_addr_short' => 'Địa chỉ {chain} tối thiểu 6 ký tự',
        'profile_payee_default_name' => 'Người dùng ví',
        'profile_payee_set_pwd_fail' => 'Thiết lập thất bại',
        'profile_pay_password_updated' => 'Đã cập nhật mật khẩu thanh toán',
        'profile_pay_password_set_ok' => 'Đã đặt mật khẩu thanh toán',
        'profile_pay_password_sms_required' => 'Vui lòng nhập mã SMS',
        'profile_payee_wallet_current' => 'Ví hiện tại: ',
        'profile_payee_wallet_address_line' => 'Địa chỉ ví: ',
        'alert_cancel' => 'Hủy',
        'alert_confirm' => 'Xác nhận',
        'alert_load_fail' => 'Tải thất bại',
        'alert_operation_fail' => 'Thao tác thất bại',
    ],
    'id-ID' => [
        'fission_home_entry_title' => 'Hongbao fisi jaringan',
        'fission_home_entry_sub_active' => 'Pool hadiah ¥{pool} · {quals}/{cap} slot',
        'fission_home_entry_sub_ended_drawn' => 'Sudah diundi · masuk ditutup',
        'fission_home_entry_sub_ended_void' => 'Tidak penuh · dibatalkan (relasi tetap)',
        'fission_home_entry_go' => 'Ikuti ›',
        'fission_home_entry_ended' => 'Berakhir',
        'profile_payee_bound' => 'Terikat',
        'profile_payee_address_label' => 'Alamat dompet',
        'profile_payee_address_ph' => 'Masukkan alamat dompet penerima',
        'profile_payee_name_optional' => 'Nama catatan (opsional)',
        'profile_payee_optional_ph' => 'Opsional',
        'profile_payee_bind_btn' => 'Konfirmasi ikat',
        'profile_payee_update_btn' => 'Perbarui ikatan',
        'profile_payee_submitting' => 'Mengirim…',
        'profile_payee_usdt_trc20_only' => 'USDT saat ini hanya mendukung TRC20',
        'profile_payee_addr_with_chain' => 'Alamat {chain}',
        'profile_payee_bound_prefix' => 'Terikat: ',
        'profile_payee_pwd_len' => 'Kata sandi pembayaran 6–32 karakter',
        'profile_payee_pwd_mismatch' => 'Kata sandi tidak cocok',
        'profile_payee_cancelled' => 'Dibatalkan',
        'profile_payee_addr_short' => 'Alamat minimal 6 karakter',
        'profile_payee_bind_ok' => 'Berhasil diikat',
        'profile_payee_bind_fail' => 'Gagal mengikat',
        'profile_payee_need_one' => 'Isi setidaknya satu alamat',
        'profile_payee_chain_addr_short' => 'Alamat {chain} minimal 6 karakter',
        'profile_payee_default_name' => 'Pengguna dompet',
        'profile_payee_set_pwd_fail' => 'Gagal mengatur',
        'profile_pay_password_updated' => 'Kata sandi pembayaran diperbarui',
        'profile_pay_password_set_ok' => 'Kata sandi pembayaran disetel',
        'profile_pay_password_sms_required' => 'Masukkan kode SMS',
        'profile_payee_wallet_current' => 'Dompet saat ini: ',
        'profile_payee_wallet_address_line' => 'Alamat dompet: ',
        'alert_cancel' => 'Batal',
        'alert_confirm' => 'Konfirmasi',
        'alert_load_fail' => 'Gagal memuat',
        'alert_operation_fail' => 'Operasi gagal',
    ],
    'ms-MY' => [
        'fission_home_entry_title' => 'Hongbao fisi seluruh rangkaian',
        'fission_home_entry_sub_active' => 'Kolam hadiah ¥{pool} · {quals}/{cap} slot',
        'fission_home_entry_sub_ended_drawn' => 'Sudah cabut · pintu ditutup',
        'fission_home_entry_sub_ended_void' => 'Tidak cukup · dibatalkan (hubungan kekal)',
        'fission_home_entry_go' => 'Sertai ›',
        'fission_home_entry_ended' => 'Tamat',
        'profile_payee_bound' => 'Terikat',
        'profile_payee_address_label' => 'Alamat dompet',
        'profile_payee_address_ph' => 'Masukkan alamat dompet penerima',
        'profile_payee_name_optional' => 'Nama catatan (pilihan)',
        'profile_payee_optional_ph' => 'Pilihan',
        'profile_payee_bind_btn' => 'Sahkan ikat',
        'profile_payee_update_btn' => 'Kemas kini ikatan',
        'profile_payee_submitting' => 'Menghantar…',
        'profile_payee_usdt_trc20_only' => 'USDT kini hanya menyokong TRC20',
        'profile_payee_addr_with_chain' => 'Alamat {chain}',
        'profile_payee_bound_prefix' => 'Terikat: ',
        'profile_payee_pwd_len' => 'Kata laluan bayaran 6–32 aksara',
        'profile_payee_pwd_mismatch' => 'Kata laluan tidak sepadan',
        'profile_payee_cancelled' => 'Dibatalkan',
        'profile_payee_addr_short' => 'Alamat sekurang-kurangnya 6 aksara',
        'profile_payee_bind_ok' => 'Berjaya diikat',
        'profile_payee_bind_fail' => 'Gagal mengikat',
        'profile_payee_need_one' => 'Sila isi sekurang-kurangnya satu alamat',
        'profile_payee_chain_addr_short' => 'Alamat {chain} sekurang-kurangnya 6 aksara',
        'profile_payee_default_name' => 'Pengguna dompet',
        'profile_payee_set_pwd_fail' => 'Gagal menetapkan',
        'profile_pay_password_updated' => 'Kata laluan bayaran dikemas kini',
        'profile_pay_password_set_ok' => 'Kata laluan bayaran ditetapkan',
        'profile_pay_password_sms_required' => 'Sila masukkan kod SMS',
        'profile_payee_wallet_current' => 'Dompet semasa: ',
        'profile_payee_wallet_address_line' => 'Alamat dompet: ',
        'alert_cancel' => 'Batal',
        'alert_confirm' => 'Sahkan',
        'alert_load_fail' => 'Gagal memuat',
        'alert_operation_fail' => 'Operasi gagal',
    ],
    'km-KH' => [
        'fission_home_entry_title' => 'កញ្ចប់ក្រហមបំបែកទូទាំងបណ្តាញ',
        'fission_home_entry_sub_active' => 'អាងរង្វាន់ ¥{pool} · {quals}/{cap} រន្ធ',
        'fission_home_entry_sub_ended_drawn' => 'បានចាប់រង្វាន់ · ច្រកបានបិទ',
        'fission_home_entry_sub_ended_void' => 'មិនគ្រប់ · បានលុប (ទំនាក់ទំនងនៅ)',
        'fission_home_entry_go' => 'ចូលរួម ›',
        'fission_home_entry_ended' => 'បានបញ្ចប់',
        'profile_payee_bound' => 'បានភ្ជាប់',
        'profile_payee_address_label' => 'អាសយដ្ឋានកាបូប',
        'profile_payee_address_ph' => 'បញ្ចូលអាសយដ្ឋានកាបូបទទួល',
        'profile_payee_name_optional' => 'ឈ្មោះកំណត់សម្គាល់ (ជម្រើស)',
        'profile_payee_optional_ph' => 'ជម្រើស',
        'profile_payee_bind_btn' => 'បញ្ជាក់ភ្ជាប់',
        'profile_payee_update_btn' => 'ធ្វើបច្ចុប្បន្នភាព',
        'profile_payee_submitting' => 'កំពុងផ្ញើ…',
        'profile_payee_usdt_trc20_only' => 'USDT បច្ចុប្បន្នគាំទ្រតែ TRC20',
        'profile_payee_addr_with_chain' => 'អាសយដ្ឋាន {chain}',
        'profile_payee_bound_prefix' => 'បានភ្ជាប់៖ ',
        'profile_payee_pwd_len' => 'ពាក្យសម្ងាត់បង់ប្រាក់ ៦–៣២ តួ',
        'profile_payee_pwd_mismatch' => 'ពាក្យសម្ងាត់មិនត្រូវគ្នា',
        'profile_payee_cancelled' => 'បានបោះបង់',
        'profile_payee_addr_short' => 'អាសយដ្ឋានយ៉ាងតិច ៦ តួ',
        'profile_payee_bind_ok' => 'ភ្ជាប់ជោគជ័យ',
        'profile_payee_bind_fail' => 'ភ្ជាប់បរាជ័យ',
        'profile_payee_need_one' => 'សូមបញ្ចូលយ៉ាងហោចណាស់មួយ',
        'profile_payee_chain_addr_short' => 'អាសយដ្ឋាន {chain} យ៉ាងតិច ៦ តួ',
        'profile_payee_default_name' => 'អ្នកប្រើកាបូប',
        'profile_payee_set_pwd_fail' => 'កំណត់បរាជ័យ',
        'profile_pay_password_updated' => 'បានធ្វើបច្ចុប្បន្នភាពពាក្យសម្ងាត់បង់',
        'profile_pay_password_set_ok' => 'បានកំណត់ពាក្យសម្ងាត់បង់',
        'profile_pay_password_sms_required' => 'សូមបញ្ចូលលេខកូដ SMS',
        'profile_payee_wallet_current' => 'កាបូបបច្ចុប្បន្ន៖ ',
        'profile_payee_wallet_address_line' => 'អាសយដ្ឋានកាបូប៖ ',
        'alert_cancel' => 'បោះបង់',
        'alert_confirm' => 'បញ្ជាក់',
        'alert_load_fail' => 'ផ្ទុកបរាជ័យ',
        'alert_operation_fail' => 'ប្រតិបត្តិការបរាជ័យ',
    ],
];

foreach ($locales as $code) {
    $jsonPath = $root . '/scripts/_tr_' . $code . '.json';
    if (!is_file($jsonPath)) {
        fwrite(STDERR, "missing $jsonPath\n");
        exit(1);
    }
    $tr = json_decode(file_get_contents($jsonPath), true);
    if (!is_array($tr)) {
        fwrite(STDERR, "bad json $jsonPath\n");
        exit(1);
    }
    if (!empty($extraTr[$code])) {
        foreach ($extraTr[$code] as $k => $v) {
            $tr[$k] = $v;
        }
    }
    // Keep only keys that exist in zh defaults (after extras)
    $out = [];
    foreach ($zh as $k => $_v) {
        if (isset($tr[$k]) && $tr[$k] !== null && $tr[$k] !== '') {
            $out[$k] = (string)$tr[$k];
        }
    }
    $path = $root . '/application/extra/i18n/' . $code . '.php';
    writeLocalePhp($path, $out, $code);
    echo "$code wrote " . count($out) . "\n";
}

// 2) Bootstrap ThinkPHP and regenerate JS
define('APP_PATH', $root . '/application/');
require $root . '/thinkphp/base.php';
\think\Container::get('app')->path(APP_PATH)->initialize();
$ok = \app\common\library\FansHubService::regenerateI18nBundle();
echo 'regenerateI18nBundle=' . ($ok ? 'ok' : 'FAIL') . "\n";

// 3) Sync to public/999/i18n + uni-999 static (build script also does this)
$src = $root . '/public/888/i18n';
foreach ([
    $root . '/public/999/i18n',
    $root . '/uni-999/src/static/i18n',
] as $dest) {
    if (!is_dir($src)) {
        echo "WARN no $src\n";
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $file) {
        $target = $dest . '/' . $it->getSubPathName();
        if ($file->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0755, true);
            }
        } else {
            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            copy($file->getPathname(), $target);
        }
    }
    echo "synced -> $dest\n";
}

echo "DONE\n";
