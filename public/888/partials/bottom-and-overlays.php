    <div class="bottom-action-bar" id="bottomActionBar">
        <button type="button" class="tab-btn active" data-tab="home" onclick="switchTab('home')"><span class="tab-ico tab-ico-img"><img src="img/tab/home.png?v=202607281935" alt="" width="28" height="28" decoding="async"></span><span data-copy="tab_bar_home">大厅</span></button>
        <button type="button" class="tab-btn" data-tab="exchange" onclick="switchTab('exchange')"><span class="tab-ico tab-ico-img"><img src="img/tab/exchange.png?v=202607281935" alt="" width="28" height="28" decoding="async"></span><span data-copy="tab_bar_exchange">闪兑</span></button>
        <button type="button" class="tab-btn" data-tab="messages" onclick="switchTab('messages')"><span class="tab-ico tab-ico-img"><img src="img/logo.png?v=202607281931" alt="" width="28" height="28" decoding="async"></span><span data-copy="tab_bar_messages">红宝</span><span class="chat-tab-badge" id="chatTabBadge">0</span></button>
        <button type="button" class="tab-btn tab-master" data-tab="master" onclick="switchTab('master')"><span class="tab-ico tab-ico-img"><img src="img/tab/master.png?v=202607281935" alt="" width="28" height="28" decoding="async"></span><span data-copy="tab_bar_master">团长</span></button>
        <button type="button" class="tab-btn" data-tab="profile" onclick="switchTab('profile')"><span class="tab-ico tab-ico-img"><img src="img/tab/profile.png?v=202607281935" alt="" width="28" height="28" decoding="async"></span><span data-copy="tab_bar_profile">我的</span></button>
    </div>
    <div id="fanshubToast" class="fanshub-toast" aria-live="polite"></div>
            <div class="welcome-lottery-mask" id="welcomeLotteryMask" data-chest-style="A">
        <!-- 瀹濈椋庢牸锛欰 路 浜噾榛戠锛?textbox/ 閫夊瀷钀藉湴锛?-->
        <div class="welcome-lottery-aura" aria-hidden="true"></div>
        <div class="welcome-lottery-panel">
            <div class="welcome-lottery-shine" aria-hidden="true"></div>
            <div class="welcome-lottery-eyebrow" data-copy="lottery_eyebrow">红宝 · VIP 入厅礼</div>
            <div class="welcome-lottery-title" data-copy="lottery_title">黑金新手宝箱</div>
            <div class="welcome-lottery-subtitle" data-copy="lottery_subtitle">轻触开启 · 锁定入场股份</div>
            <div class="welcome-lottery-chest-wrap">
                <div class="wl-ring wl-ring-a" aria-hidden="true"></div>
                <div class="wl-ring wl-ring-b" aria-hidden="true"></div>
                <div class="welcome-lottery-chest" id="welcomeLotteryChest" role="button" tabindex="0" data-copy-aria="lottery_aria_chest" aria-label="开启宝箱">
                    <div class="wl-chest-body">
                        <div class="wl-chest-lid"></div>
                        <div class="wl-chest-lock">鈼</div>
                        <div class="wl-chest-glow"></div>
                    </div>
                    <div class="wl-sparkles" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
                </div>
                <div class="wl-burst" id="welcomeLotteryBurst" aria-hidden="true"></div>
            </div>
            <div class="welcome-lottery-shares" id="welcomeLotteryShares" data-copy="lottery_shares_locked">锁定 {shares} 份</div>
            <div class="welcome-lottery-price" id="welcomeLotteryPrice">￥-</div>
            <div class="welcome-lottery-hint pulse" id="welcomeLotteryHint" data-copy="lottery_chest_hint">点击黑金宝箱 · 开启新手股份</div>
            <button type="button" class="welcome-lottery-close-btn" id="welcomeLotteryCloseBtn" data-copy="lottery_close_wait" disabled>请先开启宝箱</button>
        </div>
    </div>
    <div class="phase2-modal-mask" id="phase2ModalMask">
        <div class="phase2-modal-box">
            <div class="phase2-modal-title" id="phase2ModalTitle"></div>
            <div class="phase2-modal-body" id="phase2ModalBody"></div>
            <div class="phase2-modal-actions" id="phase2ModalActions"></div>
        </div>
    </div>
