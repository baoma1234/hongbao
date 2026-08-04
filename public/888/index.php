<?php
/**
 * ?? H5 entrance
 */
$assetVer = '202608041020';
$v = htmlspecialchars($assetVer, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>??</title>
    <link rel="icon" href="img/logo.png?v=<?= $v ?>" type="image/png">
    <link rel="apple-touch-icon" href="img/logo.png?v=<?= $v ?>">
    <link rel="stylesheet" href="css/core.css?v=<?= $v ?>">
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>
<?php include __DIR__ . '/partials/login.php'; ?>

    <div class="page-view" id="mainDashboardView" data-shell="pending"></div>
    <div id="appExtrasMount" data-shell="pending"></div>

    <script>window.FANSHUB_ASSETS = { ver: '<?= $v ?>', base: '' };</script>
    <script src="copy.defaults.js?v=<?= $v ?>"></script>
    <script src="i18n/version.js?v=<?= $v ?>"></script>
    <script src="i18n/countries.js?v=<?= $v ?>"></script>
    <script src="i18n/manager.js?v=<?= $v ?>"></script>
    <script src="js/loader.js?v=<?= $v ?>"></script>
    <script src="js/app-core.js?v=<?= $v ?>" defer></script>
    <script src="js/app-boot.js?v=<?= $v ?>" defer></script>
</body>
</html>
