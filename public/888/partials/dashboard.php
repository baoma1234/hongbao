<?php
/**
 * 登录后才下发的大厅 DOM（防未登录泄露业务页结构）
 * 由 FansHubAssets.ensureDashboard() 拉取注入
 */
?>
<div id="dashTabsFragment">
<?php include __DIR__ . '/tab-home.php'; ?>
<?php include __DIR__ . '/tab-exchange.php'; ?>
<?php include __DIR__ . '/tab-master.php'; ?>
<?php include __DIR__ . '/tab-messages.php'; ?>
<?php include __DIR__ . '/tab-profile.php'; ?>
</div>
<div id="dashExtrasFragment">
<?php include __DIR__ . '/profile-subpages.php'; ?>
<?php include __DIR__ . '/modals.php'; ?>
<?php include __DIR__ . '/bottom-and-overlays.php'; ?>
</div>
