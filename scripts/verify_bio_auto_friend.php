<?php
/**
 * 校验 BIO 客服自动通过（不依赖 DB）
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

$uid = 55555555;
$ok = AdminService::autoAcceptsFriend($uid);
echo 'autoAcceptsFriend(55555555)=' . ($ok ? 'YES' : 'NO') . "\n";
echo 'autoAcceptFriendUserIds=' . json_encode(AdminService::autoAcceptFriendUserIds()) . "\n";
if (!$ok) {
    fwrite(STDERR, "FAIL: BIO should auto-accept\n");
    exit(1);
}
echo "OK\n";
