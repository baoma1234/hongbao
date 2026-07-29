<?php
/**
 * Rebuild corrupted modals.php (broken by data-copy-html restore on nested divs)
 */
$root = dirname(__DIR__);
$copy = include $root . '/application/extra/fanshub_h5_copy.php';

function t($copy, $key, $fallback = '')
{
    return isset($copy[$key]) ? $copy[$key] : $fallback;
}

$title = t($copy, 'threshold_modal_title', '还差一步即可解锁领取！');
$desc = t($copy, 'threshold_modal_desc');
$btnOpen = t($copy, 'threshold_modal_btn_open', '💳 立即前往 555.bio 官方主站开户（送 {open_account_rights} 股）');
$btnLater = t($copy, 'threshold_modal_btn_later', '稍后再说，继续攒股份');

$wTitle = t($copy, 'withdraw_title_default', '🔒 官方 VIP 福利派发中心');
$wAmt = t($copy, 'withdraw_amount_label', '当前待领取福利总金额');
$wSecret = t($copy, 'withdraw_secret_label', '🔐 您的专属密令（请点击复制）');
$wLoading = t($copy, 'withdraw_secret_loading', 'FH-LOADING');
$wTimer = t($copy, 'withdraw_secret_timer', '⏳ 专属密令安全锁定剩余：');
$wStep1 = t($copy, 'withdraw_step1', '<span class="step-num">1</span>点击下方按钮，一键复制密令并跳转<strong>红宝专属客服</strong>');
$wStep2 = t($copy, 'withdraw_step2', '<span class="step-num">2</span>将密令发送给在线客服小妹，获取<strong>官方红宝聊天App</strong>下载指引');
$wStep3 = t($copy, 'withdraw_step3', '<span class="step-num">3</span>下载并添加官方 App 后，客服将为您完成主站账号充值（保障资金与账号绝对安全）');
$wCs = t($copy, 'withdraw_btn_cs', '💬 一键复制密令 · 匹配专属客服');
$wApp = t($copy, 'withdraw_btn_app', '📥 下载官方红宝聊天App');
$wClose = t($copy, 'withdraw_btn_close', '返回继续攒股份');

$sTitle = t($copy, 'slider_modal_title', '安全验证');
$sHint = t($copy, 'slider_modal_hint', '请按住滑块，拖动到最右侧');
$sTrack = t($copy, 'slider_track_hint', '拖动滑块到右侧 →');

$html = <<<HTML
    <div class="modal-mask" id="thresholdBlockModal">
        <div class="modal-box">
            <div class="block-icon" aria-hidden="true">🚧</div>
            <div class="modal-title" data-copy="threshold_modal_title">{$title}</div>
            <div class="modal-desc" id="thresholdModalDesc" data-copy-html="1" data-copy="threshold_modal_desc">{$desc}</div>
            <button type="button" class="modal-action-btn success" id="thresholdOpenBtn" onclick="goToMainStationFromBlock()" data-copy="threshold_modal_btn_open">{$btnOpen}</button>
            <button type="button" class="modal-action-btn secondary" onclick="closeThresholdBlockModal()" data-copy="threshold_modal_btn_later">{$btnLater}</button>
        </div>
    </div>

    <div class="modal-mask" id="withdrawModal">
        <div class="modal-box">
            <div class="modal-title" id="withdrawModalTitle" data-copy="withdraw_title_default">{$wTitle}</div>
            <div style="font-size:12px; color:var(--text-muted);" data-copy="withdraw_amount_label">{$wAmt}</div>
            <div class="modal-money" id="modalBalanceWrap">￥0.00</div>

            <div style="font-size:12px; color:var(--accent); font-weight:bold; margin-bottom:6px;" data-copy="withdraw_secret_label">{$wSecret}</div>
            <div class="secret-code-box" id="billCodeText" onclick="copySecretCode()" data-copy="withdraw_secret_loading">{$wLoading}</div>
            <div class="secret-timer"><span data-copy="withdraw_secret_timer">{$wTimer}</span><span id="secretCountdown">15:00</span></div>

            <div class="cs-step-card">
                <p data-copy-html="1" data-copy="withdraw_step1">{$wStep1}</p>
                <p data-copy-html="1" data-copy="withdraw_step2">{$wStep2}</p>
                <p data-copy-html="1" data-copy="withdraw_step3">{$wStep3}</p>
            </div>

            <button type="button" class="modal-action-btn primary" id="btnJumpCS" onclick="jumpToCustomerService()" data-copy="withdraw_btn_cs">{$wCs}</button>
            <button type="button" class="modal-action-btn gold" id="withdrawAppBtn" onclick="copyText(CONFIG.APP_DOWNLOAD_URL, fc('withdraw_app_copy_ok'))" data-copy="withdraw_btn_app">{$wApp}</button>
            <button type="button" class="modal-close-btn" onclick="closeWithdrawModal()" data-copy="withdraw_btn_close">{$wClose}</button>
        </div>
    </div>

    <div class="slider-modal" id="sliderCaptchaModal" onclick="if(event.target===this)closeSliderCaptcha()">
        <div class="slider-box" onclick="event.stopPropagation()">
            <div class="slider-box-hd">
                <div class="slider-box-title" data-copy="slider_modal_title">{$sTitle}</div>
            </div>
            <div class="slider-box-hint" id="sliderModalHint" data-copy="slider_modal_hint">{$sHint}</div>
            <div class="slider-track" id="sliderTrack">
                <div class="slider-track-fill" id="sliderTrackFill"></div>
                <div class="slider-track-hint" id="sliderTrackHint" data-copy="slider_track_hint">{$sTrack}</div>
                <div class="slider-thumb" id="sliderThumb">›</div>
            </div>
        </div>
    </div>

HTML;

file_put_contents($root . '/public/888/partials/modals.php', $html);
echo "modals_rebuilt bytes=" . strlen($html) . "\n";

// Validate structure: thresholdOpenBtn must be inside modal-box inside modal-mask
$out = file_get_contents($root . '/public/888/partials/modals.php');
if (preg_match('/id="thresholdBlockModal"[\s\S]*?id="thresholdOpenBtn"[\s\S]*?<\/div>\s*<\/div>/', $out)) {
    echo "structure_ok=1\n";
} else {
    echo "structure_ok=0\n";
}
// Ensure no orphan button before mask close issues - count modal-mask / modal-box
echo 'mask=' . substr_count($out, 'class="modal-mask"') . ' box=' . substr_count($out, 'class="modal-box"') . "\n";
echo 'success_btn=' . substr_count($out, 'modal-action-btn success') . "\n";

// Bump asset ver
$idx = $root . '/public/888/index.php';
$s = file_get_contents($idx);
$s = preg_replace('/\$assetVer\s*=\s*\'[^\']+\'/', "\$assetVer = '202607251550'", $s, 1);
file_put_contents($idx, $s);
echo "assetVer=202607251550\n";
