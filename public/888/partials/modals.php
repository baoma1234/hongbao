    <div class="modal-mask" id="thresholdBlockModal">
        <div class="modal-box">
            <div class="block-icon" aria-hidden="true">🚧</div>
            <div class="modal-title" data-copy="threshold_modal_title">还差一步即可解锁领取！</div>
            <div class="modal-desc" id="thresholdModalDesc" data-copy-html="1" data-copy="threshold_modal_desc">您的闪兑余额为 <strong style="color:var(--secondary);">{currency}<span id="blockCurrentAmt">0.00</span></strong>，距官方福利起领门槛 <strong>{currency}{threshold}</strong> 还差 <strong style="color:var(--danger);">{currency}<span id="blockShortAmt">0.00</span></strong>。<div class="progress-bar-wrap"><div class="progress-bar-fill" id="blockProgressBar" style="width:0%"></div></div>为保障您的专属配额不被释放，请尽快完成主站开户或邀请好友占位凑数！</div>
            <button type="button" class="modal-action-btn success" id="thresholdOpenBtn" onclick="goToMainStationFromBlock()" data-copy="threshold_modal_btn_open">💳 立即前往 红宝 官方主站开户（送 {open_account_rights} 股）</button>
            <button type="button" class="modal-action-btn secondary" onclick="closeThresholdBlockModal()" data-copy="threshold_modal_btn_later">稍后再说，继续攒股份</button>
        </div>
    </div>

    <div class="modal-mask" id="withdrawModal">
        <div class="modal-box">
            <div class="modal-title" id="withdrawModalTitle" data-copy="withdraw_title_default">🔒 官方 VIP 福利派发中心</div>
            <div style="font-size:12px; color:var(--text-muted);" data-copy="withdraw_amount_label">当前待领取福利总金额</div>
            <div class="modal-money" id="modalBalanceWrap">￥0.00</div>

            <div style="font-size:12px; color:var(--accent); font-weight:bold; margin-bottom:6px;" data-copy="withdraw_secret_label">🔐 您的专属密令（请点击复制）</div>
            <div class="secret-code-box" id="billCodeText" onclick="copySecretCode()" data-copy="withdraw_secret_loading">FH-LOADING</div>
            <div class="secret-timer"><span data-copy="withdraw_secret_timer">⏳ 专属密令安全锁定剩余：</span><span id="secretCountdown">15:00</span></div>

            <div class="cs-step-card">
                <p data-copy-html="1" data-copy="withdraw_step1"><span class="step-num">1</span>点击下方按钮，一键复制密令并跳转<strong>红宝专属客服</strong></p>
                <p data-copy-html="1" data-copy="withdraw_step2"><span class="step-num">2</span>将密令发送给在线客服小妹，获取<strong>官方红宝聊天App</strong>下载指引</p>
                <p data-copy-html="1" data-copy="withdraw_step3"><span class="step-num">3</span>下载并添加官方 App 后，客服将为您完成主站账号充值（保障资金与账号绝对安全）</p>
            </div>

            <button type="button" class="modal-action-btn primary" id="btnJumpCS" onclick="jumpToCustomerService()" data-copy="withdraw_btn_cs">💬 一键复制密令 · 匹配专属客服</button>
            <button type="button" class="modal-action-btn gold" id="withdrawAppBtn" onclick="copyText(CONFIG.APP_DOWNLOAD_URL, fc('withdraw_app_copy_ok'))" data-copy="withdraw_btn_app">📥 下载官方红宝聊天App</button>
            <button type="button" class="modal-close-btn" onclick="closeWithdrawModal()" data-copy="withdraw_btn_close">返回继续攒股份</button>
        </div>
    </div>

    <div class="slider-modal" id="sliderCaptchaModal" onclick="if(event.target===this)closeSliderCaptcha()">
        <div class="slider-box" onclick="event.stopPropagation()">
            <div class="slider-box-hd">
                <div class="slider-box-title" data-copy="slider_modal_title">安全验证</div>
                <button type="button" class="slider-box-refresh" id="sliderRefreshBtn" data-copy="slider_refresh_btn">重试</button>
            </div>
            <div class="slider-box-hint" id="sliderModalHint" data-copy="slider_modal_hint">请按住滑块，拖动到最右侧</div>
            <div class="slider-track" id="sliderTrack">
                <div class="slider-track-fill" id="sliderTrackFill"></div>
                <div class="slider-track-hint" id="sliderTrackHint" data-copy="slider_track_hint">拖动滑块到右侧 →</div>
                <div class="slider-thumb" id="sliderThumb" role="slider" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">›</div>
            </div>
            <div class="slider-status" id="sliderStatusText" aria-live="polite"></div>
        </div>
    </div>
