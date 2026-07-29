        <!-- 4. 团长二期 -->
        <div class="tab-page" id="tabMaster" data-tab="master">
            <div class="master-festive-bg" aria-hidden="true">
                <span class="master-glow master-glow--a"></span>
                <span class="master-glow master-glow--b"></span>
                <span class="master-glow master-glow--c"></span>
                <span class="master-ornament master-ornament--tl"></span>
                <span class="master-ornament master-ornament--tr"></span>
            </div>
            <div class="master-page-inner">
                <header class="master-hero">
                   
                    <h1 class="page-hero-title master-hero-title" data-copy="page_hero_master_title">团长专属二期大厅</h1>
                    <p class="master-hero-sub" data-copy="page_hero_master_sub">荣誉天梯明牌奖励 · 7天星火暴击 · 战队催活雷达</p>
                </header>

                <div id="masterLockCard" class="master-lock-card" style="display:none;">
                    <div class="master-lock-ico" aria-hidden="true">🔒</div>
                    <h3 data-copy="master_lock_title">团长通道待解锁</h3>
                    <p data-copy="master_lock_desc">完成首笔 VIP 福利核销后，将开放：荣誉天梯宝箱、7天星火暴击（￥175 核爆总池）、战队催活雷达。先去「领取」页冲刺门槛吧！</p>
                    <button type="button" class="master-lock-btn" onclick="switchTab('home'); setTimeout(function(){ var el=document.getElementById('homeClaimSection'); if(el) try{el.scrollIntoView({behavior:'smooth',block:'start'});}catch(e){} }, 80);" data-copy="master_lock_btn">前往领取页</button>
                </div>

                <div id="masterHonorBlock">
                    <div class="honor-ladder-card" id="honorLadderCard" role="button" tabindex="0" onclick="copySharePromoOnly()">
                        <div class="honor-ladder-ribbon">
                            <span class="honor-ladder-title" data-copy="phase2_honor_title">团长天梯权益</span>
                        </div>
                        <div class="honor-progress-wrap">
                            <div class="honor-progress-meta">
                                <span id="honorCurrentLabel">已达标转化 0 人</span>
                                <span id="honorProgressPct">0%</span>
                            </div>
                            <div class="honor-ladder-track"><div class="honor-ladder-fill" id="honorLadderFill" style="width:0%"></div></div>
                        </div>
                        <div class="honor-ladder-nodes" id="honorLadderNodes"></div>
                        <div class="honor-ladder-hint" id="honorLadderHint" data-copy="loading_generic">加载中...</div>
                    </div>
                </div>

                <div id="masterPhase2Block" style="display:none;">
                    <div class="checkin-panel checkin-panel--hongbao">
                        <div class="checkin-hongbao-body">
                            <div class="checkin-hongbao-head">
                                <div class="checkin-hongbao-title"><i class="checkin-flame" aria-hidden="true"></i>7天星火暴击</div>
                                <div class="checkin-streak-label" id="checkinStreakLabel" data-copy="phase2_checkin_streak">连续暴力打卡 {streak}/7天</div>
                            </div>
                            <div class="checkin-ledger" id="checkinLedgerHint" data-copy="phase2_checkin_ledger">满7天5倍核爆总池，保底 ¥175 筹码秒提现<br>断签降级，直接损失 ¥140</div>
                            <div class="checkin-streak-frozen" id="checkinStreakFrozen" style="display:none;"></div>
                            <div class="checkin-streak-bar"><div class="checkin-streak-fill" id="checkinStreakFill" style="width:0%"></div></div>
                            <div class="checkin-violent-row">
                                <input type="checkbox" id="checkinViolentToggle" checked>
                                <label for="checkinViolentToggle" id="checkinViolentLabel" data-copy="phase2_checkin_toggle">激活【5倍暴力分享签到】（今日最高 ¥{amount}）</label>
                            </div>
                            <div class="checkin-pending-box" id="checkinPendingBox" style="display:none;"></div>
                            <button type="button" class="btn-checkin-main" id="btnCheckinMain" data-copy="phase2_checkin_violent_btn" onclick="handleCheckinClick()">
                                <span class="btn-checkin-main-text">立即执行【暴力分享签到】</span>
                            </button>
                            <div class="checkin-btn-tip" id="checkinBtnTip">一键复制今日密令 · 锁定制胜7天全勤</div>
                        </div>
                    </div>

                    <div class="team-radar-panel">
                        <div class="team-radar-title" data-copy="phase2_radar_title">📡 战队催活雷达 · 实时列表</div>
                        <div class="team-radar-viewport" id="teamRadarViewport">
                            <div class="team-radar-track" id="teamRadarList"><div class="team-radar-loading" data-copy="loading_generic">加载中...</div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
