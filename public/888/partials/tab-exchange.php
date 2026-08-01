        <!-- 2. 闪兑 -->
        <div class="tab-page" id="tabExchange" data-tab="exchange">
            <div class="page-hero-title" data-copy="page_hero_exchange_title">⚡ VIP 闪兑大厅</div>
            <div class="page-hero-sub" data-copy="page_hero_exchange_sub">股份 ↔ 红宝 · 实时预估到账</div>
            <div class="exchange-closed-banner" id="exchangeClosedBanner" style="display:none;" data-copy="profile_ex_r2b_closed">股份兑换红宝已关闭</div>

            <!-- 双资产互兑：股份 / 红宝 -->
            <div class="share-swap" id="dualExchangeSection">
                <div class="share-swap-panel" id="shareSwapPanel">
                    <div class="share-swap-header">
                        <div class="share-swap-title" id="shareSwapTitle" data-copy="swap_title">资产兑换</div>
                        <div class="share-swap-avail" id="shareSwapAvail" data-copy="swap_avail">可用 —</div>
                    </div>

                    <div class="share-swap-section-title" id="shareSwapFromLabel" data-copy="swap_from_label">转出</div>
                    <div class="share-swap-input-card share-swap-select-card">
                        <div class="share-swap-icon-circle" id="shareSwapFromIcon" data-copy="swap_unit_share">股</div>
                        <select class="share-swap-select" id="shareSwapFromSelect" aria-label="转出资产" data-copy-aria="swap_aria_from" onchange="onShareSwapFromChange(this.value)" data-copy-aria="swap_aria_from">
                            <option value="rights" data-copy="swap_asset_rights">股份</option>
                            <option value="hongbao" data-copy="swap_asset_hongbao">红宝</option>
                        </select>
                        <span class="share-swap-chevron" aria-hidden="true">▾</span>
                    </div>
                    <div class="share-swap-input-card">
                        <div class="share-swap-icon-circle">#</div>
                        <input type="number" class="share-swap-input" id="shareSwapAmount" inputmode="decimal" min="1" step="0.01" value="1">
                        <button type="button" class="share-swap-btn-all" id="shareSwapAllBtn" onclick="fillDualExchangeAll()" data-copy="swap_all_btn">全部</button>
                    </div>
                    <div class="share-swap-hint" id="shareSwapHint" data-copy="swap_min_hint">单次最低 1</div>

                    <div class="share-swap-divider">
                        <button type="button" class="share-swap-arrow" id="shareSwapFlipBtn" onclick="flipShareSwapDirection()" aria-label="互换方向" data-copy-aria="swap_aria_flip">
                            <svg viewBox="0 0 24 24"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
                        </button>
                    </div>

                    <div class="share-swap-section-title">
                        <svg class="share-swap-icon-prefix" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4V6h16v12zm-8-3c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3z"/></svg>
                        <span data-copy="swap_to_label">兑换目标</span>
                    </div>
                    <div class="share-swap-input-card share-swap-select-card">
                        <div class="share-swap-icon-circle" id="shareSwapToIcon">宝</div>
                        <select class="share-swap-select" id="shareSwapToSelect" aria-label="兑换目标" data-copy-aria="swap_aria_to" onchange="onShareSwapToChange(this.value)" data-copy-aria="swap_aria_to">
                            <option value="hongbao" data-copy="swap_asset_hongbao">红宝</option>
                            <option value="rights" data-copy="swap_asset_rights">股份</option>
                        </select>
                        <span class="share-swap-chevron" aria-hidden="true">▾</span>
                    </div>

                    <div class="share-swap-summary">
                        <div class="share-swap-summary-left">
                            <div class="share-swap-summary-label" data-copy="swap_rate_label">兑换比例</div>
                            <div class="share-swap-summary-value" id="shareSwapRate">—</div>
                            <div class="share-swap-summary-currency" id="shareSwapCurrency">CNY</div>
                            <div class="share-swap-summary-time" id="shareSwapTime"></div>
                        </div>
                        <div class="share-swap-vdiv" aria-hidden="true"></div>
                        <div class="share-swap-summary-right">
                            <div class="share-swap-summary-label" id="shareSwapEstLabel" data-copy="swap_est_label">预计到账</div>
                            <div class="share-swap-amount" id="shareSwapEst">0</div>
                            <div class="share-swap-summary-label" id="shareSwapEstAsset">红宝</div>
                        </div>
                    </div>

                    <button type="button" class="share-swap-submit" id="shareSwapSubmitBtn" onclick="submitShareSwap()" data-copy="swap_submit">确认兑换</button>
                    <div class="share-swap-closed" id="shareSwapClosed" style="display:none;"></div>
                </div>
            </div>
        </div>
