        <!-- 6. 个人中心 -->
        <div class="tab-page" id="tabProfile" data-tab="profile">
            <section class="profile-vip-hero" aria-label="会员资料" data-copy-aria="aria_profile_vip">
                <div class="profile-vip-hero-shine" aria-hidden="true"></div>
                <div class="profile-vip-watermark" aria-hidden="true" data-copy="brand_name">红宝</div>
                <div class="profile-vip-identity">
                    <div class="profile-avatar-wrap profile-avatar-readonly profile-vip-avatar">
                        <img id="profileAvatarImg" class="profile-avatar-img" src="" alt="" style="display:none;">
                        <div id="profileAvatarFallback" class="profile-avatar-fallback">?</div>
                    </div>
                    <div class="profile-summary-text profile-vip-text">
                        <div class="profile-vip-name-row">
                            <div class="profile-summary-name" id="profileDisplayName">-</div>
                            <span class="profile-vip-badge" data-copy="profile_vip_badge">官方会员</span>
                        </div>
                        <div class="profile-meta-line profile-meta-uid">
                            <span data-copy="profile_user_id_label">会员ID</span>
                            <strong id="profileUserId">-</strong>
                            <button type="button" class="profile-copy-uid-btn" id="profileCopyUidBtn" data-copy="profile_uid_copy_btn">复制</button>
                        </div>
                        <div class="profile-meta-line profile-vip-mobile">
                            <span data-copy="profile_mobile_label">绑定手机</span>
                            <strong id="profileMobileMask">-</strong>
                        </div>
                    </div>
                </div>
            </section>

            <nav class="profile-quick-sheet" aria-label="常用功能" data-copy-aria="aria_profile_quick">
                <button type="button" class="profile-quick-item" id="profileMyQrBtn">
                    <span class="profile-quick-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M3 3h8v8H3V3zm2 2v4h4V5H5zm8-2h8v8h-8V3zm2 2v4h4V5h-4zM3 13h8v8H3v-8zm2 2v4h4v-4H5zm13-2h-3v2h2v2h-2v2h3v-2h2v-4h-2v-2zm-3 6h2v2h-2v-2z"/></svg>
                    </span>
                    <span class="profile-quick-label" data-copy="profile_quick_qr">二维码</span>
                </button>
                <button type="button" class="profile-quick-item" id="profileScanQrBtn">
                    <span class="profile-quick-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M4 4h4v2H6v2H4V4zm12 0h4v4h-2V6h-2V4zM4 16h2v2h2v2H4v-4zm14 2h-2v2h4v-4h-2v2zM8 8h8v8H8V8zm2 2v4h4v-4h-4z"/></svg>
                    </span>
                    <span class="profile-quick-label" data-copy="profile_quick_scan">扫一扫</span>
                </button>
                <button type="button" class="profile-quick-item" onclick="openProfileSubPage('recharge')">
                    <span class="profile-quick-ico profile-quick-ico-gold" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 5v4h3l-4 6v-4H9l4-6z"/></svg>
                    </span>
                    <span class="profile-quick-label" data-copy="profile_quick_recharge">充值</span>
                </button>
                <button type="button" class="profile-quick-item" onclick="openProfileSubPage('withdraw')">
                    <span class="profile-quick-ico profile-quick-ico-gold" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M5 4h14a1 1 0 011 1v3H4V5a1 1 0 011-1zm-1 6h16v9a1 1 0 01-1 1H5a1 1 0 01-1-1v-9zm4 3v2h8v-2H8z"/></svg>
                    </span>
                    <span class="profile-quick-label" data-copy="profile_quick_withdraw">提现</span>
                </button>
            </nav>

            <section class="profile-section">
                <h3 class="profile-section-label" data-copy="profile_section_asset">资产服务</h3>
                <div class="profile-menu-sheet">
                    <button type="button" class="profile-menu-row" onclick="openProfileSubPage('payee')">
                        <span class="profile-menu-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M3 6h18v3H3V6zm0 5h18v7a2 2 0 01-2 2H5a2 2 0 01-2-2v-7zm3 2v2h4v-2H6z"/></svg>
                        </span>
                        <span class="profile-menu-main">
                            <strong data-copy="profile_menu_payee">钱包地址</strong>
                            <small data-copy="profile_menu_payee_sub">绑定银行卡、支付宝、微信与数字钱包</small>
                        </span>
                        <span class="profile-menu-arrow">›</span>
                    </button>
                    <button type="button" class="profile-menu-row" onclick="openProfileSubPage('ledger')">
                        <span class="profile-menu-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2zm1 4v2h8V7H8zm0 4v2h8v-2H8zm0 4v2h5v-2H8z"/></svg>
                        </span>
                        <span class="profile-menu-main">
                            <strong data-copy="profile_menu_ledger">资金流水</strong>
                            <small data-copy="profile_menu_ledger_sub">红宝与股份变动明细</small>
                        </span>
                        <span class="profile-menu-arrow">›</span>
                    </button>
                </div>
            </section>

            <section class="profile-section">
                <h3 class="profile-section-label" data-copy="profile_section_security">账号与安全</h3>
                <div class="profile-menu-sheet">
                    <button type="button" class="profile-menu-row" onclick="openProfileSubPage('info')">
                        <span class="profile-menu-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 12a4.5 4.5 0 100-9 4.5 4.5 0 000 9zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5z"/></svg>
                        </span>
                        <span class="profile-menu-main">
                            <strong data-copy="profile_menu_info">头像与昵称</strong>
                            <small data-copy="profile_menu_info_sub">修改头像、昵称</small>
                        </span>
                        <span class="profile-menu-arrow">›</span>
                    </button>
                    <button type="button" class="profile-menu-row" onclick="openProfileSubPage('password')">
                        <span class="profile-menu-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 2a5 5 0 015 5v2h1a2 2 0 012 2v9a2 2 0 01-2 2H6a2 2 0 01-2-2v-9a2 2 0 012-2h1V7a5 5 0 015-5zm0 2a3 3 0 00-3 3v2h6V7a3 3 0 00-3-3zm0 9a1.75 1.75 0 100 3.5A1.75 1.75 0 0012 13z"/></svg>
                        </span>
                        <span class="profile-menu-main">
                            <strong data-copy="profile_menu_password">修改密码</strong>
                            <small data-copy="profile_menu_password_sub">旧密码或短信验证</small>
                        </span>
                        <span class="profile-menu-arrow">›</span>
                    </button>
                    <button type="button" class="profile-menu-row" onclick="openProfileSubPage('paypassword')">
                        <span class="profile-menu-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 1a5 5 0 015 5v2h1.5A1.5 1.5 0 0120 9.5v11A1.5 1.5 0 0118.5 22h-13A1.5 1.5 0 014 20.5v-11A1.5 1.5 0 015.5 8H7V6a5 5 0 015-5zm0 2a3 3 0 00-3 3v2h6V6a3 3 0 00-3-3zm0 9.25a1.75 1.75 0 100 3.5 1.75 1.75 0 000-3.5z"/></svg>
                        </span>
                        <span class="profile-menu-main">
                            <strong data-copy="profile_menu_pay_password">支付密码</strong>
                            <small data-copy="profile_menu_pay_password_sub">提现与绑定地址校验</small>
                        </span>
                        <span class="profile-menu-arrow">›</span>
                    </button>
                </div>
            </section>

            <button type="button" class="profile-logout-btn" id="profileLogoutBtn" onclick="handleProfileLogout()" data-copy="profile_logout_btn">退出登录</button>
            <p class="profile-foot-note" data-copy="profile_foot_note">红宝官方 · 会员中心</p>
        </div>
