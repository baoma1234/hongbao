<?php
/**
 * ???? H5 ????????????
 *
 * ?? UI???? partials/ ???????
 * ???????css/*.css
 * ???????js/app-core.js + js/app-boot.js
 */
$assetVer = '202607312340';
$v = htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>???? - 888,888???????????????????????</title>
    <link rel="icon" href="img/logo.png?v=<?= $v ?>" type="image/png">
    <link rel="apple-touch-icon" href="img/logo.png?v=<?= $v ?>">
    <link rel="stylesheet" href="css/core.css?v=<?= $v ?>">
    <link rel="stylesheet" href="css/home.css?v=<?= $v ?>">
    <link rel="stylesheet" href="css/profile.css?v=<?= $v ?>" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="css/profile-glass.css?v=<?= $v ?>" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="css/tabs-extra.css?v=<?= $v ?>" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="css/share-swap.css?v=<?= $v ?>" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="css/qr-friend.css?v=<?= $v ?>" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="css/social-modals.css?v=<?= $v ?>" media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="css/profile.css?v=<?= $v ?>">
        <link rel="stylesheet" href="css/profile-glass.css?v=<?= $v ?>">
        <link rel="stylesheet" href="css/tabs-extra.css?v=<?= $v ?>">
        <link rel="stylesheet" href="css/share-swap.css?v=<?= $v ?>">
        <link rel="stylesheet" href="css/qr-friend.css?v=<?= $v ?>">
        <link rel="stylesheet" href="css/social-modals.css?v=<?= $v ?>">
    </noscript>
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>
<?php include __DIR__ . '/partials/login.php'; ?>

    <div class="page-view" id="mainDashboardView">
<?php include __DIR__ . '/partials/tab-home.php'; ?>
<?php include __DIR__ . '/partials/tab-exchange.php'; ?>
<?php include __DIR__ . '/partials/tab-master.php'; ?>
<?php include __DIR__ . '/partials/tab-messages.php'; ?>
<?php include __DIR__ . '/partials/tab-profile.php'; ?>
    </div>

<?php include __DIR__ . '/partials/profile-subpages.php'; ?>
<?php include __DIR__ . '/partials/modals.php'; ?>
<?php include __DIR__ . '/partials/bottom-and-overlays.php'; ?>

    <script>window.FANSHUB_ASSETS = { ver: '<?= $v ?>', base: '' };</script>
    <script src="i18n/version.js?v=<?= $v ?>"></script>
    <script src="i18n/countries.js?v=<?= $v ?>"></script>
    <script src="i18n/manager.js?v=<?= $v ?>"></script>
    <script src="js/loader.js?v=<?= $v ?>"></script>
    <script src="js/qr-friend.js?v=<?= $v ?>" defer></script>
    <script src="js/app-core.js?v=<?= $v ?>" defer></script>
    <script src="js/app-boot.js?v=<?= $v ?>" defer></script>
</body>
</html>
