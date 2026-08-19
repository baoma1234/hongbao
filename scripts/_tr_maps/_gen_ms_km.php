<?php
/**
 * Generate ms-MY and km-KH maps, then emit all JSON packs.
 */
$dir = __DIR__;
$root = dirname($dir);

function load_lang($dir, $prefix) {
    $out = [];
    foreach (['a','b','c','d'] as $p) {
        $f = "$dir/{$prefix}_{$p}.php";
        if (is_file($f)) {
            $part = include $f;
            if (is_array($part)) $out = array_merge($out, $part);
        }
    }
    return $out;
}

function write_map($path, array $map) {
    $buf = "<?php\nreturn [\n";
    foreach ($map as $k => $v) {
        $buf .= '  ' . var_export((string)$k, true) . ' => ' . var_export((string)$v, true) . ",\n";
    }
    $buf .= "];\n";
    file_put_contents($path, $buf);
}

$en = load_lang($dir, 'en');
$id = load_lang($dir, 'id');
$vi = load_lang($dir, 'vi');

echo "en=" . count($en) . " vi=" . count($vi) . " id=" . count($id) . "\n";

// --- Malay from Indonesian with MY lexicon + currency ---
$msPairs = [
    'Rp' => 'RM',
    'Nomor HP' => 'Nombor telefon',
    'nomor HP' => 'nombor telefon',
    'HP ' => 'Telefon ',
    ' HP' => ' telefon',
    'Masukkan' => 'Masukkan',
    'Tarik' => 'Keluarkan',
    'tarik' => 'keluarkan',
    'ditarik' => 'dikeluarkan',
    'Klaim' => 'Tuntut',
    'klaim' => 'tuntut',
    'Anggota' => 'Ahli',
    'anggota' => 'ahli',
    'Pengguna' => 'Pengguna',
    'Sandi' => 'Kata laluan',
    'sandi' => 'kata laluan',
    'Amplop' => 'Sampul',
    'amplop' => 'sampul',
    'Grup' => 'Kumpulan',
    'grup' => 'kumpulan',
    'Chat' => 'Sembang',
    'chat' => 'sembang',
    'Login' => 'Log masuk',
    'login' => 'log masuk',
    'Logout' => 'Log keluar',
    'Keluar' => 'Keluar',
    'Unduh' => 'Muat turun',
    'unduh' => 'muat turun',
    'Pindai' => 'Imbas',
    'pindai' => 'imbas',
    'Teman' => 'Rakan',
    'teman' => 'rakan',
    'Bisukan' => 'Senyapkan',
    'bisukan' => 'senyapkan',
    'Bisu' => 'Senyap',
    'Ciutkan' => 'Runtuhkan',
    'Isi ulang' => 'Tambah nilai',
    'isi ulang' => 'tambah nilai',
    'Dompet' => 'Dompet',
    'Saham' => 'Saham',
    'Berhasil' => 'Berjaya',
    'berhasil' => 'berjaya',
    'Gagal' => 'Gagal',
    'Memuat' => 'Memuatkan',
    'memuat' => 'memuatkan',
    'Tersedia' => 'Tersedia',
    'Konfirmasi' => 'Sahkan',
    'konfirmasi' => 'sahkan',
    'Opsional' => 'Pilihan',
    'opsional' => 'pilihan',
    'Cabang' => 'Cawangan',
    'Provinsi' => 'Negeri',
    'Kota' => 'Bandar',
    'Gambar' => 'Imej',
    'File' => 'Fail',
    'Pengaturan' => 'Tetapan',
    'pengaturan' => 'tetapan',
    'Permintaan' => 'Permintaan',
    'Diterima' => 'Diterima',
    'Ditolak' => 'Ditolak',
    'Menunggu' => 'Menunggu',
    'Tidak cukup' => 'Tidak mencukupi',
    'tidak cukup' => 'tidak mencukupi',
    'Sekarang' => 'Sekarang',
    'hari ini' => 'hari ini',
    'besok' => 'esok',
    'Baru saja' => 'Baru sahaja',
    'menit lalu' => 'minit lalu',
    'jam lalu' => 'jam lalu',
    'Mengerti' => 'Faham',
    'Selamat datang' => 'Selamat datang',
    'resmi' => 'rasmi',
    'Resmi' => 'Rasmi',
    'keamanan' => 'keselamatan',
    'Keamanan' => 'Keselamatan',
    'verifikasi' => 'pengesahan',
    'Verifikasi' => 'Pengesahan',
    'dikredit' => 'dikreditkan',
    'Kredit' => 'Kredit',
    'Turnover' => 'Pusing ganti',
    'turnover' => 'pusing ganti',
    'Settlement' => 'Penyelesaian',
    'settlement' => 'penyelesaian',
    'Rebate' => 'Rebat',
    'rebate' => 'rebat',
    'Komisi' => 'Komisen',
    'komisi' => 'komisen',
    'Hasil' => 'Pendapatan',
    'hasil' => 'pendapatan',
    'Iklan' => 'Iklan',
    'Aturan' => 'Peraturan',
    'aturan' => 'peraturan',
    'Pemilik' => 'Pemilik',
    'Tendang' => 'Keluarkan',
    'Jadikan' => 'Jadikan',
    'Hapus' => 'Padam',
    'hapus' => 'padam',
    'Simpan' => 'Simpan',
    'Ubah' => 'Ubah',
    'Lainnya' => 'Lain-lain',
    'Kosongkan' => 'Biarkan kosong',
    'diizinkan' => 'dibenarkan',
    'Tidak ada' => 'Tiada',
    'tidak ada' => 'tiada',
    'Belum ada' => 'Belum ada',
    'Belum ' => 'Belum ',
    'Sudah ' => 'Sudah ',
    'Silakan' => 'Sila',
    'silakan' => 'sila',
    'Anda' => 'Anda',
    'bisa' => 'boleh',
    'Bisa' => 'Boleh',
    'tidak bisa' => 'tidak boleh',
    'Tidak bisa' => 'Tidak boleh',
    'gunakan' => 'gunakan',
    'Gunakan' => 'Gunakan',
    'kirim' => 'hantar',
    'Kirim' => 'Hantar',
    'terkirim' => 'dihantar',
    'Terkirim' => 'Dihantar',
    'Coba' => 'Cuba',
    'coba' => 'cuba',
    'lagi' => 'lagi',
    'nanti' => 'nanti',
    'sekarang' => 'sekarang',
    'Akun' => 'Akaun',
    'akun' => 'akaun',
    'kartu' => 'kad',
    'Kartu' => 'Kad',
    'bank' => 'bank',
    'nama panggilan' => 'nama samaran',
    'Nama panggilan' => 'Nama samaran',
    'Avatar' => 'Avatar',
    'Profil' => 'Profil',
    'Aula' => 'Dewan',
    'aula' => 'dewan',
    'kesejahteraan' => 'kebajikan',
    'Kesejahteraan' => 'Kebajikan',
    'hadiah' => 'ganjaran',
    'Hadiah' => 'Ganjaran',
    'Bagikan' => 'Kongsi',
    'bagikan' => 'kongsi',
    'Undang' => 'Jemput',
    'undang' => 'jemput',
    'Peringkat' => 'Kedudukan',
    'peringkat' => 'kedudukan',
    'Master' => 'Ketua',
    // keep some marketing EN loanwords that ID used
];

$ms = [];
foreach ($id as $k => $v) {
    $s = $v;
    // currency first
    $s = str_replace('Rp', 'RM', $s);
    foreach ($msPairs as $from => $to) {
        $s = str_replace($from, $to, $s);
    }
    $ms[$k] = $s;
}

// Curated Malay overrides for critical marketing / UI keys
$msOverride = [
  'brand_name' => 'Hongbao',
  'page_title' => '红宝 Rasmi — Pusat kongsi bonus peminat RM888,888',
  'login_subtitle' => "Hongbao pelbagai mod · Seronok satu tempat\n🔥 Dibuka panas — tuntut RM888,888",
  'login_subtitle_line1' => 'Hongbao pelbagai mod · Seronok satu tempat',
  'login_subtitle_line2' => '🔥 Dibuka panas — tuntut RM888,888',
  'login_submit_btn' => 'Masuk dewan kebajikan rasmi — percuma {register_rights} saham permulaan',
  'uid_label' => '🔑 Langkah 1: Masukkan akaun yang berjaya didaftarkan di rakan kongsi 红宝',
  'uid_placeholder' => 'Cth: 555bio (mesti nombor telefon sama) — jika tidak CS tidak boleh kredit',
  'uid_hint_idle' => 'Isi akaun permainan (nombor atau huruf+nombor). Setiap akaun hanya boleh dihantar sekali',
  'uid_submit_btn' => 'Hantar akaun untuk semakan',
  'uid_submit_pending' => 'Sedang disemak',
  'uid_submit_approved' => 'Disahkan & dikreditkan',
  'uid_hint_pending' => 'Sedang disemak — tunggu CS sahkan dan kredit',
  'uid_hint_approved' => 'Akaun permainan disahkan — akaun dikunci',
  'uid_hint_rejected' => 'Semakan gagal',
  'settle_title_low' => '🏦 Mohon lorong hijau VIP manual pantas',
  'settle_sub_low' => 'Walaupun kurang, hubungi CS eksklusif untuk bantu lengkapkan',
  'settle_title_high' => '🛡️ Saluran bayaran selamat · tuntutan VIP muktamad',
  'settle_sub_high' => 'Ambang dicapai — jana kod muktamad dan hubungi CS eksklusif untuk tuntut',
  'tab_bar_exchange' => 'Tukar pantas',
  'tab_bar_claim' => 'Tuntut',
  'tab_bar_master' => 'Ketua',
  'page_hero_exchange_title' => '⚡ Dewan tukar pantas VIP',
  'page_hero_claim_title' => 'Rakan kongsi 555.bio taja tuntutan VIP khas',
  'page_hero_claim_sub' => 'Isi akaun laman permainan → hantar pengesahan → auto +2 saham',
  'home_quick_exchange' => '⚡ Tukar pantas',
  'home_quick_claim' => '🏦 Tuntut',
  'home_quick_fission' => '🧧 Hongbao fisi',
  'home_quick_fission_sub' => 'Jemput kongsi kumpulan hadiah',
  'profile_menu_info' => 'Avatar & nama samaran',
  'profile_menu_password' => 'Kata laluan log masuk',
  'profile_section_asset' => 'Perkhidmatan aset',
  'profile_section_security' => 'Akaun & keselamatan',
  'profile_quick_recharge' => 'Tambah nilai',
  'profile_quick_withdraw' => 'Keluarkan',
  'profile_menu_payee' => 'Alamat dompet',
  'profile_menu_pay_password' => 'Kata laluan bayaran',
  'fission_home_entry_title' => 'Hongbao fisi seluruh rangkaian',
  'fission_home_entry_sub_active' => 'Kumpulan RM{pool} · {quals}/{cap} slot',
  'fission_home_entry_sub_ended_drawn' => 'Sudah diundi · pintu ditutup',
  'fission_home_entry_sub_ended_void' => 'Belum penuh — dibatalkan · hubungan kekal',
  'fission_home_entry_go' => 'Sertai ›',
  'fission_home_entry_ended' => 'Tamat',
  'profile_payee_bound' => 'Terikat',
  'profile_payee_address_label' => 'Alamat terima',
  'profile_payee_address_ph' => 'Masukkan alamat terima',
  'profile_payee_name_optional' => 'Catatan nama (pilihan)',
  'profile_payee_optional_ph' => 'Pilihan',
  'profile_payee_bind_btn' => 'Sahkan ikat',
  'profile_payee_update_btn' => 'Kemas kini alamat',
  'profile_payee_submitting' => 'Menghantar…',
  'profile_payee_usdt_trc20_only' => 'Hanya USDT-TRC20',
  'profile_payee_addr_with_chain' => 'Alamat {chain}',
  'profile_payee_bound_prefix' => 'Terikat:',
  'loading_generic' => 'Memuatkan...',
  'country_my' => 'Malaysia',
  'lang_ms' => 'Melayu',
  'swap_unit_balance' => 'RM',
  'asset_balance_unit' => 'RM',
];
$ms = array_merge($ms, $msOverride);
write_map("$dir/ms_a.php", $ms);
foreach (['b','c','d'] as $p) {
    file_put_contents("$dir/ms_{$p}.php", "<?php\nreturn [];\n");
}
echo "ms written keys=" . count($ms) . "\n";

// --- Khmer: load from km_seed if present, else fail hint ---
$kmSeed = "$dir/km_seed.php";
if (!is_file($kmSeed)) {
    echo "NEED km_seed.php with full Khmer map\n";
} else {
    $km = include $kmSeed;
    if (!is_array($km)) {
        echo "bad km_seed\n";
    } else {
        // ensure all en keys present
        $merged = array_merge($en, $km);
        write_map("$dir/km_a.php", $merged);
        foreach (['b','c','d'] as $p) {
            file_put_contents("$dir/km_{$p}.php", "<?php\nreturn [];\n");
        }
        echo "km written keys=" . count($merged) . " seed=" . count($km) . "\n";
    }
}

// Emit JSON
passthru('php ' . escapeshellarg("$dir/_emit_json.php"));
