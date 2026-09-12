<?php
/**
 * 校验 BIO / 40ky 客服自动通过（不依赖 DB）
 * php scripts/verify_bio_auto_friend.php
 */
$root = dirname(__DIR__);
spl_autoload_register(function ($class) use ($root) {
    if (strpos($class, 'Im\\') !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, 3));
    $file = $root . '/im-server/App/' . $rel . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Im\Service\AdminService;

$ids = [55555555, 44444444];
$fail = false;
foreach ($ids as $uid) {
    $ok = AdminService::autoAcceptsFriend($uid);
    echo "autoAcceptsFriend({$uid})=" . ($ok ? 'YES' : 'NO') . "\n";
    if (!$ok) {
        $fail = true;
    }
}
echo 'autoAcceptFriendUserIds=' . json_encode(AdminService::autoAcceptFriendUserIds()) . "\n";
if ($fail) {
    fwrite(STDERR, "FAIL\n");
    exit(1);
}
echo "OK\n";
