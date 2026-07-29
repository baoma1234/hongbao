<?php
/**
 * Split public/888/index.html into maintainable partials + CSS + JS.
 * Run once: php scripts/split_888_index.php
 */
$src = dirname(__DIR__) . '/public/888/index.html';
$base = dirname(__DIR__) . '/public/888';
$partials = $base . '/partials';

if (!is_file($src)) {
    fwrite(STDERR, "index.html missing\n");
    exit(1);
}

$lines = file($src);
$n = count($lines);
echo "source lines=$n\n";

function slice_join(array $lines, int $from1, int $to1): string {
    return implode('', array_slice($lines, $from1 - 1, $to1 - $from1 + 1));
}

function write_file(string $path, string $content): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    // Normalize to UTF-8 without BOM
    if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
        $content = substr($content, 3);
    }
    file_put_contents($path, $content);
    echo 'wrote ' . str_replace('\\', '/', $path) . ' (' . strlen($content) . " bytes)\n";
}

if (!is_dir($partials)) {
    mkdir($partials, 0755, true);
}

// Backup monolith once
$bak = $base . '/index.html.monolith.bak';
if (!is_file($bak)) {
    copy($src, $bak);
    echo "backup => index.html.monolith.bak\n";
}

// --- styles.css : content of <style> block (lines 8..1710 approx) ---
$styleStart = null;
$styleEnd = null;
foreach ($lines as $i => $line) {
    if ($styleStart === null && preg_match('/^\s*<style>\s*$/', $line)) {
        $styleStart = $i + 1; // first CSS line after <style>
        continue;
    }
    if ($styleStart !== null && $styleEnd === null && preg_match('/^\s*<\/style>\s*$/', $line)) {
        $styleEnd = $i; // line before </style> in 1-based = $i
        break;
    }
}
if (!$styleStart || !$styleEnd) {
    fwrite(STDERR, "style block not found\n");
    exit(1);
}
$css = slice_join($lines, $styleStart + 1, $styleEnd); // styleStart is <style> line number
// fix: $styleStart is 1-based line of <style>, CSS is styleStart+1 .. styleEnd
$css = slice_join($lines, $styleStart + 1, $styleEnd);
write_file($base . '/styles.css', $css);

// --- app.js : largest inline <script> without src ---
$scriptOpen = null;
$scriptClose = null;
for ($i = 0; $i < $n; $i++) {
    if (preg_match('/^\s*<script>\s*$/', $lines[$i]) || preg_match('/^<script>\s*$/', $lines[$i])) {
        // prefer the large one after chat.js
        $scriptOpen = $i + 1;
    }
    if ($scriptOpen !== null && preg_match('/^\s*<\/script>\s*$/', $lines[$i]) && $i + 1 > $scriptOpen + 100) {
        $scriptClose = $i + 1;
        // keep last large pair
    }
}
if (!$scriptOpen || !$scriptClose) {
    fwrite(STDERR, "inline script not found\n");
    exit(1);
}
// Find the actual large script more carefully
$bestOpen = null;
$bestClose = null;
$bestLen = 0;
$openAt = null;
for ($i = 0; $i < $n; $i++) {
    if (preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $lines[$i]) && !preg_match('/\bsrc=/i', $lines[$i])) {
        $openAt = $i;
    } elseif ($openAt !== null && preg_match('/<\/script>/i', $lines[$i])) {
        $len = $i - $openAt;
        if ($len > $bestLen) {
            $bestLen = $len;
            $bestOpen = $openAt;
            $bestClose = $i;
        }
        $openAt = null;
    }
}
if ($bestOpen === null) {
    fwrite(STDERR, "large script not found\n");
    exit(1);
}
$js = slice_join($lines, $bestOpen + 2, $bestClose); // after <script> line through before </script>
// If <script> line is ONLY <script>, content starts bestOpen+2 in 1-based = bestOpen+1 (0-based+1)
$js = implode('', array_slice($lines, $bestOpen + 1, $bestClose - $bestOpen - 1));
write_file($base . '/app.js', $js);

// Helper: find line (1-based) containing exact-ish marker
function find_line(array $lines, string $needle, int $start1 = 1): ?int {
    for ($i = $start1 - 1; $i < count($lines); $i++) {
        if (str_contains($lines[$i], $needle)) {
            return $i + 1;
        }
    }
    return null;
}

$headerStart = find_line($lines, 'id="floatingTopBar"');
$loginStart = find_line($lines, 'id="loginView"');
$dashStart = find_line($lines, 'id="mainDashboardView"');
$homeStart = find_line($lines, 'id="tabHome"');
$exStart = find_line($lines, 'id="tabExchange"');
$claimStart = find_line($lines, 'id="tabClaim"');
$masterStart = find_line($lines, 'id="tabMaster"');
$msgStart = find_line($lines, 'id="tabMessages"');
$profStart = find_line($lines, 'id="tabProfile"');
$infoPane = find_line($lines, 'id="profileInfoPane"');
$pwdPane = find_line($lines, 'id="profilePasswordPane"');
// wallet panes may exist
$rechargePane = find_line($lines, 'id="profileRechargePane"');
$withdrawPane = find_line($lines, 'id="profileWithdrawPane"');
$thresholdModal = find_line($lines, 'id="thresholdBlockModal"') ?: find_line($lines, 'thresholdModalDesc');
// find threshold modal container
$thresholdMask = find_line($lines, 'threshold');
$withdrawModal = find_line($lines, 'id="withdrawModal"');
$sliderModal = find_line($lines, 'id="sliderCaptchaModal"');
$bottomBar = find_line($lines, 'id="bottomActionBar"');
$lottery = find_line($lines, 'id="welcomeLotteryMask"');
$phase2 = find_line($lines, 'id="phase2ModalMask"');

echo "markers: header=$headerStart login=$loginStart home=$homeStart ex=$exStart claim=$claimStart master=$masterStart msg=$msgStart prof=$profStart bottom=$bottomBar\n";

// Find end of tabProfile: line before profileInfoPane comment/pane, and before closing mainDashboard if any
// From earlier read: tabProfile ends ~2233, then profile sub panes, then modals

// Find line with threshold modal root
$modalsStart = null;
for ($i = ($pwdPane ?: $infoPane ?: $profStart); $i < $n; $i++) {
    if (str_contains($lines[$i], 'modal-mask') && (str_contains($lines[$i], 'threshold') || str_contains($lines[$i], 'Threshold') || str_contains($lines[$i], 'id="threshold'))) {
        $modalsStart = $i + 1;
        break;
    }
}
if (!$modalsStart) {
    // fallback: first .modal-mask after profile
    for ($i = ($infoPane ?: 2230) - 1; $i < $n; $i++) {
        if (preg_match('/class="modal-mask"/', $lines[$i])) {
            $modalsStart = $i + 1;
            break;
        }
    }
}

$scriptsStart = find_line($lines, 'i18n/version.js');

// Determine profile tab end = line before infoPane (or recharge pane)
$profEnd = ($rechargePane ?: $infoPane) - 1;
// walk back to skip blank/comment
while ($profEnd > $profStart && trim($lines[$profEnd - 1]) === '') {
    $profEnd--;
}

// profile extras: from infoPane (or recharge) until modalsStart-1
$profileExtraStart = $rechargePane ?: $infoPane;
$profileExtraEnd = $modalsStart - 1;

// dashboard wrapper: we include tabs individually inside index.php, so each tab file is self-contained div.tab-page

// header: floatingTopBar block until before loginView
$headerEnd = $loginStart - 1;
$loginEnd = $dashStart - 1;

// home: from home comment/start to before exchange
$homeEnd = $exStart - 1;
$exEnd = $claimStart - 1;
$claimEnd = $masterStart - 1;
$masterEnd = $msgStart - 1;
$msgEnd = $profStart - 1;

// Trim leading comments that belong to previous section: keep comments with each section
write_file($partials . '/header.php', slice_join($lines, $headerStart, $headerEnd));
write_file($partials . '/login.php', slice_join($lines, $loginStart, $loginEnd));

// For tabs, skip the opening <div class="page-view" id="mainDashboardView"> — index.php will wrap
// homeStart might be on tabHome; include preceding comment on previous line if any
$homeFileStart = $homeStart;
if ($homeStart > 1 && str_contains($lines[$homeStart - 2], '<!--')) {
    $homeFileStart = $homeStart - 1;
}
write_file($partials . '/tab-home.php', slice_join($lines, $homeFileStart, $homeEnd));

$exFileStart = $exStart;
if ($exStart > 1 && str_contains($lines[$exStart - 2], '<!--')) $exFileStart = $exStart - 1;
write_file($partials . '/tab-exchange.php', slice_join($lines, $exFileStart, $exEnd));

$claimFileStart = $claimStart;
if ($claimStart > 1 && str_contains($lines[$claimStart - 2], '<!--')) $claimFileStart = $claimStart - 1;
write_file($partials . '/tab-claim.php', slice_join($lines, $claimFileStart, $claimEnd));

$masterFileStart = $masterStart;
if ($masterStart > 1 && str_contains($lines[$masterStart - 2], '<!--')) $masterFileStart = $masterStart - 1;
write_file($partials . '/tab-master.php', slice_join($lines, $masterFileStart, $masterEnd));

$msgFileStart = $msgStart;
if ($msgStart > 1 && str_contains($lines[$msgStart - 2], '<!--')) $msgFileStart = $msgStart - 1;
write_file($partials . '/tab-messages.php', slice_join($lines, $msgFileStart, $msgEnd));

$profFileStart = $profStart;
if ($profStart > 1 && str_contains($lines[$profStart - 2], '<!--')) $profFileStart = $profStart - 1;
$profHtml = slice_join($lines, $profFileStart, $profEnd);
// Fix known broken tags from encoding corruption
$profHtml = str_replace(
    '<strong> id="profileUserId">-</strong>',
    '<strong id="profileUserId">-</strong>',
    $profHtml
);
$profHtml = str_replace(
    '<strong> id="profileMobileMask">-</strong>',
    '<strong id="profileMobileMask">-</strong>',
    $profHtml
);
write_file($partials . '/tab-profile.php', $profHtml);

if ($profileExtraStart && $profileExtraEnd >= $profileExtraStart) {
    write_file($partials . '/profile-subpages.php', slice_join($lines, $profileExtraStart, $profileExtraEnd));
} else {
    write_file($partials . '/profile-subpages.php', "<!-- no profile subpages -->\n");
}

// modals: from modalsStart to before i18n scripts
$modalsEnd = $scriptsStart - 1;
write_file($partials . '/modals.php', slice_join($lines, $modalsStart, $modalsEnd));

// bottom + lottery + phase2: after </script> of app
$bottomEnd = $n;
// exclude closing body/html if present in slice — keep them in index.php
$tail = slice_join($lines, $bottomBar, $n);
$tail = preg_replace('/\s*<\/body>\s*<\/html>\s*$/i', "\n", $tail);
write_file($partials . '/bottom-and-overlays.php', $tail);

// Build index.php
$v = date('YmdHi');
$indexPhp = <<<PHP
<?php
/**
 * 555.bio H5 入口（分片组装）
 * 编辑各模块请改 partials/、styles.css、app.js
 */
\$assetVer = '{$v}';
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>555.bio官方直营 - 888,888元全网粉丝活跃彩金分享瓜分中心</title>
    <link rel="stylesheet" href="styles.css?v=<?= htmlspecialchars(\$assetVer, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="chat.css?v=202607211400">
</head>
<body>
<?php include __DIR__ . '/partials/header.php'; ?>
<?php include __DIR__ . '/partials/login.php'; ?>

    <div class="page-view" id="mainDashboardView">
<?php include __DIR__ . '/partials/tab-home.php'; ?>
<?php include __DIR__ . '/partials/tab-exchange.php'; ?>
<?php include __DIR__ . '/partials/tab-claim.php'; ?>
<?php include __DIR__ . '/partials/tab-master.php'; ?>
<?php include __DIR__ . '/partials/tab-messages.php'; ?>
<?php include __DIR__ . '/partials/tab-profile.php'; ?>
    </div>

<?php include __DIR__ . '/partials/profile-subpages.php'; ?>
<?php include __DIR__ . '/partials/modals.php'; ?>

    <script src="i18n/version.js"></script>
    <script src="i18n/countries.js"></script>
    <script src="i18n/manager.js"></script>
    <script src="chat.js?v=202607212000"></script>
    <script src="profile-wallet.js?v=202607212000"></script>
    <script src="copy.defaults.js"></script>
    <script src="app.js?v=<?= htmlspecialchars(\$assetVer, ENT_QUOTES, 'UTF-8') ?>"></script>

<?php include __DIR__ . '/partials/bottom-and-overlays.php'; ?>
</body>
</html>

PHP;
write_file($base . '/index.php', $indexPhp);

// Replace index.html with redirect so /888/ and /888/index.html both work
$redirect = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="0;url=index.php">
    <script>location.replace('index.php' + location.search + location.hash);</script>
    <title>Redirecting…</title>
</head>
<body>
    <p><a href="index.php">进入福利大厅</a></p>
</body>
</html>
HTML;
write_file($base . '/index.html', $redirect);

echo "DONE\n";
