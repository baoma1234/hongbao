        const API_BASE = '';
        const CONFIG = { 
            MAX_VOTE_PERCENT: 1,
            SINGLE_TICKET_VALUE: 5.00,
            SHARE_PRICE_BASE: 5.00,
            SHARE_PRICE_MAX: 7.00,
            SEED_TOTAL_SHARES: 177777.6,
            MARKET_VIRTUAL_BASE: 8000,
            WITHDRAW_THRESHOLD: 50.00,
            EXCHANGE_MIN: 1,
            EXCHANGE_R2B_MIN: 1,
            EXCHANGE_B2R_MIN: 1,
            EXCHANGE_MAX: 99999,
            EXCHANGE_R2B_ENABLED: true,
            EXCHANGE_B2R_ENABLED: true,
            CUSTOMER_SERVICE_URL: '',
            APP_DOWNLOAD_URL: 'https://your-app.com',
            MAIN_STATION_URL: 'https://555.bio',
            IM_WS_URL: '',
            SECRET_LOCK_SECONDS: 900,
            JACKPOT_AUTO_GROW: true,
            JACKPOT_SERVER_SYNC: true,
            JACKPOT_GROW_MIN: 0.02,
            JACKPOT_GROW_MAX: 0.08,
            JACKPOT_CEILING: 20000,
            SMS_SLIDER_ENABLED: true,
            SMS_SEND_INTERVAL: 60
        };
        window.CONFIG = CONFIG;
        const SMS_COOLDOWN_KEY = 'fanshub_sms_cooldown';
        let smsCooldownTimer = null;
        let jackpotPollTimer = null;
        let jackpotLocalTimer = null;
        let fissionMarketTimer = null;
        let jackpotTimersPaused = false;
        let bonusUnlockWanted = false;
        let fissionUserCount = 0;
        let partnerTodayUp = 0;
        let priceUpPct = 0;
        let currentSharePrice = 5;
        let lotteryOpening = false;
        let lotteryFinalAmount = null;
        let marqueeItems = [];
        const LOTTERY_VAL_KEY = 'fans_hub_lottery_val';
        const LOTTERY_DONE_KEY = 'fans_hub_lottery_done';
        const FISSON_COUNT_KEY = 'fans_hub_fission_count';
        const SHARE_PRICE_KEY = 'fans_hub_share_price';
        const LOTTERY_SHARES = 5;
        let COPY = {};
        let COPY_VARS = {};
        let lastServerCopy = null;

        function syncCopyFromLocale(serverCopy) {
            if (serverCopy && typeof serverCopy === 'object') {
                lastServerCopy = serverCopy;
            }
            const pack = (window.FanshubI18n && FanshubI18n.currentPack()) || {};
            const defaults = window.FANSHUB_COPY_DEFAULTS || {};
            const locale = (window.FanshubI18n && FanshubI18n.locale) || 'zh-CN';
            const isZh = String(locale).indexOf('zh') === 0;
            // defaults <- locale pack; admin server copy overrides Chinese source
            COPY = Object.assign({}, defaults, pack);
            if (lastServerCopy) {
                Object.keys(lastServerCopy).forEach(function(k) {
                    var v = lastServerCopy[k];
                    if (v == null || v === '') return;
                    if (isZh) {
                        COPY[k] = v;
                    } else if (!Object.prototype.hasOwnProperty.call(pack, k) || pack[k] === '' || pack[k] == null) {
                        COPY[k] = v;
                    }
                });
                // 中文：后台 H5 文案必须盖过 locales/zh-CN.js 静态包（否则 brand_name 等仍显示默认 555.bio）
                if (isZh && window.FANSHUB_LOCALES) {
                    var locPack = window.FANSHUB_LOCALES[locale] || window.FANSHUB_LOCALES['zh-CN'];
                    if (locPack) {
                        Object.keys(lastServerCopy).forEach(function(k) {
                            var v = lastServerCopy[k];
                            if (v != null && v !== '') locPack[k] = v;
                        });
                    }
                }
            }
            COPY_VARS = buildCopyVars({});
        }

        function buildCopyVars(cfg) {
            var currency = (window.FanshubI18n && FanshubI18n.currencySymbol()) || '￥';
            var price = (typeof getSharePrice === 'function') ? getSharePrice() : (CONFIG.SINGLE_TICKET_VALUE || 5);
            var partners = fissionUserCount || (cfg && cfg.partner_count) || 8000;
            var seedShares = (cfg && cfg.seed_total_shares !== undefined) ? cfg.seed_total_shares : (CONFIG.SEED_TOTAL_SHARES || 177777.6);
            var avg = partners > 0 ? (seedShares / partners) : 0;
            return {
                threshold: (CONFIG.WITHDRAW_THRESHOLD || 50).toFixed(2),
                ticket_value: Number(price).toFixed(2),
                current_share_price: Number(price).toFixed(2),
                partner_count: formatCountNum(partners),
                fission_user_count: formatCountNum(partners),
                partner_today_up: formatCountNum(partnerTodayUp || (cfg && cfg.partner_today_up) || 0),
                price_up_pct: (priceUpPct || (cfg && cfg.price_up_pct) || 0).toFixed ? Number(priceUpPct || (cfg && cfg.price_up_pct) || 0).toFixed(1) : '0.0',
                seed_total_shares: Number(seedShares).toLocaleString('en-US', { maximumFractionDigits: 1 }),
                avg_shares: avg.toFixed(1),
                register_rights: cfg && cfg.register_rights !== undefined ? cfg.register_rights : 5,
                open_account_rights: cfg && cfg.open_account_rights !== undefined ? cfg.open_account_rights : 2,
                share_rights: cfg && cfg.share_rights !== undefined ? cfg.share_rights : 1,
                percent: Math.round((CONFIG.MAX_VOTE_PERCENT || 1) * 100) + '%',
                currency: currency,
            };
        }

        function fc(key, extra) {
            // COPY 已按「默认 + 语言包 + 后台覆盖」合并，优先用 COPY，避免静态 zh-CN 包盖住后台文案
            let tpl = (COPY[key] != null && COPY[key] !== '') ? COPY[key] : '';
            if (!tpl && window.FanshubI18n) {
                tpl = FanshubI18n.text(key, COPY) || '';
            }
            if (!tpl) tpl = '';
            const vars = Object.assign({}, COPY_VARS, extra || {});
            Object.keys(vars).forEach(function(k) {
                tpl = tpl.replace(new RegExp('\\{' + k + '\\}', 'g'), vars[k]);
            });
            return tpl;
        }

        function formatMoney(amount) {
            const sym = (window.FanshubI18n && FanshubI18n.currencySymbol()) || '￥';
            const num = parseFloat(amount);
            const val = isNaN(num) ? 0 : num;
            return sym + val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function formatCountNum(num) {
            return Number(num || 0).toLocaleString('en-US');
        }

        function formatSharePrice(price) {
            const p = parseFloat(price);
            const val = (!isNaN(p) && p > 0) ? p : 5;
            return val.toFixed(2);
        }

        function getSharePrice() {
            const p = parseFloat(currentSharePrice);
            return (!isNaN(p) && p > 0) ? p : (CONFIG.SINGLE_TICKET_VALUE || 5);
        }

        /** 鏈嶅姟绔帶鐩樿偂浠蜂负鍑嗭紱force 鏃惰鐩栨湰鍦版棫缂撳瓨锛堢姝㈢敤杩囨湡 localStorage 椤舵帀澶у睆鍗曚环锛?*/
        function applySharePrice(price, force) {
            let p = parseFloat(price);
            if (isNaN(p) || p <= 0) return getSharePrice();
            const floor = parseFloat(CONFIG.SHARE_PRICE_BASE) || 5;
            const ceiling = parseFloat(CONFIG.SHARE_PRICE_MAX) || 7;
            p = Math.min(ceiling, Math.max(floor, p));
            p = Math.round(p * 100) / 100;
            if (!force && currentSharePrice && p + 0.005 < currentSharePrice) {
                // 浠呮湰鍦板井璺冲姩鏃跺厑璁告杞紱鏈嶅姟绔?sync 蹇呴』 force 瀵归綈
                p = currentSharePrice;
            }
            currentSharePrice = p;
            CONFIG.SINGLE_TICKET_VALUE = p;
            localStorage.setItem(SHARE_PRICE_KEY, String(p));
            return p;
        }

        function applyMarketScreen(data, light) {
            if (!data) return;
            if (data.partner_count !== undefined || data.fission_user_count !== undefined) {
                const nextCount = parseInt(data.partner_count !== undefined ? data.partner_count : data.fission_user_count, 10) || 0;
                // 服务端同步时以服务端人数为准，保证全站一致；本地模式只允许上涨
                if (CONFIG.JACKPOT_SERVER_SYNC) {
                    fissionUserCount = Math.max(0, nextCount);
                } else {
                    fissionUserCount = Math.max(fissionUserCount || 0, nextCount);
                }
            }
            if (data.partner_today_up !== undefined) {
                partnerTodayUp = Math.max(0, parseInt(data.partner_today_up, 10) || 0);
            }
            if (data.price_up_pct !== undefined) {
                priceUpPct = Math.max(0, parseFloat(data.price_up_pct) || 0);
            }
            if (data.share_price !== undefined) {
                applySharePrice(data.share_price, true);
            }
            if (data.seed_total_shares !== undefined) {
                CONFIG.SEED_TOTAL_SHARES = parseFloat(data.seed_total_shares) || CONFIG.SEED_TOTAL_SHARES;
            }
            const amount = data.cumulative_payout !== undefined ? data.cumulative_payout : data.amount;
            if (amount !== undefined) {
                const node = document.getElementById('jackpotNum');
                if (node) {
                    // 服务端同步：金额全站一致（不以本机旧值抬高）
                    const next = parseFloat(amount) || 0;
                    if (CONFIG.JACKPOT_SERVER_SYNC) {
                        node.innerText = formatMoney(next);
                    } else {
                        const prev = parseFloat(String(node.innerText).replace(/[^\d.]/g, '')) || 0;
                        node.innerText = formatMoney(Math.max(prev, next));
                    }
                }
            }
            updateJackpotMeta(!!light);
        }

        function calcRightsValuation(rights) {
            const r = parseFloat(rights);
            if (isNaN(r) || r <= 0) return 0;
            return Math.round(r * getSharePrice() * 100) / 100;
        }

        function lotteryValuationForShares(shares) {
            const s = parseFloat(shares);
            const n = (!isNaN(s) && s > 0) ? s : LOTTERY_SHARES;
            return Math.round(n * getSharePrice() * 100) / 100;
        }

        function updateJackpotMeta(light) {
            COPY_VARS = buildCopyVars({
                partner_count: fissionUserCount,
                partner_today_up: partnerTodayUp,
                price_up_pct: priceUpPct,
                seed_total_shares: CONFIG.SEED_TOTAL_SHARES
            });
            const partners = document.getElementById('jackpotPartners');
            if (partners) {
                partners.textContent = fc('jackpot_partners', {
                    partner_count: formatCountNum(fissionUserCount),
                    partner_today_up: formatCountNum(partnerTodayUp)
                }) || ('当前全网股份人数：' + formatCountNum(fissionUserCount) + ' 人 ( 🚀 今日暴涨 +' + formatCountNum(partnerTodayUp) + ' 人 )');
            }
            const commentTitle = document.getElementById('commentHallTitle');
            if (commentTitle) {
                commentTitle.textContent = fc('comment_title', {
                    partner_count: formatCountNum(fissionUserCount)
                }) || ('馃挰 瀹炴椂绂忓埄浜や簰澶у巺 (鍏ㄧ綉 ' + formatCountNum(fissionUserCount) + ' 鍚嶇湡瀹炰細鍛樺湪绾?');
            }
            const poolLabel = document.getElementById('jackpotPoolLabel');
            if (poolLabel) {
                poolLabel.textContent = fc('jackpot_pool_label') || '馃挵 骞冲彴宸茬疮璁′负鍚堜紮浜哄垱閫犱环鍊';
            }
            const priceLine = document.getElementById('jackpotSharePrice');
            if (priceLine) {
                const p2 = formatSharePrice(getSharePrice());
                const pct = Number(priceUpPct || 0).toFixed(1);
                priceLine.textContent = fc('jackpot_price_line', {
                    current_share_price: p2,
                    price_up_pct: pct
                }) || ('今日大盘实时持仓行权价：￥' + p2 + ' / 份 ( 🔥 较昨日大盘拉升 +' + pct + '% )');
            }
            // 杞杞婚噺妯″紡锛氬彧鍒峰ぇ鐩樻暟瀛楋紝涓嶉噸绠楅棯鍏戞彁绀?澶╂
            if (light) return;
            restoreLotteryValuation();
            refreshPriceLinkedHints();
            if (phase2State && phase2State.honor && typeof renderHonorLadder === 'function') {
                renderHonorLadder(phase2State.honor);
            }
        }

        function refreshPriceLinkedHints() {
            const ticketInput = document.getElementById('ticketCount');
            const count = ticketInput ? (parseInt(ticketInput.value, 10) || 1) : 1;
            const unit = getSharePrice();
            const unitText = formatSharePrice(unit);
            const profit = (Math.round(count * unit * 100) / 100).toFixed(2);
            const currency = (window.FanshubI18n && FanshubI18n.currencySymbol()) || '￥';
            const profitBox = document.getElementById('profitHintBox');
            if (profitBox) {
                profitBox.innerHTML = fc('exchange_profit_hint', {
                    count: count,
                    profit: profit,
                    ticket_value: unitText,
                    currency: currency
                });
            }
            const unitEl = document.getElementById('exchangeUnitPrice');
            if (unitEl) unitEl.textContent = currency + unitText;
            const btn = document.getElementById('btnGoldenMain');
            if (btn) btn.innerText = fc('exchange_btn_template', { count: count });
            const cta = document.getElementById('ctaHint');
            if (cta) cta.innerHTML = fc('exchange_cta_hint', { count: count });
        }

        function initFissionMarketTicker() {
            // 涓嶅啀鐢ㄦ棫鐗?localStorage 铏氶珮鑲′环瑕嗙洊澶у睆锛涚瓑 config/jackpot 鏈嶅姟绔环 force 瀵归綈
            if (!fissionUserCount) {
                fissionUserCount = CONFIG.MARKET_VIRTUAL_BASE || 8000;
            }
            updateJackpotMeta();
            if (fissionMarketTimer) {
                clearInterval(fissionMarketTimer);
                fissionMarketTimer = null;
            }
            if (!CONFIG.JACKPOT_SERVER_SYNC) {
                fissionMarketTimer = setInterval(function() {
                    const hour = new Date().getHours();
                    const isDay = hour >= 8 && hour < 23;
                    const add = isDay
                        ? (3 + Math.floor(Math.random() * 10))
                        : Math.floor(Math.random() * 3);
                    fissionUserCount += add;
                    updateJackpotMeta();
                }, 60000);
            }
        }

        function updateRightsValuationHint(amount) {
            const node = document.getElementById('rightsValuationHint');
            if (!node) return;
            const amt = parseFloat(amount);
            if (isNaN(amt) || amt <= 0) {
                node.style.display = 'none';
                node.textContent = '';
                return;
            }
            const text = fc('asset_valuation_hint', { amount: amt.toFixed(2) });
            node.textContent = text || ('（ 当前估值：￥' + amt.toFixed(2) + ' 元 ）');
            node.style.display = 'block';
        }

        function restoreLotteryValuation() {
            const rights = parseFloat(account.rights) || 0;
            if (rights <= 0) {
                updateRightsValuationHint(0);
                return;
            }
            const amount = calcRightsValuation(rights);
            localStorage.setItem(LOTTERY_VAL_KEY, String(amount));
            updateRightsValuationHint(amount);
        }

        function finishWelcomeLottery(finalAmount) {
            const amount = (finalAmount !== undefined && finalAmount !== null)
                ? finalAmount
                : lotteryValuationForShares(LOTTERY_SHARES);
            localStorage.setItem(LOTTERY_VAL_KEY, String(amount));
            localStorage.setItem(LOTTERY_DONE_KEY, '1');
            sessionStorage.removeItem('fans_hub_show_lottery');
            account.rights = LOTTERY_SHARES;
            const pool = document.getElementById('myTicketPool');
            if (pool) pool.innerText = LOTTERY_SHARES.toFixed(2);
            updateRightsValuationHint(amount);
            lotteryFinalAmount = null;
            lotteryOpening = false;
            renderUI();
        }

        function closeWelcomeLotteryModal() {
            const mask = document.getElementById('welcomeLotteryMask');
            const closeBtn = document.getElementById('welcomeLotteryCloseBtn');
            if (closeBtn && closeBtn.disabled) return;
            if (lotteryFinalAmount !== null) {
                finishWelcomeLottery(lotteryFinalAmount);
            } else {
                lotteryOpening = false;
                lotteryFinalAmount = null;
            }
            if (mask) mask.classList.remove('show');
        }

        function setWelcomeLotteryCloseEnabled(enabled, label) {
            const closeBtn = document.getElementById('welcomeLotteryCloseBtn');
            if (!closeBtn) return;
            closeBtn.disabled = !enabled;
            closeBtn.textContent = label || fc('lottery_close_btn') ;
        }

        function openWelcomeLotteryModal() {
            if (localStorage.getItem(LOTTERY_DONE_KEY)) {
                restoreLotteryValuation();
                return;
            }
            const mask = document.getElementById('welcomeLotteryMask');
            if (!mask) return;
            lotteryOpening = false;
            lotteryFinalAmount = null;
            const chest = document.getElementById('welcomeLotteryChest');
            const price = document.getElementById('welcomeLotteryPrice');
            const shares = document.getElementById('welcomeLotteryShares');
            const hint = document.getElementById('welcomeLotteryHint');
            const burst = document.getElementById('welcomeLotteryBurst');
            if (chest) chest.classList.remove('shaking', 'opened');
            if (price) {
                const sym = (window.FanshubI18n && FanshubI18n.currencySymbol()) || '￥';
                price.textContent = sym + '--';
                price.classList.remove('revealed');
            }
            if (shares) shares.textContent = fc('lottery_shares_locked', { shares: LOTTERY_SHARES.toFixed(2) }) || ('锁定 ' + LOTTERY_SHARES.toFixed(2) + ' 份');
            if (hint) {
                hint.textContent = fc('lottery_chest_hint') || '鐐瑰嚮榛戦噾瀹濈 路 寮€鍚柊鎵嬭偂浠';
                hint.classList.add('pulse');
            }
            if (burst) burst.classList.remove('show');
            setWelcomeLotteryCloseEnabled(false, fc('lottery_close_wait') || '璇峰厛寮€鍚疂绠');
            mask.classList.add('show');
        }

        function scheduleWelcomeLottery() {
            if (localStorage.getItem(LOTTERY_DONE_KEY)) {
                restoreLotteryValuation();
                return;
            }
            if (sessionStorage.getItem('fans_hub_show_lottery') !== '1') return;
            setTimeout(openWelcomeLotteryModal, 1000);
        }

        function handleWelcomeLotteryChestClick() {
            if (lotteryOpening || localStorage.getItem(LOTTERY_DONE_KEY)) return;
            lotteryOpening = true;
            const chest = document.getElementById('welcomeLotteryChest');
            const price = document.getElementById('welcomeLotteryPrice');
            const hint = document.getElementById('welcomeLotteryHint');
            const burst = document.getElementById('welcomeLotteryBurst');
            const finalAmount = lotteryValuationForShares(LOTTERY_SHARES);
            const unit = getSharePrice();
            if (chest) {
                chest.classList.remove('opened');
                chest.classList.add('shaking');
            }
            if (price) price.classList.remove('revealed');
            if (hint) {
                hint.classList.remove('pulse');
                hint.textContent = fc('lottery_opening') || '寮€绠变腑鈥?閲戝厜娑屽姩';
            }
            if (burst) burst.classList.remove('show');
            const curSym = (window.FanshubI18n && FanshubI18n.currencySymbol()) || '￥';
            const jumpTimer = setInterval(function() {
                const wobble = (Math.random() - 0.5) * Math.min(1.2, unit * 0.08);
                const jump = Math.max(unit, Math.round((finalAmount + wobble) * 100) / 100);
                if (price) price.textContent = curSym + jump.toFixed(2);
            }, 70);
            setTimeout(function() {
                clearInterval(jumpTimer);
                if (chest) {
                    chest.classList.remove('shaking');
                    chest.classList.add('opened');
                }
                if (burst) {
                    burst.classList.remove('show');
                    // reflow to restart animation
                    void burst.offsetWidth;
                    burst.classList.add('show');
                }
                if (price) {
                    price.textContent = curSym + finalAmount.toFixed(2);
                    price.classList.add('revealed');
                }
                if (hint) hint.textContent = fc('lottery_result_shares', { shares: LOTTERY_SHARES.toFixed(2) }) || ('宸查攣瀹?' + LOTTERY_SHARES.toFixed(2) + ' 浠借偂浠?路 鍙墠寰€闂厬');
                lotteryFinalAmount = finalAmount;
                lotteryOpening = false;
                setWelcomeLotteryCloseEnabled(true, fc('lottery_close_btn') );
            }, 1100);
        }

        function getLoginMobileE164() {
            if (!window.FanshubI18n) {
                const raw = (document.getElementById('loginPhone') || {}).value || '';
                return raw.trim();
            }
            return FanshubI18n.toE164((document.getElementById('loginPhone') || {}).value || '');
        }

        function syncLoginPhoneUi() {
            if (!window.FanshubI18n) return;
            const phoneInput = document.getElementById('loginPhone');
            const countrySelect = document.getElementById('countrySelect');
            if (!phoneInput) return;
            const country = FanshubI18n.getCountry();
            const placeholderKey = country.placeholderKey || 'login_phone_placeholder';
            phoneInput.placeholder = fc(placeholderKey);
            phoneInput.maxLength = country.maxlen || 11;
            if (countrySelect) countrySelect.value = FanshubI18n.country;
        }

        function initI18nControls() {
            if (!window.FanshubI18n) return;
            FanshubI18n.applyDocumentLang();
            const localeSelect = document.getElementById('localeSelect');
            const countrySelect = document.getElementById('countrySelect');
            if (localeSelect) {
                FanshubI18n.fillLocaleSelect(localeSelect, function (key) { return fc(key); });
                localeSelect.value = FanshubI18n.locale;
                localeSelect.addEventListener('change', function () {
                    FanshubI18n.setLocale(localeSelect.value);
                });
            }
            if (countrySelect) {
                FanshubI18n.fillCountrySelect(countrySelect, function (key) { return fc(key); });
                countrySelect.addEventListener('change', function () {
                    FanshubI18n.setCountry(countrySelect.value);
                });
            }
            syncLoginPhoneUi();
        }

        window.onFanshubLocaleChange = function () {
            syncCopyFromLocale(lastServerCopy);
            applyPageCopy({ skipNetwork: true });
            initI18nControls();
            syncSmsCooldownFromStorage();
            // 切语言勿再跑完整 applyProfile（会二次 renderUI/打接口，明显卡顿）
            if (lastProfile) {
                refreshLocaleDependentProfile(lastProfile);
            } else {
                restoreLotteryValuation();
                updateBalanceProgress();
                syncClaimPageEcho();
            }
        };

        /** 仅刷新依赖文案的个人态 UI，不重复拉数/不重置业务状态 */
        function refreshLocaleDependentProfile(profile) {
            if (!profile) return;
            const p2 = profile.phase2 || {};
            if (profile.invite_rank) {
                const rank = profile.invite_rank;
                const rankNode = document.getElementById('userRank');
                if (rankNode) {
                    rankNode.classList.remove('master-tag');
                    if (p2.enabled && p2.user_mode === 'master') {
                        rankNode.innerText = fc('user_rank_master', {
                            count: (p2.total_register_count || rank.invite_count || 0)
                        }) || rankNode.innerText;
                        rankNode.classList.add('master-tag');
                    } else if (rank.invite_count > 0) {
                        rankNode.innerText = fc('user_rank_template', { rank: rank.rank, count: rank.invite_count });
                    } else {
                        rankNode.innerText = fc('user_rank_default');
                    }
                }
            }
            const statusTag = document.getElementById('userStatusTag');
            if (statusTag) statusTag.textContent = fc('user_status_shield');
            if (typeof syncUidLockUI === 'function') syncUidLockUI(profile);
            if (typeof updateManualSettleButton === 'function') updateManualSettleButton();
            if (typeof updateUidStatusHint === 'function') updateUidStatusHint(profile);
            if (p2.enabled && p2.user_mode === 'master') {
                if (typeof renderHonorLadder === 'function') renderHonorLadder(p2.honor || {});
                if (typeof renderCheckinPanel === 'function') renderCheckinPanel(p2.checkin || {});
                if (typeof refreshTeamRadarCopy === 'function') refreshTeamRadarCopy();
                else if (typeof loadTeamRadar === 'function') loadTeamRadar();
            }
            if (typeof renderProfilePanel === 'function') renderProfilePanel(profile);
        }

        window.onFanshubCountryChange = function () {
            syncLoginPhoneUi();
            syncSmsCooldownFromStorage();
        };

        function applyPageCopy(opts) {
            opts = opts || {};
            const skipNetwork = !!opts.skipNetwork;
            document.title = fc('page_title');
            document.querySelectorAll('[data-copy]').forEach(function(el) {
                if (el.id === 'userRank' || el.id === 'userStatusTag' || el.id === 'displayBindPhone' || el.id === 'jackpotMeta' || el.id === 'jackpotPartners' || el.id === 'jackpotSharePrice' || el.id === 'commentHallTitle' || el.id === 'profitHintBox' || el.id === 'btnGoldenMain' || el.id === 'ctaHint' || el.id === 'withdrawModalTitle' || el.id === 'billCodeText' || el.id === 'checkinLedgerHint' || el.id === 'honorLadderHint' || el.id === 'balanceProgressHint' || el.id === 'claimProgressHint' || el.id === 'btnCheckinMain' || el.id === 'checkinViolentLabel' || el.id === 'checkinStreakLabel' || el.id === 'welcomeLotteryShares' || el.id === 'welcomeLotteryCloseBtn' || el.id === 'welcomeLotteryHint') {
                    return;
                }
                const key = el.getAttribute('data-copy');
                const val = fc(key);
                if (val == null || val === '') return;
                if (el.getAttribute('data-copy-html') === '1') {
                    el.innerHTML = val;
                } else if (el.children && el.children.length) {
                    // 有子节点（图标/img/嵌套 span）时勿用 textContent，否则会清掉结构
                    return;
                } else {
                    el.textContent = val;
                }
            });
            document.querySelectorAll('[data-copy-placeholder]').forEach(function(el) {
                if (el.id === 'loginPhone') {
                    syncLoginPhoneUi();
                    return;
                }
                const ph = fc(el.getAttribute('data-copy-placeholder'));
                if (ph) el.placeholder = ph;
            });
            document.querySelectorAll('[data-copy-aria]').forEach(function(el) {
                const key = el.getAttribute('data-copy-aria');
                const label = key ? fc(key) : '';
                if (key && label) el.setAttribute('aria-label', label);
            });
            document.querySelectorAll('[data-copy-title]').forEach(function(el) {
                const key = el.getAttribute('data-copy-title');
                const val = key ? fc(key) : '';
                if (key && val) el.setAttribute('title', val);
            });
            document.querySelectorAll('[data-copy-alt]').forEach(function(el) {
                const key = el.getAttribute('data-copy-alt');
                const val = key ? fc(key) : '';
                if (key && val) el.setAttribute('alt', val);
            });
            updateDynamicCopy({ skipNetwork: skipNetwork });
            // 聊天区文案刷新放到下一帧，避免与整页 data-copy 抢主线程
            const runChat = function () {
                if (window.FansHubChat && typeof FansHubChat.onLocaleChange === 'function') {
                    try { FansHubChat.onLocaleChange({ skipNetwork: skipNetwork }); } catch (e) {}
                }
            };
            if (skipNetwork && typeof requestAnimationFrame === 'function') {
                requestAnimationFrame(function () { setTimeout(runChat, 0); });
            } else {
                runChat();
            }
        }

        function updateDynamicCopy(opts) {
            opts = opts || {};
            const skipNetwork = !!opts.skipNetwork;
            // 余额/资料轻量刷新：勿重建跑马灯、排行榜、团长面板（切语言/整页文案仍全量）
            const skipHomeLists = !!opts.skipHomeLists;
            refreshPriceLinkedHints();
            const jackpotHint = document.getElementById('jackpotHint');
            if (jackpotHint) jackpotHint.textContent = fc('jackpot_hint');
            updateJackpotMeta();
            const loginBtn = document.getElementById('loginSubmitBtn');
            if (loginBtn) loginBtn.textContent = fc('login_submit_btn');
            const openBtn = document.getElementById('openAccountBtn');
            if (openBtn) updateOpenAccountCta();
            const shareActionBtn = document.getElementById('sharePromoActionBtn');
            if (shareActionBtn) shareActionBtn.textContent = fc('share_promo_action_btn') || '点击立即分享';
            const thresholdOpenBtn = document.getElementById('thresholdOpenBtn');
            if (thresholdOpenBtn) thresholdOpenBtn.textContent = fc('threshold_modal_btn_open');
            const thresholdDesc = document.getElementById('thresholdModalDesc');
            if (thresholdDesc) thresholdDesc.innerHTML = fc('threshold_modal_desc');
            currentSelectedTeam = fc('channel_a_name') || 'A';
            const optA = document.getElementById('optTeamA');
            if (optA) optA.classList.add('selected');
            applySkinOptions();
            const statusTag = document.getElementById('userStatusTag');
            if (statusTag) statusTag.textContent = fc('user_status_shield');
            const lotteryShares = document.getElementById('welcomeLotteryShares');
            if (lotteryShares && !lotteryOpening) {
                lotteryShares.textContent = fc('lottery_shares_locked', { shares: LOTTERY_SHARES.toFixed(2) });
            }
            const lotteryClose = document.getElementById('welcomeLotteryCloseBtn');
            if (lotteryClose && lotteryClose.disabled) {
                lotteryClose.textContent = fc('lottery_close_wait');
            }
            const lotteryHint = document.getElementById('welcomeLotteryHint');
            if (lotteryHint && !lotteryOpening) {
                lotteryHint.textContent = fc('lottery_chest_hint');
            }
            updateBalanceProgress();
            syncClaimPageEcho();
            if (!skipHomeLists) {
                initMarqueeInterval();
            }
            /* home-i18n-refresh */
            /* share-swap-i18n-refresh */
            if (typeof refreshProfileExchangeUi === 'function') {
                try { refreshProfileExchangeUi({ preserveAmount: true }); } catch (eSwap) {}
            }
            if (typeof refreshProfileLedgerCopy === 'function') {
                try { refreshProfileLedgerCopy(); } catch (eLed) {}
            }
            if (typeof updateManualSettleButton === 'function') {
                try { updateManualSettleButton(); } catch (e1) {}
            }
            if (typeof updateUidSubmitButton === 'function') {
                try { updateUidSubmitButton(); } catch (e2) {}
            }
            if (typeof updateUidStatusHint === 'function') {
                try { updateUidStatusHint(typeof account !== 'undefined' ? account : null); } catch (e3) {}
            }
            if (!skipHomeLists) {
                // 切语言：只重绘本地缓存榜，避免重复请求接口
                if (skipNetwork) {
                    if (typeof refreshLeaderboardCopy === 'function') {
                        try { refreshLeaderboardCopy(); } catch (eLb) {}
                    } else if (typeof loadLeaderboard === 'function') {
                        try { loadLeaderboard(); } catch (eLb2) {}
                    }
                } else if (typeof loadLeaderboard === 'function') {
                    try { loadLeaderboard(); } catch (eLb) {}
                }
                /* master-i18n-refresh */
                if (phase2State && phase2State.user_mode === 'master') {
                    try {
                        if (typeof renderHonorLadder === 'function') renderHonorLadder(phase2State.honor || {});
                        if (typeof renderCheckinPanel === 'function') renderCheckinPanel(phase2State.checkin || {});
                        if (skipNetwork && typeof refreshTeamRadarCopy === 'function') {
                            refreshTeamRadarCopy();
                        } else if (typeof loadTeamRadar === 'function') {
                            loadTeamRadar();
                        }
                    } catch (eMaster) {}
                }
            }
        }

        function parseOpenAccountCta(raw) {
            const bracket = raw.match(/^(.+?)\[(.+?)\]\((.+?)\)\s*$/);
            if (bracket) {
                return { label: (bracket[1] + '[' + bracket[2] + ']').trim(), badge: bracket[3].trim() };
            }
            const paren = raw.match(/^(.+?)\(([^)]+)\)\s*$/);
            if (paren) {
                return { label: paren[1].trim(), badge: paren[2].trim() };
            }
            const rights = COPY_VARS.open_account_rights || 2;
            return {
                label: raw,
                badge: fc('open_account_badge_fallback', { open_account_rights: rights }) || ('立得 ' + rights + ' 份大盘股。')
            };
        }

        function updateOpenAccountCta() {
            const parts = parseOpenAccountCta(fc('open_account_btn'));
            const label = document.getElementById('openAccountBtnLabel');
            const badge = document.getElementById('openAccountBadge');
            if (label) label.textContent = parts.label;
            if (badge) badge.textContent = parts.badge;
        }

        function balanceProgressHintText(progressPct, ready) {
            if (ready) {
                return fc('balance_progress_ready') || '鉁?宸茶揪鎻愮幇闂ㄦ锛屽彲绔嬪嵆鎻愮幇';
            }
            const pct = Math.round(progressPct);
            return fc('balance_progress_pct', { pct: pct }) || ('宸茶揪鎻愮幇闂ㄦ鐨?' + pct + '%');
        }

        function updateBalanceProgress() {
            // 提现门槛进度按红宝
            const balance = Number(account.hongbao) || 0;
            const threshold = CONFIG.WITHDRAW_THRESHOLD || 50;
            const progress = threshold > 0 ? Math.min(100, (balance / threshold) * 100) : 0;
            const ready = balance >= threshold;
            const fill = document.getElementById('balanceProgressFill');
            const hint = document.getElementById('balanceProgressHint');
            const sym = document.getElementById('balanceCurrencySym');
            if (sym) sym.textContent = (window.FanshubI18n && FanshubI18n.currencySymbol()) || '￥';
            if (fill) fill.style.width = progress + '%';
            if (hint) {
                hint.textContent = balanceProgressHintText(progress, ready);
                hint.classList.toggle('ready', ready);
            }
        }

        let fanshubToastTimer = null;
        function showFanshubToast(message, type, durationMs) {
            let el = document.getElementById('fanshubToast');
            if (!el) {
                el = document.createElement('div');
                el.id = 'fanshubToast';
                el.className = 'fanshub-toast';
                document.body.appendChild(el);
            }
            const text = String(message || '').replace(/\\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
            el.textContent = text;
            const kind = (type === 'error' || type === 'info') ? type : 'success';
            el.className = 'fanshub-toast show ' + kind;
            clearTimeout(fanshubToastTimer);
            const ms = durationMs || Math.min(6500, Math.max(2600, text.length * 45));
            fanshubToastTimer = setTimeout(function() {
                el.classList.remove('show');
            }, ms);
        }

        function setBottomActionBarVisible(visible) {
            const bar = document.getElementById('bottomActionBar');
            const dash = document.getElementById('mainDashboardView');
            if (bar) bar.classList.toggle('show', !!visible);
            if (dash) dash.classList.toggle('has-bottom-bar', !!visible);
        }

        let currentTab = 'home';
        let socialLoaded = false;
        let teamRadarLoaded = false;
        function ensureSocialLoaded() {
            if (socialLoaded) return;
            socialLoaded = true;
            if (typeof loadLeaderboard === 'function') loadLeaderboard();
            if (typeof initComments === 'function' && document.getElementById('commentScrollBox')) initComments();
        }
        function switchTab(tab) {
            const map = {
                home: 'tabHome',
                exchange: 'tabExchange',
                master: 'tabMaster',
                messages: 'tabMessages',
                profile: 'tabProfile'
            };
            var scrollTargetId = '';
            if (tab === 'claim') {
                tab = 'home';
                scrollTargetId = 'homeClaimSection';
            }
            if (tab === 'social') {
                tab = 'home';
                scrollTargetId = 'homeSocialSection';
            }
            if (!map[tab]) tab = 'home';
            const prevTab = currentTab;
            currentTab = tab;
            document.body.classList.toggle('tab-home', tab === 'home');
            document.body.classList.toggle('tab-messages', tab === 'messages');
            document.body.classList.toggle('tab-master', tab === 'master');
            document.querySelectorAll('#mainDashboardView .tab-page').forEach(function(el) {
                el.classList.toggle('active', el.id === map[tab]);
            });
            document.querySelectorAll('#bottomActionBar .tab-btn').forEach(function(btn) {
                btn.classList.toggle('active', btn.getAttribute('data-tab') === tab);
            });
            if (scrollTargetId) {
                setTimeout(function() {
                    var sec = document.getElementById(scrollTargetId);
                    if (sec) {
                        try { sec.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
                    }
                }, 80);
            } else {
                try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) { window.scrollTo(0, 0); }
            }
            // 彩金定时器：仅首页前台运行
            if (tab === 'home') {
                initMarqueeInterval();
                ensureSocialLoaded();
                if (localStorage.getItem('fans_hub_token') && !document.hidden) {
                    startJackpotSync();
                }
            } else if (prevTab === 'home') {
                stopJackpotTimers();
                if (fissionMarketTimer) {
                    clearInterval(fissionMarketTimer);
                    fissionMarketTimer = null;
                }
            }
            // 任意 Tab：登录后优先保活 IM WebSocket（不等到进消息页）
            if (localStorage.getItem('fans_hub_token')) {
                if (window.FansHubAssets && typeof FansHubAssets.ensureChat === 'function') {
                    FansHubAssets.ensureChat().then(function () {
                        if (window.FansHubChat) {
                            if (typeof FansHubChat.ensureConnected === 'function') FansHubChat.ensureConnected();
                            else if (typeof FansHubChat.connect === 'function') FansHubChat.connect(false);
                        }
                    }).catch(function () {});
                } else if (window.FansHubChat) {
                    if (typeof FansHubChat.ensureConnected === 'function') FansHubChat.ensureConnected();
                    else if (typeof FansHubChat.connect === 'function') FansHubChat.connect(false);
                }
            }
            if (tab === 'messages') {
                if (window.FansHubAssets && typeof FansHubAssets.ensureChat === 'function') {
                    FansHubAssets.ensureChat().then(function () {
                        if (window.FansHubChat) FansHubChat.onTabEnter();
                    }).catch(function (e) {
                        console.warn('chat load fail', e);
                        showFanshubToast((e && e.message) || '消息模块加载失败', 'error');
                    });
                } else if (window.FansHubChat) {
                    FansHubChat.onTabEnter();
                }
            }
            if (tab === 'master' && phase2State && phase2State.user_mode === 'master') {
                if (!teamRadarLoaded && typeof loadTeamRadar === 'function') {
                    teamRadarLoaded = true;
                    loadTeamRadar();
                }
            }
            if (tab === 'exchange' && typeof refreshPriceLinkedHints === 'function') {
                refreshPriceLinkedHints();
            }
            if (tab === 'profile') {
                closeProfileSubPage();
                renderProfilePanel();
            }
        }

        function syncClaimPageEcho() {
            /* claim balance echo removed — kept as no-op for legacy callers */
        }

        function updateFlowStepper() {
            const uid = ((document.getElementById('mainStationID') || {}).value || '').trim();
            const opened = flowStage === 'stage2' || !!uid;
            const exchanged = (parseFloat(account.hongbao) || 0) > 0 || (parseFloat(account.balance) || 0) > 0;
            const claimReady = (parseFloat(account.hongbao) || 0) >= (CONFIG.WITHDRAW_THRESHOLD || 50);
            const doneFlags = [true, opened, exchanged, !!uid, claimReady];
            let current = 1;
            for (let i = 0; i < doneFlags.length; i++) {
                if (doneFlags[i]) current = i + 1;
                else {
                    current = i + 1;
                    break;
                }
            }
            if (claimReady) current = 5;
            document.querySelectorAll('#flowStepper .flow-step').forEach(function(el) {
                const step = parseInt(el.getAttribute('data-step'), 10) || 0;
                el.classList.remove('done', 'active');
                if (doneFlags[step - 1]) el.classList.add('done');
                else if (step === current) el.classList.add('active');
            });
        }

        function applySkinOptions() {
            const map = {
                default: 'skin_option_default',
                a: 'skin_option_a',
                b: 'skin_option_b',
                d: 'skin_option_d'
            };
            Object.keys(map).forEach(function(val) {
                const opt = document.querySelector('#skinSelect option[value="' + val + '"]');
                if (opt) opt.textContent = fc(map[val]);
            });
        }
        const PAGE_INVITE_CODE = new URLSearchParams(window.location.search).get('code')
            || new URLSearchParams(window.location.search).get('invite') 
        let API_SIGN_ENABLED = false;
        let API_SIGN_SECRET = '';
        let DEVICE_FINGERPRINT = localStorage.getItem('fans_hub_device_fp') 
        let configReadyPromise = null;
        let serverConfigLoaded = false;

        function ensureServerConfig() {
            if (serverConfigLoaded) {
                return Promise.resolve(true);
            }
            if (configReadyPromise) {
                return configReadyPromise;
            }
            configReadyPromise = apiRequest('config', 'GET').then(function(cfg) {
                return Promise.resolve(applyServerConfig(cfg)).then(function() {
                    serverConfigLoaded = true;
                    return true;
                });
            }).catch(function(e) {
                configReadyPromise = null;
                console.warn('鍔犺浇绛惧悕/娲诲姩閰嶇疆澶辫触', e);
                throw e;
            });
            return configReadyPromise;
        }

        function shouldClearTokenOnProfileError(err) {
            const msg = String((err && err.message) || '');
            if (!msg) return false;
            // 浠呴壌鏉冨け鏁堟竻 token锛涚鍚?缃戠粶鐬椂閿欒淇濈暀锛岄伩鍏嶅埛鏂拌鎺夌嚎
            return /璇风櫥褰晐鏈櫥褰晐鐧诲綍澶辨晥|鐧诲綍鍚庢搷浣渱token|Token|鎺堟潈|璁よ瘉|閴存潈/i.test(msg);
        }

        async function restoreSessionProfile() {
            await ensureServerConfig();
            try {
                return await apiRequest('profile', 'GET');
            } catch (e) {
                if (shouldClearTokenOnProfileError(e)) {
                    throw e;
                }
                // 閰嶇疆鍙兘鏈氨缁垨瀵嗛挜鐬€侀棶棰橈細寮哄埗閲嶆媺 config 鍐嶈瘯涓€娆?
                serverConfigLoaded = false;
                configReadyPromise = null;
                await ensureServerConfig();
                return await apiRequest('profile', 'GET');
            }
        }

        function hasSubtleCrypto() {
            return !!(window.crypto && window.crypto.subtle && typeof window.crypto.subtle.digest === 'function');
        }

        function bytesToHex(bytes) {
            return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
        }

        function utf8ToBytes(str) {
            if (typeof TextEncoder !== 'undefined') {
                return new TextEncoder().encode(str);
            }
            const utf8 = unescape(encodeURIComponent(str));
            const out = new Uint8Array(utf8.length);
            for (let i = 0; i < utf8.length; i++) out[i] = utf8.charCodeAt(i);
            return out;
        }

        function sha256Bytes(data) {
            const K = new Uint32Array([
                0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
                0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
                0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
                0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
                0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
                0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
                0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
                0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2
            ]);
            const bytes = data instanceof Uint8Array ? data : new Uint8Array(data);
            const bitLen = bytes.length * 8;
            const withOne = new Uint8Array(((bytes.length + 9 + 63) >> 6) << 6);
            withOne.set(bytes);
            withOne[bytes.length] = 0x80;
            const view = new DataView(withOne.buffer);
            view.setUint32(withOne.length - 4, bitLen, false);
            const h = new Uint32Array([0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19]);
            const w = new Uint32Array(64);
            for (let i = 0; i < withOne.length; i += 64) {
                for (let j = 0; j < 16; j++) w[j] = view.getUint32(i + j * 4, false);
                for (let j = 16; j < 64; j++) {
                    const s0 = ((w[j-15] >>> 7) | (w[j-15] << 25)) ^ ((w[j-15] >>> 18) | (w[j-15] << 14)) ^ (w[j-15] >>> 3);
                    const s1 = ((w[j-2] >>> 17) | (w[j-2] << 15)) ^ ((w[j-2] >>> 19) | (w[j-2] << 13)) ^ (w[j-2] >>> 10);
                    w[j] = (w[j-16] + s0 + w[j-7] + s1) >>> 0;
                }
                let a=h[0],b=h[1],c=h[2],d=h[3],e=h[4],f=h[5],g=h[6],hh=h[7];
                for (let j = 0; j < 64; j++) {
                    const S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
                    const ch = (e & f) ^ (~e & g);
                    const t1 = (hh + S1 + ch + K[j] + w[j]) >>> 0;
                    const S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
                    const maj = (a & b) ^ (a & c) ^ (b & c);
                    const t2 = (S0 + maj) >>> 0;
                    hh = g; g = f; f = e; e = (d + t1) >>> 0; d = c; c = b; b = a; a = (t1 + t2) >>> 0;
                }
                h[0]=(h[0]+a)>>>0; h[1]=(h[1]+b)>>>0; h[2]=(h[2]+c)>>>0; h[3]=(h[3]+d)>>>0;
                h[4]=(h[4]+e)>>>0; h[5]=(h[5]+f)>>>0; h[6]=(h[6]+g)>>>0; h[7]=(h[7]+hh)>>>0;
            }
            const out = new Uint8Array(32);
            const outView = new DataView(out.buffer);
            for (let i = 0; i < 8; i++) outView.setUint32(i * 4, h[i], false);
            return out;
        }

        function sha256HexSync(str) {
            return bytesToHex(sha256Bytes(utf8ToBytes(str)));
        }

        function hmacSha256HexSync(message, secret) {
            const blockSize = 64;
            let key = utf8ToBytes(secret);
            if (key.length > blockSize) key = sha256Bytes(key);
            if (key.length < blockSize) {
                const padded = new Uint8Array(blockSize);
                padded.set(key);
                key = padded;
            }
            const inner = new Uint8Array(blockSize + utf8ToBytes(message).length);
            const outer = new Uint8Array(blockSize + 32);
            for (let i = 0; i < blockSize; i++) {
                inner[i] = key[i] ^ 0x36;
                outer[i] = key[i] ^ 0x5c;
            }
            inner.set(utf8ToBytes(message), blockSize);
            outer.set(sha256Bytes(inner), blockSize);
            return bytesToHex(sha256Bytes(outer));
        }

        async function sha256Hex(str) {
            if (hasSubtleCrypto()) {
                try {
                    const buf = await crypto.subtle.digest('SHA-256', utf8ToBytes(str));
                    return bytesToHex(new Uint8Array(buf));
                } catch (e) {}
            }
            return sha256HexSync(str);
        }

        async function hmacSha256Hex(message, secret) {
            if (hasSubtleCrypto()) {
                try {
                    const enc = new TextEncoder();
                    const key = await crypto.subtle.importKey('raw', enc.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
                    const sig = await crypto.subtle.sign('HMAC', key, enc.encode(message));
                    return bytesToHex(new Uint8Array(sig));
                } catch (e) {}
            }
            return hmacSha256HexSync(message, secret);
        }

        async function getDeviceFingerprint() {
            if (DEVICE_FINGERPRINT) return DEVICE_FINGERPRINT;
            const parts = [
                navigator.userAgent || '',
                navigator.language || '',
                screen.width + 'x' + screen.height,
                screen.colorDepth || '',
                new Date().getTimezoneOffset(),
                navigator.platform || ''
            ];
            try {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                ctx.textBaseline = 'top';
                ctx.font = '14px Arial';
                ctx.fillText('fanshub_fp', 2, 2);
                parts.push(canvas.toDataURL());
            } catch (e) {}
            DEVICE_FINGERPRINT = await sha256Hex(parts.join('|'));
            localStorage.setItem('fans_hub_device_fp', DEVICE_FINGERPRINT);
            return DEVICE_FINGERPRINT;
        }

        async function apiRequest(action, method, body) {
            const headers = { 'Content-Type': 'application/json' };
            const token = localStorage.getItem('fans_hub_token');
            if (token) headers['token'] = token;
            const locale = (window.FanshubI18n && FanshubI18n.locale) || 'zh-CN';
            headers['X-Fanshub-Locale'] = locale;
            const httpMethod = (method || 'POST').toUpperCase();
            let url = API_BASE + '/api/fanshub/' + action;
            let bodyStr = '';
            const localeQs = 'locale=' + encodeURIComponent(locale);
            if (httpMethod === 'GET') {
                const qs = body ? (new URLSearchParams(body).toString() + '&' + localeQs) : localeQs;
                url += '?' + qs;
            } else if (body) {
                bodyStr = JSON.stringify(Object.assign({}, body, { locale: locale }));
            } else {
                bodyStr = JSON.stringify({ locale: locale });
            }
            if (API_SIGN_ENABLED && API_SIGN_SECRET) {
                const ts = Math.floor(Date.now() / 1000);
                const nonce = newRequestId('nonce');
                const path = '/api/fanshub/' + action;
                const payload = ts + '\n' + nonce + '\n' + httpMethod + '\n' + path + '\n' + (httpMethod === 'GET' ? '' : bodyStr);
                headers['X-Fanshub-Timestamp'] = String(ts);
                headers['X-Fanshub-Nonce'] = nonce;
                headers['X-Fanshub-Sign'] = await hmacSha256Hex(payload, API_SIGN_SECRET);
            }
            const opts = { method: httpMethod, headers };
            if (bodyStr) opts.body = bodyStr;
            const res = await fetch(url, opts);
            let data;
            try {
                data = await res.json();
            } catch (e) {
                throw new Error(fc('alert_api_bad_response'));
            }
            if (data.code !== 1) {
                const err = new Error(data.msg || data.message || fc('alert_api_request_fail'));
                err.payload = data.data || null;
                throw err;
            }
            return data.data;
        }
        window.apiRequest = apiRequest;

        window.uploadFanshubFile = async function(action, file) {
            const fd = new FormData();
            fd.append('file', file);
            const headers = {};
            const token = localStorage.getItem('fans_hub_token');
            if (token) headers['token'] = token;
            const locale = (window.FanshubI18n && FanshubI18n.locale) || 'zh-CN';
            headers['X-Fanshub-Locale'] = locale;
            const path = '/api/fanshub/' + action;
            const url = API_BASE + path;
            if (API_SIGN_ENABLED && API_SIGN_SECRET) {
                const ts = Math.floor(Date.now() / 1000);
                const nonce = newRequestId('nonce');
                const payload = ts + '\n' + nonce + '\nPOST\n' + path + '\n';
                headers['X-Fanshub-Timestamp'] = String(ts);
                headers['X-Fanshub-Nonce'] = nonce;
                headers['X-Fanshub-Sign'] = await hmacSha256Hex(payload, API_SIGN_SECRET);
            }
            const res = await fetch(url, { method: 'POST', headers, body: fd });
            let data;
            try { data = await res.json(); } catch (e) { throw new Error(fc('alert_api_bad_response')); }
            if (data.code !== 1) throw new Error(data.msg || data.message || fc('alert_api_request_fail'));
            return data.data;
        };

        function getSmsCooldownRemain(phone) {
            if (!phone) return 0;
            try {
                const map = JSON.parse(localStorage.getItem(SMS_COOLDOWN_KEY) || '{}');
                const until = map[phone];
                if (!until) return 0;
                const remain = Math.ceil((until - Date.now()) / 1000);
                return remain > 0 ? remain : 0;
            } catch (e) {
                return 0;
            }
        }

        function setSmsCooldownUntil(phone, seconds) {
            if (!phone || seconds <= 0) return;
            try {
                const map = JSON.parse(localStorage.getItem(SMS_COOLDOWN_KEY) || '{}');
                map[phone] = Date.now() + seconds * 1000;
                localStorage.setItem(SMS_COOLDOWN_KEY, JSON.stringify(map));
            } catch (e) {}
        }

        function startSmsCooldown(phone, seconds) {
            const btn = document.getElementById('captchaBtn');
            if (!btn) return;
            const total = Math.max(1, parseInt(seconds, 10) || CONFIG.SMS_SEND_INTERVAL || 60);
            setSmsCooldownUntil(phone, total);
            btn.classList.add('disabled');
            btn.disabled = true;
            if (smsCooldownTimer) clearInterval(smsCooldownTimer);
            let count = total;
            function tick() {
                if (count <= 0) {
                    clearInterval(smsCooldownTimer);
                    smsCooldownTimer = null;
                    btn.classList.remove('disabled');
                    btn.disabled = false;
                    btn.innerText = fc('login_captcha_btn');
                    return;
                }
                btn.innerText = fc('login_captcha_resend', { count: count });
                count--;
            }
            tick();
            smsCooldownTimer = setInterval(tick, 1000);
        }

        function syncSmsCooldownFromStorage() {
            const phone = getLoginMobileE164();
            const remain = getSmsCooldownRemain(phone);
            if (remain > 0) {
                startSmsCooldown(phone, remain);
            }
        }

        function detectBrowserLocale() {
            var langs = navigator.languages || [navigator.language || ''];
            var map = {
                'zh-cn': 'zh-CN', 'zh': 'zh-CN',
                'en-ph': 'en-PH', 'fil': 'en-PH', 'tl': 'en-PH',
                'en': 'en-PH',
                'km': 'km-KH', 'km-kh': 'km-KH',
                'id': 'id-ID', 'id-id': 'id-ID',
                'vi': 'vi-VN', 'vi-vn': 'vi-VN',
                'ms': 'ms-MY', 'ms-my': 'ms-MY'
            };
            for (var i = 0; i < langs.length; i++) {
                var raw = String(langs[i] || '').toLowerCase();
                if (map[raw]) return map[raw];
                var short = raw.split('-')[0];
                if (map[short]) return map[short];
            }
            return null;
        }

        function applyDefaultLocale(cfg) {
            if (!window.FanshubI18n || !cfg) return Promise.resolve();
            try {
                if (localStorage.getItem('fans_hub_locale')) return Promise.resolve();
                var locale = cfg.default_locale || 'zh-CN'; 
                if (cfg.locale_auto_detect !== false) {
                    var detected = detectBrowserLocale();
                    if (detected) locale = detected;
                }
                if (window.FANSHUB_LOCALE_META && window.FANSHUB_LOCALE_META[locale]) {
                    return FanshubI18n.setLocale(locale);
                }
            } catch (e) {}
            return Promise.resolve();
        }

        function isHomeTabActive() {
            return currentTab === 'home';
        }

        function canRunJackpotTimers() {
            const dash = document.getElementById('mainDashboardView');
            return !!(dash && dash.classList.contains('active') && isHomeTabActive() && !document.hidden);
        }

        function stopJackpotTimers() {
            if (jackpotPollTimer) {
                clearInterval(jackpotPollTimer);
                jackpotPollTimer = null;
            }
            if (jackpotLocalTimer) {
                clearInterval(jackpotLocalTimer);
                jackpotLocalTimer = null;
            }
        }

        function tickJackpotLocal() {
            // 开启服务端同步时：金额全站一致，禁止本机随机加数（否则每人看到的大盘不同）
            if (CONFIG.JACKPOT_SERVER_SYNC || !CONFIG.JACKPOT_AUTO_GROW || !canRunJackpotTimers()) return;
            const jackpotNode = document.getElementById('jackpotNum');
            if (!jackpotNode) return;
            let currentAmt = parseFloat(jackpotNode.innerText.replace(/[^\d.]/g, ''));
            const ceiling = CONFIG.JACKPOT_CEILING || 20000;
            if (currentAmt >= ceiling) {
                jackpotNode.innerText = formatMoney(ceiling);
                return;
            }
            const minG = CONFIG.JACKPOT_GROW_MIN || 0.02;
            const maxG = CONFIG.JACKPOT_GROW_MAX || 0.08;
            const growth = Math.min(
                ceiling - currentAmt,
                minG + (Math.random() * Math.max(0, maxG - minG))
            );
            jackpotNode.innerText = formatMoney(currentAmt + Math.max(0, growth));
        }

        async function pollJackpotFromServer() {
            if (!canRunJackpotTimers()) return;
            try {
                const data = await apiRequest('jackpot', 'GET');
                if (!canRunJackpotTimers()) return;
                applyMarketScreen(data, true);
            } catch (e) {
                console.warn('jackpot poll failed', e);
            }
        }

        function startJackpotSync() {
            stopJackpotTimers();
            jackpotTimersPaused = false;
            if (!canRunJackpotTimers()) {
                jackpotTimersPaused = !!document.hidden;
                return;
            }
            // 金额：服务端权威（全站一致）；人数：高频轮询，真人注册后尽快涨上去
            if (CONFIG.JACKPOT_AUTO_GROW && !CONFIG.JACKPOT_SERVER_SYNC) {
                jackpotLocalTimer = setInterval(tickJackpotLocal, 2000);
            }
            if (CONFIG.JACKPOT_SERVER_SYNC) {
                pollJackpotFromServer();
                jackpotPollTimer = setInterval(pollJackpotFromServer, 2000);
            }
        }

        function pauseBackgroundTimers() {
            jackpotTimersPaused = true;
            stopJackpotTimers();
            if (fissionMarketTimer) {
                clearInterval(fissionMarketTimer);
                fissionMarketTimer = null;
            }
            if (bonusUnlockPollTimer) {
                clearInterval(bonusUnlockPollTimer);
                bonusUnlockPollTimer = null;
            }
        }

        function resumeBackgroundTimers() {
            const dash = document.getElementById('mainDashboardView');
            const inDash = dash && dash.classList.contains('active');
            if (inDash && localStorage.getItem('fans_hub_token') && isHomeTabActive()) {
                startJackpotSync();
            }
            if (bonusUnlockWanted && phase2State && phase2State.checkin) {
                syncBonusUnlockPoll(phase2State.checkin);
            }
            if (!CONFIG.JACKPOT_SERVER_SYNC && inDash && isHomeTabActive()) {
                initFissionMarketTicker();
            }
        }

        function applyServerConfig(cfg) {
            if (!cfg) return Promise.resolve();
            CONFIG.SHARE_PRICE_BASE = parseFloat(cfg.market_share_price_base || cfg.single_ticket_value) || CONFIG.SHARE_PRICE_BASE || 5;
            CONFIG.SHARE_PRICE_MAX = parseFloat(cfg.market_share_price_max || cfg.share_price_max) || CONFIG.SHARE_PRICE_MAX || 7;
            CONFIG.SEED_TOTAL_SHARES = parseFloat(cfg.seed_total_shares || cfg.market_total_shares_seed) || CONFIG.SEED_TOTAL_SHARES || 177777.6;
            CONFIG.MARKET_VIRTUAL_BASE = parseInt(cfg.market_virtual_base, 10) || CONFIG.MARKET_VIRTUAL_BASE || 8000;
            const cfgPrice = parseFloat(cfg.current_share_price || cfg.single_ticket_value);
            if (!isNaN(cfgPrice) && cfgPrice > 0) {
                applySharePrice(cfgPrice, true);
            }
            if (cfg.partner_count !== undefined || cfg.fission_user_count !== undefined) {
                const pc = parseInt(cfg.partner_count !== undefined ? cfg.partner_count : cfg.fission_user_count, 10) || 0;
                if (cfg.jackpot_server_sync !== false) {
                    fissionUserCount = Math.max(0, pc);
                } else {
                    fissionUserCount = Math.max(fissionUserCount || 0, pc, CONFIG.MARKET_VIRTUAL_BASE);
                }
            }
            if (cfg.partner_today_up !== undefined) {
                partnerTodayUp = Math.max(0, parseInt(cfg.partner_today_up, 10) || 0);
            }
            if (cfg.price_up_pct !== undefined) {
                priceUpPct = Math.max(0, parseFloat(cfg.price_up_pct) || 0);
            }
            CONFIG.WITHDRAW_THRESHOLD = parseFloat(cfg.withdraw_threshold) || CONFIG.WITHDRAW_THRESHOLD;
            CONFIG.MAX_VOTE_PERCENT = parseFloat(cfg.max_vote_percent) || CONFIG.MAX_VOTE_PERCENT;
            const legacyMin = Math.max(1, parseFloat(cfg.exchange_min) || 1);
            CONFIG.EXCHANGE_R2B_MIN = Math.max(1, parseFloat(cfg.exchange_r2b_min) || legacyMin);
            CONFIG.EXCHANGE_B2R_MIN = Math.max(1, parseFloat(cfg.exchange_b2r_min) || legacyMin);
            CONFIG.EXCHANGE_MIN = CONFIG.EXCHANGE_R2B_MIN;
            CONFIG.EXCHANGE_R2B_ENABLED = cfg.exchange_rights_to_balance_enabled !== false;
            CONFIG.EXCHANGE_B2R_ENABLED = cfg.exchange_balance_to_rights_enabled !== false;
            CONFIG.HONGBAO_UNIT_VALUE = Math.max(0.0001, parseFloat(cfg.hongbao_unit_value) || 1);
            CONFIG.EXCHANGE_PAIRS = (cfg.exchange_pairs && typeof cfg.exchange_pairs === 'object') ? cfg.exchange_pairs : {};
            if (typeof syncExchangeGateUi === 'function') syncExchangeGateUi();
            CONFIG.SECRET_LOCK_SECONDS = parseInt(cfg.secret_lock_seconds, 10) || CONFIG.SECRET_LOCK_SECONDS;
            CONFIG.CUSTOMER_SERVICE_URL = cfg.customer_service_url || CONFIG.CUSTOMER_SERVICE_URL;
            CONFIG.APP_DOWNLOAD_URL = cfg.app_download_url || CONFIG.APP_DOWNLOAD_URL;
            CONFIG.MAIN_STATION_URL = cfg.main_station_url || CONFIG.MAIN_STATION_URL;
            CONFIG.IM_WS_URL = cfg.im_ws_url || CONFIG.IM_WS_URL || '';
            marqueeItems = Array.isArray(cfg.marquee_items) ? cfg.marquee_items : [];
            CONFIG.JACKPOT_AUTO_GROW = cfg.jackpot_auto_grow !== false;
            CONFIG.JACKPOT_SERVER_SYNC = cfg.jackpot_server_sync !== false;
            CONFIG.JACKPOT_CEILING = parseFloat(cfg.jackpot_ceiling) || 20000;
            // 前端本地滚动用微增量；日线大额增长走服务端
            CONFIG.JACKPOT_GROW_MIN = parseFloat(cfg.jackpot_micro_grow_min) || 0.02;
            CONFIG.JACKPOT_GROW_MAX = parseFloat(cfg.jackpot_micro_grow_max) || 0.08;
            const jackpotAmt = cfg.jackpot_current !== undefined
                ? parseFloat(cfg.jackpot_current)
                : (parseFloat(cfg.jackpot_base) || 12000);
            const jackpotNode = document.getElementById('jackpotNum');
            if (jackpotNode) {
                if (CONFIG.JACKPOT_SERVER_SYNC) {
                    jackpotNode.innerText = formatMoney(jackpotAmt || 0);
                } else {
                    const prev = parseFloat(String(jackpotNode.innerText).replace(/[^\d.]/g, '')) || 0;
                    jackpotNode.innerText = formatMoney(Math.max(prev, jackpotAmt || 0));
                }
            }
            CONFIG.SMS_SLIDER_ENABLED = cfg.sms_slider_enabled !== false;
            CONFIG.SMS_SEND_INTERVAL = parseInt(cfg.sms_send_interval, 10) || 60;
            CONFIG.PHASE2_ENABLED = cfg.phase2_enabled !== false;
            CONFIG.CHECKIN_VIOLENT_BONUS = parseFloat(cfg.checkin_violent_bonus) || 4;
            API_SIGN_ENABLED = !!cfg.api_sign_enabled;
            API_SIGN_SECRET = cfg.api_sign_secret || '';
            serverConfigLoaded = true;
            return Promise.resolve(applyDefaultLocale(cfg)).then(function() {
                if (window.FanshubI18n) {
                    return FanshubI18n.ensureLocaleLoaded();
                }
            }).then(function() {
                syncCopyFromLocale(cfg.copy);
                COPY_VARS = buildCopyVars(cfg);
                applyPageCopy();
                initI18nControls();
                initFissionMarketTicker();
                // 鐧诲綍椤典笉鎷?jackpot锛涜繘鍏ュぇ鍘呭悗鍐?startJackpotSync()
            });
        }

        function newRequestId(prefix) {
            return prefix + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
        }

        function applyProfile(profile) {
            if (!profile) return;
            lastProfile = profile;
            const p2 = profile.phase2 || {};
            account.balance = parseFloat(profile.balance) || 0;
            account.rights = parseFloat(profile.rights) || 0;
            account.rights_locked = parseFloat(profile.rights_locked) || 0;
            account.rights_free = profile.rights_free != null
                ? (parseFloat(profile.rights_free) || 0)
                : Math.max(0, account.rights - account.rights_locked);
            account.hongbao = parseFloat(profile.hongbao) || 0;
            account.phone = profile.mobile || account.phone;
            account.main_uid = profile.main_uid || '';
            account.main_uid_pending = profile.main_uid_pending || '';
            account.main_uid_audit = profile.main_uid_audit || '';
            account.main_uid_reject_reason = profile.main_uid_reject_reason || '';
            flowStage = profile.flow_stage || flowStage;
            if (profile.mobile_mask) {
                document.getElementById('displayBindPhone').innerText = profile.mobile_mask;
            }
            const uidInput = document.getElementById('mainStationID');
            if (uidInput) {
                const displayUid = account.main_uid || account.main_uid_pending || '';
                if (displayUid) {
                    uidInput.value = displayUid;
                }
            }
            if (profile.invite_rank) {
                const rank = profile.invite_rank;
                const rankNode = document.getElementById('userRank');
                if (rankNode) {
                    rankNode.classList.remove('master-tag');
                    if (p2.enabled && p2.user_mode === 'master') {
                        rankNode.innerText = fc('user_rank_master', {
                            count: (p2.total_register_count || rank.invite_count || 0)
                        }) || ('👑 荣誉团长 · 已邀' + (p2.total_register_count || rank.invite_count || 0) + '人');
                        rankNode.classList.add('master-tag');
                    } else if (rank.invite_count > 0) {
                        rankNode.innerText = fc('user_rank_template', { rank: rank.rank, count: rank.invite_count });
                    } else {
                        rankNode.innerText = fc('user_rank_default');
                    }
                }
            }
            const statusTag = document.getElementById('userStatusTag');
            if (statusTag) {
                statusTag.textContent = fc('user_status_shield') || '瀵嗗寵闃叉姢宸插紑鍚';
            }
            syncUidLockUI(profile);
            renderUI();
            updateFlowUI();
            updateManualSettleButton();
            updatePhase2UI(profile);
            renderProfilePanel(profile);
            if (typeof refreshProfileExchangeUi === 'function') refreshProfileExchangeUi();
        }

        function resolveAvatarUrl(profile) {
            if (!profile) return '';
            return profile.avatar_url || profile.avatar || '';
        }

        function renderProfilePanel(profile) {
            profile = profile || lastProfile;
            if (!profile) return;
            const uidEl = document.getElementById('profileUserId');
            const mobileEl = document.getElementById('profileMobileMask');
            const nickEl = document.getElementById('profileNicknameInput');
            const nameEl = document.getElementById('profileDisplayName');
            const av = resolveAvatarUrl(profile);
            const nick = (profile.nickname || '').trim();
            const letter = (nick || profile.mobile_mask || 'U').charAt(0);
            if (uidEl) uidEl.textContent = profile.user_id || '-';
            if (mobileEl) mobileEl.textContent = profile.mobile_mask || profile.mobile || '';
            if (nameEl) nameEl.textContent = nick || (profile.mobile_mask || ('ID' + (profile.user_id || '')));
            if (nickEl && document.activeElement !== nickEl) {
                nickEl.value = nick;
            }
            function paintAvatar(imgId, fallbackId) {
                const img = document.getElementById(imgId);
                const fallback = document.getElementById(fallbackId);
                if (!img || !fallback) return;
                if (av) {
                    img.src = av;
                    img.style.display = '';
                    fallback.style.display = 'none';
                } else {
                    img.removeAttribute('src');
                    img.style.display = 'none';
                    fallback.style.display = 'flex';
                    fallback.textContent = letter;
                }
            }
            paintAvatar('profileAvatarImg', 'profileAvatarFallback');
            paintAvatar('profileEditAvatarImg', 'profileEditAvatarFallback');
        }

        function openProfileSubPage(which) {
            if (which === 'exchange') {
                closeProfileSubPage();
                if (typeof switchTab === 'function') switchTab('exchange');
                return;
            }
            if (which === 'recharge' || which === 'withdraw' || which === 'ledger' || which === 'payee') {
                var openWallet = function () {
                    if (typeof window.openProfileWalletPage === 'function') {
                        window.openProfileWalletPage(which);
                        return;
                    }
                    showFanshubToast('钱包模块加载失败', 'error');
                };
                if (window.FansHubAssets && typeof FansHubAssets.ensureWallet === 'function') {
                    FansHubAssets.ensureWallet().then(openWallet).catch(function (e) {
                        console.warn('wallet load fail', e);
                        showFanshubToast((e && e.message) || '钱包模块加载失败', 'error');
                    });
                    return;
                }
                openWallet();
                return;
            }
            closeProfileSubPage();
            const id = which === 'password' ? 'profilePasswordPane'
                : (which === 'qrcode' ? 'profileQrPane' : 'profileInfoPane');
            const pane = document.getElementById(id);
            if (!pane) return;
            renderProfilePanel();
            if (which === 'password') setProfilePwdMode(profilePwdMode || 'old');
            pane.classList.add('open');
            pane.setAttribute('aria-hidden', 'false');
            if (typeof setBottomActionBarVisible === 'function') setBottomActionBarVisible(false);
            if (which === 'qrcode' && window.FansHubFriendQr && typeof FansHubFriendQr.renderMyQr === 'function') {
                FansHubFriendQr.renderMyQr();
            }
        }

        function closeProfileSubPage() {
            ['profileInfoPane', 'profilePasswordPane', 'profileRechargePane', 'profileWithdrawPane', 'profileLedgerPane', 'profilePayeePane', 'profileQrPane'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) {
                    el.classList.remove('open');
                    el.setAttribute('aria-hidden', 'true');
                }
            });
            if (currentTab === 'profile' && typeof setBottomActionBarVisible === 'function') {
                setBottomActionBarVisible(true);
            }
        }

        function syncExchangeGateUi() {
            const minR2b = Math.max(1, parseInt(CONFIG.EXCHANGE_R2B_MIN, 10) || 1);
            const r2bOn = CONFIG.EXCHANGE_R2B_ENABLED !== false;
            const banner = document.getElementById('exchangeClosedBanner');
            const card = document.getElementById('exchangeMainCard');
            const cta = document.querySelector('#tabExchange .cta-section');
            const btn = document.getElementById('btnGoldenMain');
            if (banner) banner.style.display = r2bOn ? 'none' : 'block';
            if (card) card.style.display = r2bOn ? '' : 'none';
            if (cta) cta.style.display = r2bOn ? '' : 'none';
            if (btn) btn.disabled = !r2bOn || !(account && (typeof freeRights === 'function' ? freeRights() : account.rights) > 0);
            const ticket = document.getElementById('ticketCount');
            if (ticket) {
                let v = parseInt(ticket.value, 10) || minR2b;
                if (v < minR2b) ticket.value = String(minR2b);
            }
            if (typeof refreshProfileExchangeUi === 'function') refreshProfileExchangeUi();
        }

        let shareSwapFrom = 'rights';
        let shareSwapTo = 'balance';
        const SHARE_SWAP_ASSETS = ['rights', 'balance', 'hongbao'];
        const SHARE_SWAP_DEFAULT_MAX = 99999;

        function shareSwapAssetLabel(asset) {
            if (asset === 'balance') return fc('swap_asset_balance') || fc('asset_balance_label') || '红利';
            if (asset === 'hongbao') return fc('swap_asset_hongbao') || fc('asset_hongbao_label') || '红宝';
            return fc('swap_asset_rights') || fc('asset_shares_label') || '股份';
        }

        function shareSwapAssetIcon(asset) {
            if (asset === 'balance') return fc('swap_unit_balance') || '¥';
            if (asset === 'hongbao') return fc('swap_unit_hongbao') || '宝';
            return fc('swap_unit_share') || '股';
        }

        function shareSwapIsBhPair(from, to) {
            return (from === 'balance' && to === 'hongbao') || (from === 'hongbao' && to === 'balance');
        }

        function shareSwapPairInfo(from, to) {
            const key = from + '_' + to;
            const pairs = CONFIG.EXCHANGE_PAIRS || {};
            if (pairs[key]) {
                const p = pairs[key];
                return {
                    enabled: p.enabled !== false,
                    min: Number(p.min) || (shareSwapIsBhPair(from, to) ? 50 : 1),
                    max: Number(p.max) || SHARE_SWAP_DEFAULT_MAX
                };
            }
            // 兼容旧配置：仅红利↔红宝保留最低额，其余上限 99999
            if (shareSwapIsBhPair(from, to)) {
                return { enabled: true, min: 50, max: SHARE_SWAP_DEFAULT_MAX };
            }
            if (from === 'rights' && to === 'balance') {
                return { enabled: CONFIG.EXCHANGE_R2B_ENABLED !== false, min: CONFIG.EXCHANGE_R2B_MIN || 1, max: SHARE_SWAP_DEFAULT_MAX };
            }
            if (from === 'balance' && to === 'rights') {
                return { enabled: CONFIG.EXCHANGE_B2R_ENABLED !== false, min: CONFIG.EXCHANGE_B2R_MIN || 1, max: SHARE_SWAP_DEFAULT_MAX };
            }
            return { enabled: true, min: 1, max: Number(CONFIG.EXCHANGE_MAX) || SHARE_SWAP_DEFAULT_MAX };
        }

        /** 上方转出选定后，下方允许的兑换目标 */
        function shareSwapAllowedToList(from) {
            return SHARE_SWAP_ASSETS.filter(function (to) {
                if (to === from) return false;
                return shareSwapPairInfo(from, to).enabled;
            });
        }

        function shareSwapUnitValue(asset) {
            if (asset === 'balance') return 1;
            if (asset === 'hongbao') return Math.max(0.0001, Number(CONFIG.HONGBAO_UNIT_VALUE) || 1);
            return Math.max(0.0001, (typeof getSharePrice === 'function') ? getSharePrice() : (CONFIG.SINGLE_TICKET_VALUE || 5));
        }

        function shareSwapAvail(asset) {
            if (asset === 'balance') return Number(account.balance || 0);
            if (asset === 'hongbao') return Number(account.hongbao || 0);
            // 股份兑出仅可用可兑份额（分享送股等）；当日兑入锁定至 T+1
            if (typeof freeRights === 'function') return freeRights();
            return Math.max(0, Number(account.rights_free != null ? account.rights_free : ((account.rights || 0) - (account.rights_locked || 0))));
        }

        function shareSwapInsufficientMsg(avail) {
            const label = shareSwapAssetLabel(shareSwapFrom);
            if (shareSwapFrom === 'balance') {
                const currency = (window.FanshubI18n && FanshubI18n.currencySymbol()) || '￥';
                return fc('alert_exchange_insufficient', { avail: currency + Number(avail).toFixed(2) })
                    || ('数量不足，当前可用红利 ' + currency + Number(avail).toFixed(2));
            }
            if (shareSwapFrom === 'hongbao') {
                return fc('alert_exchange_insufficient', { avail: Number(avail).toFixed(2) })
                    || ('数量不足，当前可用红宝 ' + Number(avail).toFixed(2));
            }
            return fc('alert_exchange_insufficient', { avail: Math.floor(avail) })
                || ('数量不足，当前可兑股份 ' + Math.floor(avail) + ' 份');
        }

        function setProfileExchangeMode(mode) {
            // 兼容旧入口：r2b / b2r
            if (mode === 'b2r') {
                shareSwapFrom = 'balance';
                shareSwapTo = 'rights';
            } else if (mode === 'r2b') {
                shareSwapFrom = 'rights';
                shareSwapTo = 'balance';
            }
            refreshProfileExchangeUi();
        }

        function onShareSwapFromChange(value) {
            const next = (value === 'balance' || value === 'hongbao') ? value : 'rights';
            shareSwapFrom = next;
            // 转出不限制；仅按转出约束下方目标
            const allowed = shareSwapAllowedToList(shareSwapFrom);
            if (allowed.indexOf(shareSwapTo) < 0) {
                shareSwapTo = allowed[0] || (shareSwapFrom === 'rights' ? 'balance' : 'rights');
            }
            refreshProfileExchangeUi();
        }

        function onShareSwapToChange(value) {
            const next = (value === 'balance' || value === 'hongbao') ? value : 'rights';
            const allowed = shareSwapAllowedToList(shareSwapFrom);
            if (allowed.indexOf(next) < 0) {
                showFanshubToast(fc('alert_exchange_pair_invalid') || '当前转出资产不支持该兑换目标', 'error');
                refreshProfileExchangeUi({ preserveAmount: true });
                return;
            }
            shareSwapTo = next;
            refreshProfileExchangeUi();
        }

        function flipShareSwapDirection() {
            const tmp = shareSwapFrom;
            const nextFrom = shareSwapTo;
            const allowed = shareSwapAllowedToList(nextFrom);
            if (allowed.indexOf(tmp) < 0) {
                showFanshubToast(fc('alert_exchange_pair_invalid') || '当前方向无法互换', 'error');
                return;
            }
            shareSwapFrom = nextFrom;
            shareSwapTo = tmp;
            refreshProfileExchangeUi();
        }

        function formatShareSwapTime() {
            const d = new Date();
            const pad = function (n) { return String(n).padStart(2, '0'); };
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' +
                pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        }

        function fillDualExchangeAll() {
            const el = document.getElementById('shareSwapAmount');
            if (!el) return;
            const pair = shareSwapPairInfo(shareSwapFrom, shareSwapTo);
            const max = Math.max(1, Number(pair.max) || SHARE_SWAP_DEFAULT_MAX);
            const avail = Math.min(shareSwapAvail(shareSwapFrom), max);
            el.value = shareSwapFrom === 'rights'
                ? String(Math.max(0, Math.floor(avail)))
                : Number(avail).toFixed(2);
            refreshProfileExchangeUi({ preserveAmount: true });
        }

        function refreshProfileExchangeUi(opts) {
            opts = opts || {};
            // 输入过程中禁止强制回填，否则无法改写数量
            const preserveAmount = !!opts.preserveAmount;
            const currency = (window.FanshubI18n && FanshubI18n.currencySymbol()) || '￥';
            if (SHARE_SWAP_ASSETS.indexOf(shareSwapFrom) < 0) shareSwapFrom = 'rights';
            let allowedTo = shareSwapAllowedToList(shareSwapFrom);
            if (!allowedTo.length) {
                // 兜底：找任一可用转出
                for (let i = 0; i < SHARE_SWAP_ASSETS.length; i++) {
                    const list = shareSwapAllowedToList(SHARE_SWAP_ASSETS[i]);
                    if (list.length) {
                        shareSwapFrom = SHARE_SWAP_ASSETS[i];
                        allowedTo = list;
                        break;
                    }
                }
            }
            if (allowedTo.indexOf(shareSwapTo) < 0) {
                shareSwapTo = allowedTo[0] || 'balance';
            }

            const pair = shareSwapPairInfo(shareSwapFrom, shareSwapTo);
            const anyEnabled = SHARE_SWAP_ASSETS.some(function (f) {
                return shareSwapAllowedToList(f).length > 0;
            });
            const dualSec = document.getElementById('dualExchangeSection');
            if (dualSec) dualSec.style.display = anyEnabled ? '' : 'none';

            const fromSel = document.getElementById('shareSwapFromSelect');
            const toSel = document.getElementById('shareSwapToSelect');
            if (fromSel) {
                fromSel.value = shareSwapFrom;
                // 转出不做限制：全部可选
                Array.prototype.forEach.call(fromSel.options, function (opt) {
                    opt.disabled = false;
                });
            }
            if (toSel) {
                toSel.value = shareSwapTo;
                // 仅限制下方兑换目标（跟转出相同 / 通道关闭的不可选）
                Array.prototype.forEach.call(toSel.options, function (opt) {
                    opt.disabled = allowedTo.indexOf(opt.value) < 0;
                });
            }

            const fromIcon = document.getElementById('shareSwapFromIcon');
            const toIcon = document.getElementById('shareSwapToIcon');
            if (fromIcon) fromIcon.textContent = shareSwapAssetIcon(shareSwapFrom);
            if (toIcon) toIcon.textContent = shareSwapAssetIcon(shareSwapTo);

            const titleEl = document.getElementById('shareSwapTitle');
            if (titleEl) {
                titleEl.textContent = fc('swap_title_pair', {
                    from: shareSwapAssetLabel(shareSwapFrom),
                    to: shareSwapAssetLabel(shareSwapTo)
                }) || (shareSwapAssetLabel(shareSwapFrom) + '兑换' + shareSwapAssetLabel(shareSwapTo));
            }
            const fromLabel = document.getElementById('shareSwapFromLabel');
            if (fromLabel) {
                fromLabel.textContent = fc('swap_from_with_asset', {
                    asset: shareSwapAssetLabel(shareSwapFrom)
                }) || ('转出' + shareSwapAssetLabel(shareSwapFrom));
            }

            const avail = shareSwapAvail(shareSwapFrom);
            const availEl = document.getElementById('shareSwapAvail');
            if (availEl) {
                if (shareSwapFrom === 'balance') {
                    availEl.textContent = fc('swap_avail_balance', { amount: currency + avail.toFixed(2) })
                        || ('可用 ' + currency + avail.toFixed(2));
                } else if (shareSwapFrom === 'hongbao') {
                    availEl.textContent = fc('swap_avail_hongbao', { amount: avail.toFixed(2) })
                        || ('可用 ' + avail.toFixed(2) + ' 红宝');
                } else {
                    const locked = Math.max(0, Number(account.rights_locked) || 0);
                    if (locked > 0) {
                        availEl.textContent = fc('share_swap_rights_locked_hint', {
                            free: Math.floor(avail),
                            locked: Math.ceil(locked)
                        }) || ('可兑 ' + Math.floor(avail) + ' 份（锁定 ' + Math.ceil(locked) + ' 份，次日可兑）');
                    } else {
                        availEl.textContent = fc('swap_avail_rights', { amount: Math.floor(avail) })
                            || ('可用 ' + Math.floor(avail) + ' 份');
                    }
                }
            }

            const min = Math.max(1, Number(pair.min) || 1);
            const max = Math.max(min, Number(pair.max) || SHARE_SWAP_DEFAULT_MAX);
            const amountEl = document.getElementById('shareSwapAmount');
            if (amountEl) {
                amountEl.min = shareSwapIsBhPair(shareSwapFrom, shareSwapTo) ? String(min) : '0';
                amountEl.max = String(max);
                amountEl.step = shareSwapFrom === 'rights' ? '1' : '0.01';
                amountEl.inputMode = shareSwapFrom === 'rights' ? 'numeric' : 'decimal';
                if (!preserveAmount && (!amountEl.value || amountEl.value === '')) {
                    amountEl.value = shareSwapIsBhPair(shareSwapFrom, shareSwapTo) ? String(min) : '1';
                }
                amountEl.disabled = !pair.enabled;
                if (!amountEl._shareSwapBound) {
                    amountEl._shareSwapBound = true;
                    amountEl.addEventListener('input', function () {
                        refreshProfileExchangeUi({ preserveAmount: true });
                    });
                    amountEl.addEventListener('blur', function () {
                        const raw = parseFloat(amountEl.value);
                        if (!amountEl.value || isNaN(raw) || raw <= 0) {
                            amountEl.value = shareSwapIsBhPair(shareSwapFrom, shareSwapTo) ? String(min) : '1';
                            refreshProfileExchangeUi({ preserveAmount: true });
                            return;
                        }
                        if (shareSwapIsBhPair(shareSwapFrom, shareSwapTo) && raw < min) {
                            amountEl.value = String(min);
                            refreshProfileExchangeUi({ preserveAmount: true });
                            return;
                        }
                        if (raw > max) {
                            amountEl.value = shareSwapFrom === 'rights' ? String(Math.floor(max)) : String(max);
                            refreshProfileExchangeUi({ preserveAmount: true });
                        }
                    });
                }
            }
            const hintEl = document.getElementById('shareSwapHint');
            if (hintEl) {
                if (shareSwapIsBhPair(shareSwapFrom, shareSwapTo)) {
                    hintEl.textContent = fc('profile_ex_min_max_hint', { min: min, max: max })
                        || fc('profile_ex_min_hint', { min: min })
                        || ('单次最低 ' + min + '，上限 ' + max);
                } else {
                    hintEl.textContent = fc('profile_ex_max_hint', { max: max }) || ('单笔上限 ' + max);
                }
            }

            const fromUnit = shareSwapUnitValue(shareSwapFrom);
            const toUnit = shareSwapUnitValue(shareSwapTo);
            const amt = Math.max(0, parseFloat((amountEl && amountEl.value) || 0) || 0);
            const credit = toUnit > 0 ? (amt * fromUnit / toUnit) : 0;

            const rateEl = document.getElementById('shareSwapRate');
            if (rateEl) {
                rateEl.textContent = fc('swap_rate_line', {
                    from: shareSwapAssetLabel(shareSwapFrom),
                    to: shareSwapAssetLabel(shareSwapTo),
                    rate: (fromUnit / toUnit).toFixed(4)
                }) || ('1 ' + shareSwapAssetLabel(shareSwapFrom) + ' = ' +
                    (fromUnit / toUnit).toFixed(4) + ' ' + shareSwapAssetLabel(shareSwapTo));
            }
            const curEl = document.getElementById('shareSwapCurrency');
            if (curEl) curEl.textContent = 'CNY';
            const timeEl = document.getElementById('shareSwapTime');
            if (timeEl) timeEl.textContent = formatShareSwapTime();
            const estEl = document.getElementById('shareSwapEst');
            const estAsset = document.getElementById('shareSwapEstAsset');
            if (estAsset) estAsset.textContent = shareSwapAssetLabel(shareSwapTo);
            if (estEl) {
                if (shareSwapTo === 'balance') estEl.textContent = currency + credit.toFixed(2);
                else if (shareSwapTo === 'hongbao') estEl.textContent = credit.toFixed(2);
                else estEl.textContent = fc('swap_est_shares', { amount: credit.toFixed(2) })
                    || (credit.toFixed(2) + ' 份');
            }

            const submitBtn = document.getElementById('shareSwapSubmitBtn');
            if (submitBtn) {
                submitBtn.disabled = !pair.enabled;
                submitBtn.textContent = fc('swap_submit') || '确认兑换';
            }
            const closedEl = document.getElementById('shareSwapClosed');
            if (closedEl) {
                if (pair.enabled) {
                    closedEl.style.display = 'none';
                } else {
                    closedEl.style.display = 'block';
                    closedEl.textContent = fc('swap_pair_closed', {
                        from: shareSwapAssetLabel(shareSwapFrom),
                        to: shareSwapAssetLabel(shareSwapTo)
                    }) || (shareSwapAssetLabel(shareSwapFrom) + '兑换' + shareSwapAssetLabel(shareSwapTo) + '已关闭');
                }
            }

            // 闪兑页资产回显
            const hbEcho = document.getElementById('exchangeHongbaoEcho');
            if (hbEcho) hbEcho.textContent = Number(account.hongbao || 0).toFixed(2);
        }

        async function submitShareSwap() {
            const pair = shareSwapPairInfo(shareSwapFrom, shareSwapTo);
            if (!pair.enabled) {
                showFanshubToast(fc('alert_exchange_disabled') || '兑换功能已关闭', 'error');
                return;
            }
            const min = Math.max(1, Number(pair.min) || 1);
            const max = Math.max(min, Number(pair.max) || SHARE_SWAP_DEFAULT_MAX);
            const amountEl = document.getElementById('shareSwapAmount');
            let amount = parseFloat((amountEl && amountEl.value) || 0) || 0;
            if (shareSwapFrom === 'rights') amount = parseInt(amount, 10) || 0;
            if (amount <= 0) {
                showFanshubToast(fc('alert_exchange_amount_invalid') || '请输入转出数量', 'error');
                return;
            }
            if (shareSwapIsBhPair(shareSwapFrom, shareSwapTo) && amount < min) {
                showFanshubToast(fc('alert_exchange_min', { min: min }) || ('单次最少兑换 ' + min), 'error');
                return;
            }
            if (amount > max + 1e-8) {
                showFanshubToast(fc('alert_exchange_max', { max: max }) || ('单次最高兑换 ' + max), 'error');
                return;
            }
            const avail = shareSwapAvail(shareSwapFrom);
            if (amount > avail + 1e-8) {
                showFanshubToast(shareSwapInsufficientMsg(avail), 'error');
                return;
            }
            const btn = document.getElementById('shareSwapSubmitBtn');
            if (btn) btn.disabled = true;
            try {
                const profile = await apiRequest('exchangeswap', 'POST', {
                    from: shareSwapFrom,
                    to: shareSwapTo,
                    amount: amount,
                    channel: 'swap',
                    request_id: newRequestId('exs')
                });
                applyProfile(profile);
                refreshProfileExchangeUi();
                if (typeof updateDashboard === 'function') updateDashboard();
                showFanshubToast(fc('alert_exchange_swap_ok') || '🎉 兑换成功', 'success');
            } catch (e) {
                showFanshubToast(e.message || fc('alert_exchange_fail') || '兑换失败', 'error');
            } finally {
                if (btn) btn.disabled = !pair.enabled;
            }
        }

        // 兼容旧函数名（闪兑页仍可能引用）
        async function submitProfileExchangeR2b() {
            shareSwapFrom = 'rights';
            shareSwapTo = 'balance';
            const src = document.getElementById('shareSwapAmount');
            if (src) src.value = String(parseInt((document.getElementById('ticketCount') || {}).value, 10) || src.value);
            return submitShareSwap();
        }
        async function submitProfileExchangeB2r() {
            shareSwapFrom = 'balance';
            shareSwapTo = 'rights';
            return submitShareSwap();
        }

        let profilePwdMode = 'old';
        let profileSmsTimer = null;

        function setProfilePwdMode(mode) {
            profilePwdMode = mode === 'sms' ? 'sms' : 'old';
            const oldBtn = document.getElementById('profilePwdModeOld');
            const smsBtn = document.getElementById('profilePwdModeSms');
            const oldWrap = document.getElementById('profileOldPwdWrap');
            const smsWrap = document.getElementById('profileSmsWrap');
            if (oldBtn) oldBtn.classList.toggle('active', profilePwdMode === 'old');
            if (smsBtn) smsBtn.classList.toggle('active', profilePwdMode === 'sms');
            if (oldWrap) oldWrap.style.display = profilePwdMode === 'old' ? '' : 'none';
            if (smsWrap) smsWrap.style.display = profilePwdMode === 'sms' ? '' : 'none';
        }

        function forceLogoutToLogin(toastMsg) {
            try { localStorage.removeItem('fans_hub_token'); } catch (e) {}
            closeProfileSubPage();
            if (window.FansHubChat && typeof FansHubChat.onLogout === 'function') {
                try { FansHubChat.onLogout(); } catch (e2) {}
            }
            stopJackpotTimers();
            setBottomActionBarVisible(false);
            const dash = document.getElementById('mainDashboardView');
            const login = document.getElementById('loginView');
            if (dash) dash.classList.remove('active');
            if (login) login.classList.add('active');
            if (toastMsg) showFanshubToast(toastMsg, 'success');
        }

        async function saveProfileNickname() {
            const nickEl = document.getElementById('profileNicknameInput');
            const nickname = nickEl ? String(nickEl.value || '').trim() : '';
            if (!nickname) {
                showFanshubToast(fc('alert_nickname_empty'), 'error');
                return;
            }
            try {
                const profile = await apiRequest('updateprofile', 'POST', { nickname: nickname });
                applyProfile(profile);
                showFanshubToast(fc('alert_profile_saved'), 'success');
                closeProfileSubPage();
            } catch (e) {
                showFanshubToast(e.message || fc('alert_api_request_fail'), 'error');
            }
        }

        async function uploadProfileAvatar(file) {
            if (!file) return;
            try {
                const data = await uploadFanshubFile('avatarupload', file);
                if (data && data.profile) applyProfile(data.profile);
                showFanshubToast(fc('alert_avatar_ok'), 'success');
            } catch (e) {
                showFanshubToast(e.message || fc('alert_api_request_fail'), 'error');
            }
        }

        function startProfileSmsCooldown(seconds) {
            const btn = document.getElementById('profileSmsSendBtn');
            if (!btn) return;
            const total = Math.max(1, parseInt(seconds, 10) || CONFIG.SMS_SEND_INTERVAL || 60);
            btn.disabled = true;
            if (profileSmsTimer) clearInterval(profileSmsTimer);
            let count = total;
            function tick() {
                if (count <= 0) {
                    clearInterval(profileSmsTimer);
                    profileSmsTimer = null;
                    btn.disabled = false;
                    btn.textContent = fc('profile_sms_send_btn') || fc('login_captcha_btn');
                    return;
                }
                btn.textContent = fc('login_captcha_resend', { count: count }) || (count + 's');
                count--;
            }
            tick();
            profileSmsTimer = setInterval(tick, 1000);
        }

        async function sendProfileSmsCode() {
            const phone = (lastProfile && lastProfile.mobile) || account.phone || '';
            if (!phone) {
                showFanshubToast(fc('alert_phone_invalid'), 'error');
                return;
            }
            const remain = getSmsCooldownRemain(phone);
            if (remain > 0) {
                startProfileSmsCooldown(remain);
                showFanshubToast(fc('api_sms_too_frequent_wait', { seconds: remain }), 'error');
                return;
            }
            async function doSend(sliderPayload) {
                try {
                    const payload = Object.assign({
                        mobile: phone,
                        country_code: window.FanshubI18n ? FanshubI18n.country : 'CN'
                    }, sliderPayload || {});
                    const data = await apiRequest('sendsms', 'POST', payload);
                    const interval = (data && data.retry_after) ? parseInt(data.retry_after, 10) : (CONFIG.SMS_SEND_INTERVAL || 60);
                    setSmsCooldownUntil(phone, interval);
                    startProfileSmsCooldown(interval);
                    const hint = (data && data.hint) ? data.hint : fc('alert_sms_hint_default');
                    showFanshubToast(fc('alert_sms_sent') + '\n' + hint, 'success');
                } catch (e) {
                    if (e.payload && e.payload.retry_after) {
                        startProfileSmsCooldown(e.payload.retry_after);
                    }
                    showFanshubToast(e.message || fc('alert_sms_fail'), 'error');
                }
            }
            if (CONFIG.SMS_SLIDER_ENABLED) {
                openSliderCaptcha(phone, function(sliderPayload) { doSend(sliderPayload); });
                return;
            }
            await doSend({});
        }

        async function submitProfilePassword() {
            const newPwd = (document.getElementById('profileNewPassword') || {}).value || '';
            const confirmPwd = (document.getElementById('profileConfirmPassword') || {}).value || '';
            if (String(newPwd).length < 6) {
                showFanshubToast(fc('alert_password_short'), 'error');
                return;
            }
            if (String(newPwd) !== String(confirmPwd)) {
                showFanshubToast(fc('alert_password_mismatch'), 'error');
                return;
            }
            const body = {
                mode: profilePwdMode,
                new_password: newPwd,
                confirm_password: confirmPwd
            };
            if (profilePwdMode === 'old') {
                body.old_password = (document.getElementById('profileOldPassword') || {}).value || '';
            } else {
                body.captcha = (document.getElementById('profileSmsCode') || {}).value || '';
            }
            try {
                await apiRequest('changepassword', 'POST', body);
                forceLogoutToLogin(fc('alert_password_ok'));
            } catch (e) {
                showFanshubToast(e.message || fc('alert_api_request_fail'), 'error');
            }
        }

        async function handleProfileLogout() {
            const tip = fc('profile_logout_confirm') || '纭畾閫€鍑哄綋鍓嶈处鍙凤紵';
            if (!window.confirm(tip)) return;
            try {
                await apiRequest('logout', 'POST', {});
            } catch (e) {
                // 鍗充娇鎺ュ彛澶辫触涔熸竻鏈湴鐧诲綍鎬?
            }
            forceLogoutToLogin(fc('alert_logout_ok'));
        }

        function bindProfileUi() {
            const oldBtn = document.getElementById('profilePwdModeOld');
            const smsBtn = document.getElementById('profilePwdModeSms');
            if (oldBtn && !oldBtn._bound) {
                oldBtn._bound = true;
                oldBtn.onclick = function() { setProfilePwdMode('old'); };
            }
            if (smsBtn && !smsBtn._bound) {
                smsBtn._bound = true;
                smsBtn.onclick = function() { setProfilePwdMode('sms'); };
            }
            const avatarInput = document.getElementById('profileAvatarInput');
            if (avatarInput && !avatarInput._bound) {
                avatarInput._bound = true;
                avatarInput.addEventListener('change', function() {
                    const file = avatarInput.files && avatarInput.files[0];
                    avatarInput.value = '';
                    if (file) uploadProfileAvatar(file);
                });
            }
            setProfilePwdMode('old');
            const copyUidBtn = document.getElementById('profileCopyUidBtn');
            if (copyUidBtn && !copyUidBtn._bound) {
                copyUidBtn._bound = true;
                copyUidBtn.onclick = async function() {
                    const uidEl = document.getElementById('profileUserId');
                    const text = uidEl ? String(uidEl.textContent || '').trim() : '';
                    if (!text || text === '-') {
                        showFanshubToast(fc('profile_uid_copy_empty') || '暂无会员ID', 'error');
                        return;
                    }
                    try {
                        if (typeof copyTextSilent === 'function') {
                            await copyTextSilent(text);
                        } else if (navigator.clipboard && navigator.clipboard.writeText) {
                            await navigator.clipboard.writeText(text);
                        } else {
                            const temp = document.createElement('input');
                            temp.value = text;
                            document.body.appendChild(temp);
                            temp.select();
                            document.execCommand('copy');
                            document.body.removeChild(temp);
                        }
                        showFanshubToast(fc('profile_uid_copied') || '会员ID已复制', 'success');
                    } catch (e) {
                        showFanshubToast(fc('alert_copy_fail') || '复制失败', 'error');
                    }
                };
            }
        }

        function updatePhase2UI(profile) {
            const p2 = (profile && profile.phase2) || {};
            phase2State = p2;
            const isMaster = p2.enabled && p2.user_mode === 'master';
            const newbiePromo = document.getElementById('newbiePromoBlock');
            const masterHonor = document.getElementById('masterHonorBlock');
            const newbieOpen = document.getElementById('newbieOpenPanel');
            const masterBlock = document.getElementById('masterPhase2Block');
            const lockCard = document.getElementById('masterLockCard');
            if (newbiePromo) newbiePromo.style.display = isMaster ? 'none' : '';
            // 天梯始终展示（未解锁也可预览全部等级）
            if (masterHonor) masterHonor.style.display = p2.enabled ? '' : 'none';
            if (newbieOpen) newbieOpen.style.display = isMaster ? 'none' : '';
            if (masterBlock) masterBlock.style.display = isMaster ? '' : 'none';
            if (lockCard) lockCard.style.display = (p2.enabled && !isMaster) ? '' : 'none';
            if (p2.enabled) {
                renderHonorLadder(p2.honor || {});
            }
            if (isMaster) {
                renderCheckinPanel(p2.checkin || {});
                if (currentTab === 'master') loadTeamRadar();
                syncBonusUnlockPoll(p2.checkin || {});
            } else {
                syncBonusUnlockPoll(null);
            }
            if (p2.events && p2.events.length) {
                showPhase2Events(p2.events);
            }
            syncClaimPageEcho();
        }

        let bonusUnlockPollTimer = null;
        function syncBonusUnlockPoll(checkin) {
            const needPoll = !!(checkin
                && checkin.checked_today
                && checkin.today_mode === 'violent'
                && !checkin.bonus_unlocked);
            bonusUnlockWanted = needPoll;
            if (!needPoll) {
                if (bonusUnlockPollTimer) {
                    clearInterval(bonusUnlockPollTimer);
                    bonusUnlockPollTimer = null;
                }
                return;
            }
            if (document.hidden) {
                return;
            }
            if (bonusUnlockPollTimer) return;
            bonusUnlockPollTimer = setInterval(async function() {
                if (document.hidden) return;
                if (!localStorage.getItem('fans_hub_token')) {
                    syncBonusUnlockPoll(null);
                    return;
                }
                try {
                    const profile = await apiRequest('profile', 'GET');
                    const next = (profile && profile.phase2 && profile.phase2.checkin) || {};
                    if (next.bonus_unlocked || !next.checked_today) {
                        applyProfile(profile);
                        return;
                    }
                    // 浠呭埛鏂扮鍒扮姸鎬侊紝閬垮厤鏃犱簨浠舵椂鏁撮〉閲嶅娓叉煋
                    if (profile && profile.phase2) {
                        phase2State = profile.phase2;
                        renderCheckinPanel(next);
                        if (profile.phase2.events && profile.phase2.events.length) {
                            applyProfile(profile);
                        }
                    }
                } catch (e) {}
            }, 20000);
        }

        function renderHonorLadder(honor) {
            const nodesEl = document.getElementById('honorLadderNodes');
            const fillEl = document.getElementById('honorLadderFill');
            const hintEl = document.getElementById('honorLadderHint');
            const countEl = document.getElementById('honorCurrentLabel');
            const pctEl = document.getElementById('honorProgressPct');
            if (!nodesEl || !hintEl) return;
            const nodes = honor.nodes || [];
            const unit = getSharePrice();
            const iconClass = {
                1: 'bronze', 2: 'silver', 3: 'diamond', 4: 'crown', 5: 'glory',
                bronze: 'bronze', silver: 'silver', diamond: 'diamond', crown: 'crown', glory: 'glory'
            };
            const subCount = parseInt(honor.sub_withdrawn_count, 10) || 0;
            const nextId = honor.next_tier ? (honor.next_tier.id || 0) : 0;
            const pct = Math.max(0, Math.min(100, parseFloat(honor.progress_percent) || 0));

            if (countEl) {
                countEl.textContent = fc('phase2_honor_sub_count', { count: subCount })
                    || ('已达标转化 ' + subCount + ' 人');
            }
            if (pctEl) pctEl.textContent = Math.round(pct) + '%';
            if (fillEl) fillEl.style.width = pct + '%';

            nodesEl.innerHTML = nodes.map(function(n) {
                const rightsNum = parseFloat(n.rights);
                const rightsText = (!isNaN(rightsNum) && n.rights != null && n.rights !== '')
                    ? String(n.rights).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '')
                    : '0';
                const rightsVal = (n.rights_val != null && n.rights_val !== '')
                    ? Math.round(parseFloat(n.rights_val) || 0)
                    : Math.round((isNaN(rightsNum) ? 0 : rightsNum) * unit);
                const bal = Math.round(parseFloat(n.balance) || 0);
                const name = fc('phase2_honor_name_' + n.id) || n.name || ('段位' + n.id);
                const people = n.threshold || 0;
                const ico = iconClass[n.icon] || iconClass[n.id] || 'bronze';
                const reached = !!n.reached;
                const isCurrent = !reached && nextId && String(n.id) === String(nextId);
                const stateClass = reached ? ' is-reached' : (isCurrent ? ' is-current' : ' is-locked');
                const badgeText = reached
                    ? (fc('phase2_honor_badge_done') || '已解锁')
                    : (isCurrent
                        ? (fc('phase2_honor_badge_next') || '冲刺中')
                        : (fc('phase2_honor_badge_lock') || '待解锁'));
                const cashEmpty = bal <= 0;
                const cashHot = bal > 0 && n.id >= 4;
                const iconSrc = 'img/honor/' + ico + '.svg';
                return '<div class="honor-tier honor-tier--' + ico + stateClass + '">'
                    + '<div class="honor-tier-main">'
                    +   '<img class="honor-tier-ico" src="' + iconSrc + '" alt="" width="44" height="44" loading="lazy">'
                    +   '<div class="honor-tier-info">'
                    +     '<div class="honor-tier-name">' + name + '</div>'
                    +     '<div class="honor-tier-req">' + (fc('phase2_honor_need_people', { n: people }) || ('达标需 ' + people + ' 人')) + '</div>'
                    +   '</div>'
                    +   '<div class="honor-tier-badge">' + badgeText + '</div>'
                    + '</div>'
                    + '<div class="honor-tier-rewards">'
                    +   '<div class="honor-reward' + (cashHot ? ' honor-reward--hot' : '') + '">'
                    +     '<em>' + (fc('phase2_honor_col_shares') || '解锁股份') + '</em>'
                    +     '<strong>' + rightsText + '股</strong>'
                    +     '<span>≈ ¥' + rightsVal + '</span>'
                    +   '</div>'
                    +   '<div class="honor-reward honor-reward--cash' + (cashEmpty ? ' is-empty' : '') + '">'
                    +     '<em>' + (fc('phase2_honor_col_cash') || '现金奖励') + '</em>'
                    +     '<strong>' + (cashEmpty ? (fc('phase2_honor_cash_none') || '暂无现金') : ('¥' + bal)) + '</strong>'
                    +   '</div>'
                    + '</div>'
                    + '</div>';
            }).join('');

            if (honor.capped) {
                let top = 0;
                if (nodes.length) {
                    const last = nodes[nodes.length - 1];
                    top = Math.round((parseFloat(last.rights) || 0) * unit + (parseFloat(last.balance) || 0));
                } else {
                    top = honor.top_pack_total || 0;
                }
                hintEl.textContent = fc('phase2_honor_capped', { pack_total: top })
                    || ('长期天梯已封顶 · 通关累计可打包最高 ¥' + top + ' · 点击复制密令继续带队');
            } else if (honor.next_tier) {
                const need = honor.next_tier.need != null
                    ? honor.next_tier.need
                    : (honor.next_tier.threshold - subCount);
                const nextName = fc('phase2_honor_name_' + (honor.next_tier.id || '')) || honor.next_tier.name || '';
                hintEl.textContent = fc('phase2_honor_progress_short', {
                    name: nextName,
                    need: Math.max(0, need)
                }) || ('距离解锁「' + nextName + '」还差 ' + Math.max(0, need) + ' 人，点击复制密令邀请好友');
            } else {
                hintEl.textContent = fc('phase2_honor_hint') || '点击本卡复制专属推广密令，带队冲榜';
            }
        }

        function renderCheckinPanel(checkin) {
            const btn = document.getElementById('btnCheckinMain');
            const tipEl = document.getElementById('checkinBtnTip');
            const toggle = document.getElementById('checkinViolentToggle');
            const pending = document.getElementById('checkinPendingBox');
            const streakLabel = document.getElementById('checkinStreakLabel');
            const streakFill = document.getElementById('checkinStreakFill');
            const streakFrozen = document.getElementById('checkinStreakFrozen');
            const ledger = document.getElementById('checkinLedgerHint');
            const toggleLabel = document.getElementById('checkinViolentLabel');
            const streak = (checkin && checkin.streak_day) || 0;
            const bonusAmt = (CONFIG.CHECKIN_VIOLENT_BONUS || 4).toFixed(2);
            if (toggleLabel) {
                toggleLabel.textContent = fc('phase2_checkin_toggle', { amount: (1 + parseFloat(bonusAmt)).toFixed(2) })
                    || ('激活【5倍暴力分享签到】（今日最高 ¥' + (1 + parseFloat(bonusAmt)).toFixed(2) + '）');
            }
            if (streakLabel) {
                var streakTxt = fc('phase2_checkin_streak', { streak: streak });
                if (!streakTxt || /终极账本|星火暴击：/.test(streakTxt)) {
                    streakTxt = '连续暴力打卡 ' + streak + '/7天';
                }
                streakLabel.textContent = streakTxt;
            }
            if (streakFill) streakFill.style.width = Math.min(100, Math.round(streak / 7 * 100)) + '%';
            if (ledger) {
                const jackpot = '175';
                const loss = '140';
                var line = fc('phase2_checkin_ledger_short', { jackpot: jackpot, loss: loss });
                if (!line) line = fc('phase2_checkin_ledger', { jackpot: jackpot, loss: loss, streak: streak });
                if (!line || /终极账本/.test(line)) {
                    line = '满7天5倍核爆总池，保底 <span class="hl">¥' + jackpot + '</span> 筹码秒提现<br>断签降级，直接损失 <span class="hl">¥' + loss + '</span>';
                }
                ledger.innerHTML = line;
            }
            if (streakFrozen) {
                if (phase2State.streak_frozen) {
                    streakFrozen.style.display = '';
                    const need = Math.max(0, 2 - (phase2State.today_invite_count || 0));
                    streakFrozen.textContent = need > 0
                        ? (fc('phase2_checkin_frozen', { need: need }) || ('⚠️ 断签冻结中：今日再拉 ' + need + ' 人注册可复活 175 元暴击资格'))
                        : (fc('phase2_checkin_revive_ready') || '🔥 今日已拉满 2 人，暴击资格将自动复活');
                } else {
                    streakFrozen.style.display = 'none';
                }
            }
            if (!btn) return;
            const violent = toggle ? toggle.checked : true;
            const tipDefault = '一键复制今日密令 · 锁定制胜7天全勤';
            if (checkin.checked_today) {
                btn.disabled = false;
                btn.classList.add('is-done');
                const mode = checkin.today_mode === 'violent'
                    ? (fc('phase2_checkin_mode_violent') || '暴力')
                    : (fc('phase2_checkin_mode_normal') || '普通');
                const doneText = fc('phase2_checkin_done_btn', { mode: mode })
                    || ('✓ 今日已签到(' + mode + ') · 再复制推广链接');
                btn.innerHTML = '<span class="btn-checkin-main-text">' + doneText + '</span>';
                if (tipEl) tipEl.textContent = tipDefault;
            } else {
                btn.disabled = false;
                btn.classList.remove('is-done');
                if (violent) {
                    btn.innerHTML = '<span class="btn-checkin-main-text">'
                        + (fc('phase2_checkin_violent_btn_short') || '立即执行【暴力分享签到】')
                        + '</span>';
                    if (tipEl) tipEl.textContent = fc('phase2_checkin_violent_tip') || tipDefault;
                } else {
                    btn.innerHTML = '<span class="btn-checkin-main-text">'
                        + (fc('phase2_checkin_normal_btn_short') || '仅执行【普通打卡】')
                        + '</span>';
                    if (tipEl) tipEl.textContent = fc('phase2_checkin_normal_tip') || '今日仅得 1 元 · 放弃 7 天 175 元大奖资格';
                }
            }
            if (pending) {
                if (checkin.checked_today && checkin.today_mode === 'violent' && !checkin.bonus_unlocked) {
                    pending.style.display = '';
                    pending.textContent = fc('phase2_checkin_pending', { amount: bonusAmt }) || ('⏳ 今日暴力对账中：￥' + bonusAmt + ' 元(等待散户新客注册中… ⏱)');
                } else if (checkin.checked_today && checkin.today_mode === 'violent' && checkin.bonus_unlocked) {
                    pending.style.display = '';
                    pending.textContent = fc('phase2_checkin_pending_ok', { amount: bonusAmt }) || ('✓ 对账成功，今日额外￥' + bonusAmt + ' 已全额到账！');
                } else {
                    pending.style.display = 'none';
                }
            }
        }

                function teamRadarDaySeed() {
            const d = new Date();
            return d.getFullYear() * 10000 + (d.getMonth() + 1) * 100 + d.getDate();
        }

        function teamRadarRng(seed) {
            let a = (seed >>> 0) || 1;
            return function () {
                a |= 0;
                a = (a + 0x6D2B79F5) | 0;
                let t = Math.imul(a ^ (a >>> 15), 1 | a);
                t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
                return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
            };
        }

        /** 虚拟战队雷达：多国脱敏号 + 接近门槛进度 */
        function buildVirtualTeamRadar(limit) {
            limit = Math.max(3, Math.min(12, limit || 8));
            const rnd = teamRadarRng(teamRadarDaySeed() ^ 0x7EADA1);
            const threshold = Number(CONFIG.WITHDRAW_THRESHOLD) || 50;
            const pools = [
                { dial: '+86', heads: ['130', '135', '136', '137', '138', '139', '150', '158', '186', '188'], tailLen: 4 },
                { dial: '+63', heads: ['905', '906', '915', '917', '918', '919', '920', '921'], tailLen: 4 },
                { dial: '+84', heads: ['90', '91', '93', '96', '97', '98', '32', '33'], tailLen: 4 },
                { dial: '+60', heads: ['10', '11', '12', '13', '16', '17', '18', '19'], tailLen: 4 },
                { dial: '+855', heads: ['10', '11', '12', '15', '16', '17', '70', '77'], tailLen: 3 },
                { dial: '+62', heads: ['812', '813', '815', '816', '817', '818', '821', '822'], tailLen: 4 }
            ];
            const fracs = [0.96, 0.88, 0.76, 0.64, 0.52, 0.41, 0.33, 1.0, 0.28, 0.22, 0.18, 0.12];
            const rows = [];
            for (let i = 0; i < limit; i++) {
                const pool = pools[Math.floor(rnd() * pools.length)];
                const head = pool.heads[Math.floor(rnd() * pool.heads.length)];
                let tail = '';
                for (let t = 0; t < pool.tailLen; t++) tail += String(Math.floor(rnd() * 10));
                const frac = fracs[i] != null ? fracs[i] : (0.2 + rnd() * 0.5);
                let bal = Math.round(threshold * frac * 100) / 100;
                if (frac >= 1) bal = threshold;
                else bal = Math.min(threshold - 0.5, Math.max(1, bal + Math.floor(rnd() * 3) - 1));
                const withdrawn = bal >= threshold;
                rows.push({
                    user_id: -(i + 1),
                    mobile_mask: pool.dial + ' ' + head + '****' + tail,
                    balance: bal,
                    threshold: threshold,
                    withdrawn: withdrawn,
                    virtual: true
                });
            }
            rows.sort(function (a, b) {
                if (a.withdrawn !== b.withdrawn) return a.withdrawn ? 1 : -1;
                return b.balance - a.balance;
            });
            return rows;
        }

        function renderTeamRadarList(box, list) {
            if (!box) return;
            box.classList.remove('is-scrolling');
            box.removeAttribute('data-dup');
            if (!list || !list.length) {
                box.innerHTML = '<div style="font-size:12px;color:rgba(255,255,255,0.75);">' + (fc('phase2_radar_empty') || '') + '</div>';
                return;
            }
            var rowsHtml = list.map(function (row) {
                const bal = Number(row.balance) || 0;
                const th = Number(row.threshold) || (Number(CONFIG.WITHDRAW_THRESHOLD) || 50);
                const prog = fc('phase2_radar_progress', {
                    balance: bal.toFixed(2),
                    threshold: th.toFixed(0)
                }) || ('Progress: ' + bal.toFixed(2) + ' / ' + th.toFixed(0));
                let right;
                if (row.withdrawn) {
                    right = '<span class="team-radar-done">' + (fc('phase2_radar_done') || 'Ready') + '</span>';
                } else if (row.virtual) {
                    right = '<button type="button" class="btn-urge" onclick="urgeVirtualTeammate()">' + (fc('phase2_radar_urge') || 'Nudge') + '</button>';
                } else {
                    right = '<button type="button" class="btn-urge" onclick="urgeTeammate(' + (row.user_id | 0) + ')">' + (fc('phase2_radar_urge') || 'Nudge') + '</button>';
                }
                return '<div class="team-radar-row"><div>\uD83D\uDCF1 ' + row.mobile_mask + '<br>' + prog + '</div>' + right + '</div>';
            }).join('');
            // 复制一份做无缝滚动
            box.innerHTML = rowsHtml + rowsHtml;
            box.setAttribute('data-dup', '1');
            // 内容够高才开滚
            requestAnimationFrame(function () {
                var viewport = document.getElementById('teamRadarViewport');
                if (!viewport) return;
                if (box.scrollHeight > viewport.clientHeight * 1.15) {
                    box.classList.add('is-scrolling');
                }
            });
        }

        async function loadTeamRadar() {
            const box = document.getElementById('teamRadarList');
            if (!box) return;
            let list = buildVirtualTeamRadar(8);
            try {
                const data = await apiRequest('teamradar', 'GET');
                const real = (data && data.list) || [];
                if (Array.isArray(real) && real.length) {
                    const map = {};
                    real.forEach(function (r) {
                        if (!r || !r.mobile_mask) return;
                        map[r.mobile_mask] = {
                            user_id: r.user_id,
                            mobile_mask: r.mobile_mask,
                            balance: Number(r.balance) || 0,
                            threshold: Number(r.threshold) || (Number(CONFIG.WITHDRAW_THRESHOLD) || 50),
                            withdrawn: !!r.withdrawn,
                            virtual: false
                        };
                    });
                    list.forEach(function (v) {
                        if (!map[v.mobile_mask]) map[v.mobile_mask] = v;
                    });
                    list = Object.keys(map).map(function (k) { return map[k]; });
                    list.sort(function (a, b) {
                        if (!!a.withdrawn !== !!b.withdrawn) return a.withdrawn ? 1 : -1;
                        return (b.balance || 0) - (a.balance || 0);
                    });
                    list = list.slice(0, 10);
                }
            } catch (e) {
                // keep virtual
            }
            renderTeamRadarList(box, list);
            window._lastTeamRadarList = list;
        }

        function refreshTeamRadarCopy() {
            const box = document.getElementById('teamRadarList');
            if (!box) return;
            if (window._lastTeamRadarList && window._lastTeamRadarList.length) {
                renderTeamRadarList(box, window._lastTeamRadarList);
                return;
            }
            if (typeof loadTeamRadar === 'function') loadTeamRadar();
        }
        window.refreshTeamRadarCopy = refreshTeamRadarCopy;

        async function urgeVirtualTeammate() {
            try {
                const link = (CONFIG && CONFIG.SHARE_LINK) || (location.origin + location.pathname);
                const text = fc('phase2_urge_copy', { link: link }) || link;
                if (typeof copyTextSilent === 'function') await copyTextSilent(text);
                else if (navigator.clipboard && navigator.clipboard.writeText) await navigator.clipboard.writeText(text);
                showFanshubToast(fc('phase2_toast_urge_ok') || 'Copied', 'success');
            } catch (e) {
                showFanshubToast(fc('phase2_toast_copy_fail') || 'Copy failed', 'error');
            }
        }
        window.urgeVirtualTeammate = urgeVirtualTeammate;
        window.loadTeamRadar = loadTeamRadar;

        async function urgeTeammate(userId) {
            try {
                const data = await apiRequest('urgecopy', 'POST', { invitee_user_id: userId });
                await copyTextSilent(data.text || '');
                showFanshubToast(fc('phase2_toast_urge_ok') || '鍌椿鏂囨宸插鍒讹紝鍘荤兢閲孈浠栵紒');
            } catch (e) {
                showFanshubToast(e.message || (fc('phase2_toast_copy_fail') || '澶嶅埗澶辫触'), 'error');
            }
        }

        function showPhase2Modal(title, body, actions, useHtml) {
            const mask = document.getElementById('phase2ModalMask');
            const bodyEl = document.getElementById('phase2ModalBody');
            document.getElementById('phase2ModalTitle').textContent = title || '';
            if (useHtml) {
                bodyEl.innerHTML = (body || '').replace(/\n/g, '<br>');
            } else {
                bodyEl.textContent = body || '';
            }
            const actEl = document.getElementById('phase2ModalActions');
            actEl.innerHTML = '';
            (actions || []).forEach(function(act) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'phase2-modal-btn ' + (act.style || 'primary');
                btn.textContent = act.label;
                btn.onclick = function() {
                    mask.classList.remove('show');
                    if (typeof act.onClick === 'function') act.onClick();
                };
                actEl.appendChild(btn);
            });
            mask.classList.add('show');
        }

        function closePhase2Modal() {
            const mask = document.getElementById('phase2ModalMask');
            if (mask) mask.classList.remove('show');
        }

        function showPhase2Events(events) {
            if (!events || !events.length) return;
            const ev = events[0];
            const useHtml = !!ev.html;
            if (ev.type === 'confirm_normal') {
                showPhase2Modal(ev.title, ev.message, [
                    { label: fc('phase2_btn_persist_1') || '坚持领 1 元（放弃大奖）', style: 'muted', onClick: function() { doCheckin(false, true); } },
                    { label: fc('phase2_btn_reselect_violent') || '閲嶉€夋毚鍔涘垎浜紙澶氭嬁5鍊嶏級', style: 'gold', onClick: function() {
                        const t = document.getElementById('checkinViolentToggle');
                        if (t) t.checked = true;
                        renderCheckinPanel(phase2State.checkin || {});
                        doCheckin(true, true);
                    }}
                ], useHtml);
                return;
            }
            const actions = [{ label: fc('phase2_btn_know') || '鐭ラ亾浜', style: 'primary', onClick: closePhase2Modal }];
            if (ev.type === 'day7_explosion') {
                actions.unshift({ label: fc('phase2_btn_day7_cash') , style: 'gold', onClick: function() {
                    closePhase2Modal();
                    var target = document.getElementById('shareSwapSubmitBtn') || document.getElementById('btnGoldenMain');
                    if (target && target.scrollIntoView) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }});
            } else if (ev.type === 'honor_tier') {
                if (ev.capped) {
                    actions.unshift({ label: fc('phase2_btn_honor_withdraw') , style: 'gold', onClick: function() {
                        closePhase2Modal();
                        openWithdrawModal();
                    }});
                } else {
                    actions.unshift({ label: fc('phase2_btn_honor_exchange') || '绔嬪嵆鍓嶅線姝ヨ繘鍣ㄥ彉鐜', style: 'gold', onClick: function() {
                        closePhase2Modal();
                        var target = document.getElementById('shareSwapSubmitBtn') || document.getElementById('btnGoldenMain');
                        if (target && target.scrollIntoView) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }});
                }
            } else if (ev.type === 'mode_master') {
                actions[0].label = fc('phase2_btn_enter_master') || '杩涘叆鍥㈤暱澶у巺';
            }
            showPhase2Modal(ev.title, ev.message, actions, useHtml);
            if (events.length > 1) {
                setTimeout(function() { showPhase2Events(events.slice(1)); }, 1400);
            }
        }

        async function copySharePromoOnly() {
            try {
                const data = await apiRequest('share', 'POST', { copy_only: 1 });
                await copyTextSilent(data.share_text || '');
                showFanshubToast(fc('phase2_toast_promo_ok') || '鎺ㄥ箍瀵嗕护宸插鍒讹紝鍘荤兢閲岀兢鍙戯紒');
            } catch (e) {
                showFanshubToast(e.message || (fc('phase2_toast_copy_fail') || '澶嶅埗澶辫触'), 'error');
            }
        }

        async function handleCheckinClick() {
            const checkin = (phase2State && phase2State.checkin) || {};
            if (checkin.checked_today) {
                await copySharePromoOnly();
                return;
            }
            const toggle = document.getElementById('checkinViolentToggle');
            const violent = toggle ? toggle.checked : true;
            await doCheckin(violent, false);
        }

        async function doCheckin(violent, confirmed) {
            try {
                const data = await apiRequest('checkin', 'POST', { violent: violent ? 1 : 0, confirmed: confirmed ? 1 : 0 });
                if (data.need_confirm) {
                    showPhase2Events(data.events || []);
                    return;
                }
                if (data.share && data.share.share_text) {
                    await copyTextSilent(data.share.share_text);
                }
                if (data.profile) applyProfile(data.profile);
                showPhase2Events(data.events || []);
                showFanshubToast(fc('phase2_toast_checkin_ok') || '绛惧埌鎴愬姛 路 鎺ㄥ箍瀵嗕护宸插鍒');
            } catch (e) {
                showFanshubToast(e.message || (fc('phase2_toast_checkin_fail') || '绛惧埌澶辫触'), 'error');
            }
        }

        const SKIN_STORAGE_KEY = 'fans_hub_skin';
        const SKINS = {
            default: {
                '--bg-main': '#F4F6F9',
                '--bg-card': '#FFFFFF',
                '--primary': '#00C853',
                '--secondary': '#FF9100',
                '--accent': '#0071FF',
                '--text-main': '#1A212D',
                '--text-muted': '#657786',
                '--danger': '#FF3B30'
            },
            a: {
                '--bg-main': '#FAFAFA',
                '--bg-card': '#FFFFFF',
                '--primary': '#E53935',
                '--secondary': '#FFB300',
                '--accent': '#D32F2F',
                '--text-main': '#212121',
                '--text-muted': '#757575',
                '--danger': '#FF3B30'
            },
            b: {
                '--bg-main': '#EDF2F7',
                '--bg-card': '#FFFFFF',
                '--primary': '#00C853',
                '--secondary': '#FF9100',
                '--accent': '#1A365D',
                '--text-main': '#2D3748',
                '--text-muted': '#718096',
                '--danger': '#FF3B30'
            },
            d: {
                '--bg-main': '#F6F7FB',
                '--bg-card': '#FFFFFF',
                '--primary': '#3F51B5',
                '--secondary': '#00BCD4',
                '--accent': '#1E3A8A',
                '--text-main': '#111827',
                '--text-muted': '#6B7280',
                '--danger': '#EF4444'
            }
        };

        function applySkin(skinId) {
            const effectiveId = SKINS[skinId] ? skinId : 'default';
            const skin = SKINS[effectiveId];
            Object.entries(skin).forEach(([key, value]) => {
                document.documentElement.style.setProperty(key, value);
            });
            const select = document.getElementById('skinSelect');
            if (select) select.value = effectiveId;

            const bar = document.getElementById('floatingTopBar');
            if (bar) {
                bar.classList.remove('skin-changed');
                void bar.offsetWidth;
                bar.classList.add('skin-changed');
                setTimeout(() => bar.classList.remove('skin-changed'), 420);
            }

            try { localStorage.setItem(SKIN_STORAGE_KEY, effectiveId); } catch (e) {}
        }

        (function initSkinSwitcher() {
            const savedSkin = (() => {
                try { return localStorage.getItem(SKIN_STORAGE_KEY); } catch (e) { return null; }
            })();
            const initialSkin = savedSkin && SKINS[savedSkin] ? savedSkin : 'default';
            applySkin(initialSkin);

            const select = document.getElementById('skinSelect');
            if (!select) return;
            select.value = initialSkin;
            select.addEventListener('change', () => {
                const nextId = select.value;
                if (!SKINS[nextId]) return;
                applySkin(nextId);
            });
        })();

        window.CONFIG = window.CONFIG || CONFIG;
        window.fc = fc;
        window.formatMoney = formatMoney;
        window.switchTab = switchTab;
        window.closeProfileSubPage = closeProfileSubPage;
        window.openProfileSubPage = openProfileSubPage;
        window.syncExchangeGateUi = syncExchangeGateUi;
        window.refreshProfileExchangeUi = refreshProfileExchangeUi;
        window.setProfileExchangeMode = setProfileExchangeMode;
        window.submitProfileExchangeR2b = submitProfileExchangeR2b;
        window.submitProfileExchangeB2r = submitProfileExchangeB2r;
        window.fillDualExchangeAll = fillDualExchangeAll;
        window.onShareSwapFromChange = onShareSwapFromChange;
        window.onShareSwapToChange = onShareSwapToChange;
        window.flipShareSwapDirection = flipShareSwapDirection;
        window.submitShareSwap = submitShareSwap;
        window.showFanshubToast = showFanshubToast;
        window.apiRequest = apiRequest;
        window.applyPageCopy = applyPageCopy;
        window.syncCopyFromLocale = syncCopyFromLocale;
        window.initI18nControls = initI18nControls;
        window.ensureServerConfig = ensureServerConfig;
        window.syncSmsCooldownFromStorage = syncSmsCooldownFromStorage;
        window.bindProfileUi = bindProfileUi;
        window.setBottomActionBarVisible = setBottomActionBarVisible;
        window.renderProfilePanel = renderProfilePanel;
        window.getSharePrice = getSharePrice;
        window.startJackpotSync = startJackpotSync;

