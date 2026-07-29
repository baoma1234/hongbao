        <!-- 1. 澶у巺 / 璧勪骇 -->
        <div class="tab-page active" id="tabHome" data-tab="home">
            <div class="jackpot-container">
                <div class="jackpot-label" data-copy="jackpot_label">📊 红宝 全网生态实时大屏</div>
                <div class="jackpot-meta" id="jackpotPartners">📈 当前全网股份人数：8,000 人 ( 🚀 今日暴涨 +0 人 )</div>
                <div class="jackpot-pool-label" id="jackpotPoolLabel" data-copy="jackpot_pool_label">💰 平台已累计为合伙人创造价值</div>
                <div class="jackpot-val" id="jackpotNum">￥2,000.00</div>
                <div class="jackpot-price-line" id="jackpotSharePrice">💎 今日大盘实时持仓行权价：￥5.00 / 份 ( 🔥 较昨日大盘拉升 +0% )</div>
                <div class="jackpot-hint-line" id="jackpotHint" data-copy="jackpot_hint">💡 等额闪兑销毁股份 · 累计价值只涨不跌 · 股价由达标转化人数驱动</div>
            </div>

            <div class="header-panel">
                <div class="assets-grid">
                    <div class="asset-card asset-card-rights">
                        <span class="asset-label" data-copy="asset_rights_label">持有资产 (未清算)</span>
                        <strong class="asset-value asset-value-gold"><span id="myTicketPool">5.00</span> <span class="asset-unit" data-copy="asset_rights_unit">份</span></strong>
                        <div class="asset-valuation-hint" id="rightsValuationHint"></div>
                    </div>
                    <div class="asset-card-divider" aria-hidden="true"></div>
                    <div class="asset-card asset-card-balance">
                        <span class="asset-label" data-copy="asset_balance_label">红利余额</span>
                        <strong class="asset-value asset-value-green">
                            <span class="asset-currency" id="balanceCurrencySym">￥</span><span id="myUserBalance">0.00</span>
                        </strong>
                        <div class="asset-progress-wrap">
                            <div class="asset-progress-bar"><div class="asset-progress-fill" id="balanceProgressFill"></div></div>
                            <div class="asset-progress-hint" id="balanceProgressHint" data-copy="balance_progress_pct">已达提现门槛的 {pct}%</div>
                        </div>
                    </div>
                    <div class="asset-card-divider" aria-hidden="true"></div>
                    <div class="asset-card asset-card-hongbao">
                        <span class="asset-label" data-copy="asset_hongbao_label">红宝</span>
                        <strong class="asset-value"><span id="myHongbaoPool">0.00</span></strong>
                    </div>
                </div>
                <div class="user-info">
                    <div class="user-id"><span data-copy="user_account_status">资产账户状态:</span> <span id="displayBindPhone">138****8888</span> <span id="userStatusTag" data-copy="user_status_shield">密匙防护已开启</span></div>
                    <div id="userRank" class="status-tag" data-copy="user_rank_default">特权确权账户</div>
                </div>
                <div class="flow-stepper" id="flowStepper" data-copy-aria="aria_flow_stepper" aria-label="福利进度">
                    <div class="flow-step done" data-step="1"><span class="n">1</span><span class="t" data-copy="stepper_1">入厅</span></div>
                    <div class="flow-step" data-step="2"><span class="n">2</span><span class="t" data-copy="stepper_2">开户</span></div>
                    <div class="flow-step" data-step="3"><span class="n">3</span><span class="t" data-copy="stepper_3">闪兑</span></div>
                    <div class="flow-step" data-step="4"><span class="n">4</span><span class="t" data-copy="stepper_4">账号</span></div>
                    <div class="flow-step" data-step="5"><span class="n">5</span><span class="t" data-copy="stepper_5">领取</span></div>
                </div>
                <div id="newbiePromoBlock">
                    <div class="share-promo-card" id="sharePromoCard" role="button" tabindex="0" onclick="copyShareLink()">
                        <div class="share-promo-glow"></div>
                        <div class="share-promo-inner single-line">
                            <span class="share-promo-text" id="sharePromoText" data-copy="share_promo_btn">📢 邀请 1 人开户 ➡️ 额外送 1 份股 (多邀多得)</span>
                            <button type="button" class="btn-share-action" id="sharePromoActionBtn" data-copy="share_promo_action_btn" onclick="event.stopPropagation(); copyShareLink();">点击立即分享</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="user-panel" id="newbieOpenPanel">
                <button type="button" class="cta-open-account" id="openAccountBtn" onclick="goToMainStation()"><span class="cta-open-account-label" id="openAccountBtnLabel" data-copy="open_account_badge_fallback">立送 2 份大盘股。</span><span class="cta-open-account-badge" id="openAccountBadge" data-copy="open_account_badge_fallback">立送 2 份大盘股。</span></button>
            </div>

            <div class="home-claim-section" id="homeClaimSection">
                <div class="page-hero-title" data-copy="page_hero_claim_title">🏦 VIP 特批领取</div>
                <div class="page-hero-sub" data-copy="page_hero_claim_sub">回填现金站游戏账号 → 提交核销 → 生成密令联系客服上分</div>
                <div class="match-card" style="padding:15px;">
                    <div class="uid-section visible" id="uidSection">
                        <label class="uid-label" for="mainStationID" data-copy="uid_label">🔑 第一步：请输入您在红宝现金站注册成功的账号</label>
                        <input
                            type="text"
                            id="mainStationID"
                            class="id-input-box"
                            name="fanshub_game_uid"
                            maxlength="32"
                            autocomplete="off"
                            autocapitalize="off"
                            autocorrect="off"
                            spellcheck="false"
                            inputmode="text"
                            enterkeyhint="done"
                            data-copy-placeholder="uid_placeholder"
                            placeholder="例如：555bio（必须使用同手机号注册），否则小妹无法在后台核销上分"
                        >
                        <div class="uid-submit-row">
                            <button type="button" class="btn-uid-submit" id="btnUidSubmit" onclick="submitUID()" data-copy="uid_submit_btn">提交账号审核</button>
                        </div>
                        <div class="uid-status-hint" id="uidStatusHint" data-copy="uid_hint_idle">请填写游戏账号（数字或英文数字组合均可），每个账号仅可提交一次审核</div>
                    </div>
                </div>
                <button class="btn-manual-settle" id="btnManualSettle" onclick="openWithdrawModal()">
                    <span id="manualSettleTitle" data-copy="settle_title_low">🏦 申请 VIP 人工加急特批绿通</span>
                    <span class="sub-label" id="manualSettleSub" data-copy="settle_sub_low">金额不足时也可提前联系专属客服协助凑数</span>
                </button>
            </div>

            <div class="home-quick-grid">
                <button type="button" class="home-quick-btn hq-exchange" onclick="switchTab('exchange')"><span data-copy="home_quick_exchange">⚡ 去闪兑</span><span data-copy="home_quick_exchange_sub">股份秒变红利余额</span></button>
                <button type="button" class="home-quick-btn hq-master" onclick="switchTab('master')"><span data-copy="home_quick_master">👑 团长大厅</span><span data-copy="home_quick_master_sub">天梯 + 7天星火暴击</span></button>
                <button type="button" class="home-quick-btn hq-messages" onclick="switchTab('messages')"><span data-copy="home_quick_messages">红宝社区</span><span data-copy="home_quick_messages_sub">私聊 · 群聊 · 红包</span></button>
                <button type="button" class="home-quick-btn hq-profile" onclick="switchTab('profile')"><span data-copy="home_quick_profile">👤 个人中心</span><span data-copy="home_quick_profile_sub">资料 · 密码 · 退出</span></button>
            </div>

            <div class="marquee-box" style="margin-top:14px;">
                <div class="marquee-content" id="marqueeNode"></div>
            </div>

            <div class="home-social-section" id="homeSocialSection">
                <div class="page-hero-title" data-copy="page_hero_social_title">💬 互动大厅</div>
                <div class="page-hero-sub" data-copy="page_hero_social_sub">看排行 · 刷视频文 · 蹭气氛，专注拉新与晒单</div>
                <div class="match-card" style="margin-top: 0; padding: 15px; text-align: left;">
                    <h4 style="font-size: 14px; margin-bottom: 10px; color: var(--secondary);" data-copy="leaderboard_title">🏆 邀请裂变排行榜 TOP10</h4>
                    <div class="leaderboard-list" id="leaderboardList">
                        <div class="text-muted" style="font-size:12px;" data-copy="leaderboard_loading">加载中...</div>
                    </div>
                </div>
                <div style="margin-top: 25px; padding: 10px; font-size: 10px; color: #657786; text-align: center; line-height: 1.6;">
                    <p data-copy="footer_line1">📊 本平台性质为【红宝 官方活跃粉丝度模拟福利推广营销互动调查大厅】</p>
                    <p data-copy="footer_line2">安全承诺：全盘无资金充值入口。所有股份及瓜分红利均属于用户活跃度内部福利。领取红利统一由官方 VIP 福利中心人工核准，并采用安全方式精准充值至您的 红宝 主站账户中。活动最终解释权归 红宝 官方所有。</p>
                    <p data-copy="footer_line3">© 2026 红宝 Open-Marketing Platform. 服务协议 | 合规声明</p>
                </div>
            </div>
        </div>

        <!-- 2. 闂厬 -->
