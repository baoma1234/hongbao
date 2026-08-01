/* js/app-boot.js — account state, DOM ready, onload, page actions */

        let account = { balance: 0.00, rights: 0, rights_locked: 0, rights_free: 0, hongbao: 0, phone: '', main_uid: '', main_uid_pending: '', main_uid_audit: '', main_uid_reject_reason: '' };

        function freeRights() {
            if (account.rights_free != null && account.rights_free !== '') {
                return Math.max(0, Number(account.rights_free) || 0);
            }
            return Math.max(0, (Number(account.rights) || 0) - (Number(account.rights_locked) || 0));
        }
        window.freeRights = freeRights;
        let lastProfile = null;
        let phase2State = { enabled: false, user_mode: 'newbie', checkin: {} };
        let flowStage = 'stage1';
        let currentSelectedTeam = '';
        let secretTimerInterval = null;
        let currentSecretCode = '';
        let isConverting = false;
        let commentPage = 1;
        let commentHasMore = true;
        let commentLoading = false;
        let commentLoadedCount = 0;
        const COMMENT_PAGE_SIZE = 20;

        (function bootstrapCopy() {
            if (!window.FanshubI18n) return;
            FanshubI18n.ensureLocaleLoaded().then(function() {
                syncCopyFromLocale(null);
                initI18nControls();
                applyPageCopy();
            }).catch(function(e) {
                console.warn('locale boot failed', e);
            });
        })();

        document.addEventListener('DOMContentLoaded', function() {
            ensureServerConfig().then(function() {
                syncSmsCooldownFromStorage();
            }).catch(function(e) {
                console.warn('棰勫姞杞介厤缃け璐', e);
            });
            bindProfileUi();
            const phoneInput = document.getElementById('loginPhone');
            if (phoneInput) {
                phoneInput.addEventListener('input', syncSmsCooldownFromStorage);
            }
            const shareCard = document.getElementById('sharePromoCard');
            if (shareCard) {
                shareCard.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        copyShareLink();
                    }
                });
            }
            const honorCard = document.getElementById('honorLadderCard');
            if (honorCard) {
                honorCard.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        copySharePromoOnly();
                    }
                });
            }
            const checkinToggle = document.getElementById('checkinViolentToggle');
            if (checkinToggle) {
                checkinToggle.addEventListener('change', function() {
                    renderCheckinPanel(phase2State.checkin || {});
                });
            }
            initFissionMarketTicker();
            const lotteryChest = document.getElementById('welcomeLotteryChest');
            if (lotteryChest) {
                lotteryChest.addEventListener('click', handleWelcomeLotteryChestClick);
                lotteryChest.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        handleWelcomeLotteryChestClick();
                    }
                });
            }
            const lotteryCloseBtn = document.getElementById('welcomeLotteryCloseBtn');
            if (lotteryCloseBtn) {
                lotteryCloseBtn.addEventListener('click', closeWelcomeLotteryModal);
            }
        });

        window.onload = async function() {
            try {
                if (window.FanshubI18n) {
                    await FanshubI18n.ensureLocaleLoaded();
                    syncCopyFromLocale(null);
                }
                // 蹇呴』鍏堟媺鍒?api_sign_*锛屽啀鍙戦渶绛惧悕鐨勬帴鍙ｏ紱鍚﹀垯鍒锋柊鏃?profile 鏃犵鍚嶈鎷掑苟璇竻 token
                await ensureServerConfig();
            } catch (e) { console.warn('鍔犺浇閰嶇疆澶辫触', e); }
            const token = localStorage.getItem('fans_hub_token');
            if (token) {
                try {
                    applyProfile(await restoreSessionProfile());
                    document.getElementById('loginView').classList.remove('active');
                    document.getElementById('mainDashboardView').classList.add('active');
                    setBottomActionBarVisible(true);
                    switchTab('home');
                    initMarqueeInterval();
                    startJackpotSync();
                    if (window.FansHubAssets && typeof FansHubAssets.prefetchChat === 'function') {
                        FansHubAssets.prefetchChat();
                    } else if (window.FansHubChat && typeof FansHubChat.onLogin === 'function') {
                        FansHubChat.onLogin();
                    }
                } catch (e) {
                    console.warn('鎭㈠鐧诲綍澶辫触', e);
                    if (shouldClearTokenOnProfileError(e)) {
                        localStorage.removeItem('fans_hub_token');
                        setBottomActionBarVisible(false);
                        stopJackpotTimers();
                        if (window.FansHubChat) FansHubChat.onLogout();
                    } else {
                        showFanshubToast(e.message || fc('api_operation_fail'), 'error');
                    }
                }
            }
            renderUI();
            updateFlowUI();
            updateManualSettleButton();
            syncSmsCooldownFromStorage();
            scheduleWelcomeLottery();
            restoreLotteryValuation();
        };

        document.addEventListener('visibilitychange', async function() {
            if (document.hidden) {
                pauseBackgroundTimers();
                return;
            }
            resumeBackgroundTimers();
            // 回到前台：确保 IM WebSocket 已连接，才能收红包/新消息提示
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
            if (sessionStorage.getItem('fans_hub_pending_open') === 'true') {
                sessionStorage.removeItem('fans_hub_pending_open');
                sessionStorage.setItem('fans_hub_pending_open_reward', 'true');
                updateFlowUI();
                await tryClaimOpenAccountReward(true);
            }
        });

        async function tryClaimOpenAccountReward(showAlert) {
            if (!localStorage.getItem('fans_hub_token')) return;
            const uid = (account.main_uid || '').trim();
            if (!uid || account.main_uid_audit !== 'approved') {
                updateFlowUI();
                if (showAlert) {
                    if ((account.main_uid_audit || '') === 'pending') {
                        showFanshubToast(fc('uid_hint_pending'), 'info');
                    } else {
                        showFanshubToast(fc('alert_uid_required'), 'info');
                    }
                }
                return;
            }
            try {
                applyProfile(await apiRequest('openaccount', 'POST', {}));
                sessionStorage.removeItem('fans_hub_pending_open_reward');
                updateFlowUI();
                renderUI();
                if (showAlert) {
                    showFanshubToast(fc('api_open_account_ok'), 'success');
                }
            } catch (e) {
                updateFlowUI();
                if (showAlert) {
                    showFanshubToast(e.message || fc('alert_open_reward_fail'), 'error');
                }
            }
        }

        function shouldShowUidSection() {
            // 娓告垙璐﹀彿鍥炲～鍖哄父椹诲睍绀猴紙鏍搁攢瀵硅处锛?
            return true;
        }

        function updateUidStatusHint(profile) {
            const hint = document.getElementById('uidStatusHint');
            if (!hint) return;
            const audit = (profile && profile.main_uid_audit) || account.main_uid_audit || '';
            const reason = (profile && profile.main_uid_reject_reason) || account.main_uid_reject_reason || '';
            hint.classList.remove('pending', 'approved', 'rejected');
            if (audit === 'pending') {
                hint.classList.add('pending');
                hint.textContent = fc('uid_hint_pending') || '姝ｅ湪瀹℃牳涓紝璇疯€愬績绛夊緟瀹㈡湇鍚庡彴鏍搁攢涓婂垎';
            } else if (audit === 'approved') {
                hint.classList.add('approved');
                hint.textContent = fc('uid_hint_approved') || '娓告垙璐﹀彿宸查€氳繃鏍搁攢锛岃处鍙峰凡閿佸畾';
            } else if (audit === 'rejected') {
                hint.classList.add('rejected');
                const base = fc('uid_hint_rejected') || '审核失败';
                let reasonText = '';
                if (reason) {
                    if (/^(srv_|uid_|api_|alert_)/.test(reason)) {
                        reasonText = fc(reason) || reason;
                    } else {
                        reasonText = reason;
                    }
                }
                hint.textContent = reasonText ? (base + '：' + reasonText) : base;
            } else {
                hint.textContent = fc('uid_hint_idle') || '璇峰～鍐欐父鎴忚处鍙凤紙鏁板瓧鎴栬嫳鏂囨暟瀛楃粍鍚堝潎鍙級锛屾瘡涓处鍙蜂粎鍙彁浜や竴娆″鏍';
            }
        }

        function syncUidLockUI(profile) {
            const input = document.getElementById('mainStationID');
            const btn = document.getElementById('btnUidSubmit');
            const audit = (profile && profile.main_uid_audit) || account.main_uid_audit || '';
            const locked = audit === 'pending' || audit === 'approved';

            if (input) {
                // 瀹℃牳涓?宸查€氳繃锛氶攣瀹氳緭鍏ワ紱澶辫触鍙敼鍙烽噸鎻?
                input.readOnly = locked;
                input.disabled = audit === 'approved';
                input.classList.toggle('is-locked', locked);
                if (locked) {
                    input.blur();
                }
            }
            if (btn) {
                if (audit === 'approved') {
                    btn.disabled = true;
                    btn.style.display = 'none';
                    btn.textContent = fc('uid_submit_approved') || '宸查€氳繃鏍搁攢';
                } else if (audit === 'pending') {
                    btn.disabled = true;
                    btn.style.display = '';
                    btn.textContent = fc('uid_submit_pending') || '姝ｅ湪瀹℃牳涓';
                } else {
                    btn.disabled = false;
                    btn.style.display = '';
                    btn.textContent = fc('uid_submit_btn') || '鎻愪氦璐﹀彿瀹℃牳';
                }
            }
            updateUidStatusHint(profile);
        }

        function updateFlowUI() {
            const uidSection = document.getElementById('uidSection');
            if (shouldShowUidSection()) {
                uidSection.classList.add('visible');
            } else {
                uidSection.classList.remove('visible');
            }
            syncUidLockUI();
            updateFlowStepper();
            const dash = document.getElementById('mainDashboardView');
            setBottomActionBarVisible(!!(dash && dash.classList.contains('active')));
        }

        function updateUidSubmitButton() {
            syncUidLockUI();
        }

        function sanitizeUidValue(raw) {
            return String(raw || '').replace(/[^A-Za-z0-9]/g, '').slice(0, 32);
        }

        // iOS 鍦?input 閲屾敼鍐?value 浼氶噸澶嶈緭鍏ワ紱鍙湪 composition 缁撴潫 / blur / 鎻愪氦鏃舵竻娲?
        function onUidInput(e) {
            const input = document.getElementById('mainStationID');
            if (!input || input.readOnly || input.disabled) return;
            if (e && (e.isComposing || e.keyCode === 229)) return;
            updateFlowStepper();
        }

        function onUidBlur() {
            const input = document.getElementById('mainStationID');
            if (!input || input.readOnly || input.disabled) return;
            const cleaned = sanitizeUidValue(input.value);
            if (input.value !== cleaned) input.value = cleaned;
            updateFlowStepper();
        }

        let uidSubmitting = false;
        async function submitUID() {
            const input = document.getElementById('mainStationID');
            const btn = document.getElementById('btnUidSubmit');
            if (!input || uidSubmitting) return;
            const audit = account.main_uid_audit || '';
            if (audit === 'approved') {
                showFanshubToast(fc('srv_uid_already_approved'), 'info');
                syncUidLockUI();
                return;
            }
            if (audit === 'pending') {
                showFanshubToast(fc('srv_uid_pending') || fc('uid_hint_pending'), 'info');
                syncUidLockUI();
                return;
            }
            const uid = sanitizeUidValue(input.value);
            input.value = uid;
            if (uid.length < 2) {
                showFanshubToast(fc('srv_uid_format_invalid') || fc('alert_uid_required'), 'error');
                return;
            }
            if (!localStorage.getItem('fans_hub_token')) {
                showFanshubToast(fc('api_operation_fail'), 'error');
                return;
            }
            uidSubmitting = true;
            if (btn) btn.disabled = true;
            try {
                const profile = await apiRequest('binduid', 'POST', { main_uid: uid });
                lastSubmittedUid = uid;
                applyProfile(profile);
                showFanshubToast(fc('api_bind_ok') || fc('uid_hint_pending'), 'success');
            } catch (e) {
                showFanshubToast(e.message || fc('api_operation_fail'), 'error');
                syncUidLockUI();
            } finally {
                uidSubmitting = false;
                syncUidLockUI();
            }
        }

        let lastSubmittedUid = '';

        (function bindUidInputEvents() {
            const input = document.getElementById('mainStationID');
            if (!input) return;
            input.addEventListener('input', onUidInput);
            input.addEventListener('compositionend', function() {
                // 杈撳叆娉曠粨鏉熷悗鍐嶆竻娲楋紝閬垮厤 iOS 閲嶅瀛楃
                if (input.readOnly || input.disabled) return;
                const cleaned = sanitizeUidValue(input.value);
                if (input.value !== cleaned) input.value = cleaned;
                updateFlowStepper();
            });
            input.addEventListener('blur', onUidBlur);
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    submitUID();
                }
            });
        })();

        function updateManualSettleButton() {
            const title = document.getElementById('manualSettleTitle');
            const sub = document.getElementById('manualSettleSub');
            if ((Number(account.hongbao) || 0) >= CONFIG.WITHDRAW_THRESHOLD) {
                title.innerText = fc('settle_title_high');
                sub.innerText = fc('settle_sub_high');
            } else {
                title.innerText = fc('settle_title_low');
                sub.innerText = fc('settle_sub_low');
            }
        }

        function startSecretCountdown() {
            if (secretTimerInterval) clearInterval(secretTimerInterval);
            let remaining = CONFIG.SECRET_LOCK_SECONDS;
            const el = document.getElementById('secretCountdown');
            function tick() {
                const m = Math.floor(remaining / 60);
                const s = remaining % 60;
                el.innerText = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
                if (remaining <= 0) {
                    clearInterval(secretTimerInterval);
                    el.innerText = fc('withdraw_secret_expired');
                    el.parentElement.style.color = '#999';
                }
                remaining--;
            }
            tick();
            secretTimerInterval = setInterval(tick, 1000);
        }

        let sliderCaptchaState = {
            challenge: null,
            dragX: 0,
            dragging: false,
            locking: false,
            completed: false,
            startTime: 0,
            maxX: 0,
            onSuccess: null,
            onCancel: null,
            phone: '',
            mode: 'sms' // sms | grab
        };

        function setSliderStatus(text, cls) {
            const el = document.getElementById('sliderStatusText');
            if (!el) return;
            el.className = 'slider-status' + (cls ? (' ' + cls) : '');
            el.innerText = text || '';
        }

        function closeSliderCaptcha(opts) {
            opts = opts || {};
            const modal = document.getElementById('sliderCaptchaModal');
            if (modal) modal.classList.remove('show');
            resetSliderThumb(false);
            sliderCaptchaState.dragging = false;
            sliderCaptchaState.locking = false;
            if (!opts.fromSuccess && !sliderCaptchaState.completed && typeof sliderCaptchaState.onCancel === 'function') {
                const cancel = sliderCaptchaState.onCancel;
                sliderCaptchaState.onCancel = null;
                try { cancel(); } catch (e) {}
            }
        }

        function applyThumbX(x, withAnim) {
            const thumb = document.getElementById('sliderThumb');
            const fill = document.getElementById('sliderTrackFill');
            const hint = document.getElementById('sliderTrackHint');
            if (!thumb) return;
            const maxX = sliderCaptchaState.maxX;
            x = Math.max(0, Math.min(maxX, x));
            sliderCaptchaState.dragX = x;
            if (withAnim) thumb.classList.add('is-anim');
            else thumb.classList.remove('is-anim');
            thumb.style.transform = 'translate3d(' + x + 'px,0,0)';
            thumb.setAttribute('aria-valuenow', maxX > 0 ? String(Math.round(x / maxX * 100)) : '0');
            if (fill) fill.style.width = (x + thumb.offsetWidth / 2) + 'px';
            if (hint) hint.style.opacity = x > 8 ? '0' : '1';
        }

        function resetSliderThumb(animate) {
            const thumb = document.getElementById('sliderThumb');
            const track = document.getElementById('sliderTrack');
            if (thumb) {
                thumb.classList.remove('is-dragging', 'is-ok');
                thumb.innerText = '›';
            }
            if (track) track.classList.remove('is-ok', 'is-fail');
            applyThumbX(0, !!animate);
            setSliderStatus('');
        }

        function measureSliderMax() {
            const track = document.getElementById('sliderTrack');
            const thumb = document.getElementById('sliderThumb');
            if (!track || !thumb) return 0;
            const trackWidth = track.clientWidth || 280;
            return Math.max(0, trackWidth - thumb.offsetWidth - 4);
        }

        function renderSliderChallenge(ch) {
            document.getElementById('sliderModalHint').innerText = (ch && ch.hint) ? ch.hint : fc('slider_modal_hint');
            sliderCaptchaState.maxX = measureSliderMax();
            resetSliderThumb(false);
            setSliderStatus('');
        }

        async function openSliderCaptcha(phone, onSuccess, onCancel) {
            sliderCaptchaState.phone = phone;
            sliderCaptchaState.onSuccess = onSuccess;
            sliderCaptchaState.onCancel = typeof onCancel === 'function' ? onCancel : null;
            sliderCaptchaState.completed = false;
            sliderCaptchaState.mode = 'sms';
            try {
                const data = await apiRequest('slidercaptcha', 'GET');
                if (!data || data.enabled === false) {
                    onSuccess({});
                    return;
                }
                sliderCaptchaState.challenge = data;
                document.getElementById('sliderCaptchaModal').classList.add('show');
                setTimeout(function() { renderSliderChallenge(data); }, 30);
                bindSliderDrag();
            } catch (e) {
                showFanshubToast(e.message || fc('srv_slider_create_fail'), 'error');
                if (typeof onCancel === 'function') onCancel();
            }
        }

        /** 抢包风控专用滑块（挑战写入 IM Redis） */
        async function openGrabSliderCaptcha(phone, onSuccess, onCancel) {
            sliderCaptchaState.phone = phone || '';
            sliderCaptchaState.onSuccess = onSuccess;
            sliderCaptchaState.onCancel = typeof onCancel === 'function' ? onCancel : null;
            sliderCaptchaState.completed = false;
            sliderCaptchaState.mode = 'grab';
            try {
                const data = await apiRequest('grabslider', 'GET');
                if (!data || data.enabled === false) {
                    onSuccess({});
                    return;
                }
                sliderCaptchaState.challenge = data;
                if (data.hint) {
                    document.getElementById('sliderModalHint').innerText = data.hint;
                }
                document.getElementById('sliderCaptchaModal').classList.add('show');
                setTimeout(function() { renderSliderChallenge(data); }, 30);
                bindSliderDrag();
            } catch (e) {
                showFanshubToast(e.message || fc('srv_slider_create_fail'), 'error');
                if (typeof onCancel === 'function') onCancel();
            }
        }
        window.openSliderCaptcha = openSliderCaptcha;
        window.openGrabSliderCaptcha = openGrabSliderCaptcha;
        window.closeSliderCaptcha = closeSliderCaptcha;

        function bindSliderDrag() {
            const thumb = document.getElementById('sliderThumb');
            const track = document.getElementById('sliderTrack');
            const refreshBtn = document.getElementById('sliderRefreshBtn');
            if (!thumb || !track) return;

            if (refreshBtn && !refreshBtn._sliderBound) {
                refreshBtn._sliderBound = true;
                refreshBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (sliderCaptchaState.locking) return;
                    if (sliderCaptchaState.mode === 'grab') {
                        openGrabSliderCaptcha(sliderCaptchaState.phone, sliderCaptchaState.onSuccess);
                    } else {
                        openSliderCaptcha(sliderCaptchaState.phone, sliderCaptchaState.onSuccess);
                    }
                });
            }

            if (thumb._sliderBound) return;
            thumb._sliderBound = true;

            const PASS_RATIO = 0.82;

            function pointerDown(clientX, ev) {
                if (sliderCaptchaState.locking) return;
                sliderCaptchaState.dragging = true;
                sliderCaptchaState.startTime = Date.now();
                sliderCaptchaState._startClientX = clientX;
                sliderCaptchaState._startDragX = sliderCaptchaState.dragX;
                sliderCaptchaState.maxX = measureSliderMax();
                thumb.classList.add('is-dragging');
                thumb.classList.remove('is-anim', 'is-ok');
                track.classList.remove('is-fail', 'is-ok');
                setSliderStatus('');
                if (ev && ev.pointerId != null && thumb.setPointerCapture) {
                    try { thumb.setPointerCapture(ev.pointerId); } catch (err) {}
                }
            }

            function pointerMove(clientX) {
                if (!sliderCaptchaState.dragging || sliderCaptchaState.locking) return;
                const delta = clientX - sliderCaptchaState._startClientX;
                applyThumbX(sliderCaptchaState._startDragX + delta, false);
            }

            function failBack() {
                track.classList.add('is-fail');
                setSliderStatus(fc('alert_slider_fail') || '请拖到最右侧', 'is-fail');
                applyThumbX(0, true);
                setTimeout(function () {
                    track.classList.remove('is-fail');
                    if (!sliderCaptchaState.dragging) setSliderStatus('');
                }, 420);
            }

            function succeedAndSubmit() {
                const maxX = sliderCaptchaState.maxX;
                const ch = sliderCaptchaState.challenge;
                if (!ch || !ch.token) {
                    failBack();
                    return;
                }
                sliderCaptchaState.locking = true;
                sliderCaptchaState.completed = true;
                sliderCaptchaState.onCancel = null;
                applyThumbX(maxX, true);
                thumb.classList.remove('is-dragging');
                thumb.classList.add('is-ok');
                thumb.innerText = '✓';
                track.classList.add('is-ok');
                setSliderStatus('验证通过，继续抢包…', 'is-ok');
                const payload = {
                    slider_token: ch.token,
                    slider_x: Math.round(maxX),
                    slider_max: Math.round(maxX),
                    slider_duration: Math.max(180, Date.now() - sliderCaptchaState.startTime)
                };
                const cb = sliderCaptchaState.onSuccess;
                setTimeout(function () {
                    closeSliderCaptcha({ fromSuccess: true });
                    if (typeof cb === 'function') cb(payload);
                }, 220);
            }

            function pointerUp() {
                if (!sliderCaptchaState.dragging || sliderCaptchaState.locking) return;
                sliderCaptchaState.dragging = false;
                thumb.classList.remove('is-dragging');
                const maxX = sliderCaptchaState.maxX;
                const passX = Math.floor(maxX * PASS_RATIO);
                if (sliderCaptchaState.dragX < passX) {
                    failBack();
                    return;
                }
                // 过线后吸附到终点，体验更顺
                succeedAndSubmit();
            }

            if (window.PointerEvent) {
                thumb.addEventListener('pointerdown', function (e) {
                    if (e.button != null && e.button !== 0) return;
                    e.preventDefault();
                    pointerDown(e.clientX, e);
                });
                thumb.addEventListener('pointermove', function (e) {
                    if (!sliderCaptchaState.dragging) return;
                    pointerMove(e.clientX);
                });
                thumb.addEventListener('pointerup', pointerUp);
                thumb.addEventListener('pointercancel', pointerUp);
            } else {
                thumb.addEventListener('mousedown', function (e) { e.preventDefault(); pointerDown(e.clientX); });
                thumb.addEventListener('touchstart', function (e) {
                    if (e.touches && e.touches[0]) pointerDown(e.touches[0].clientX);
                }, { passive: true });
                document.addEventListener('mousemove', function (e) { pointerMove(e.clientX); });
                document.addEventListener('touchmove', function (e) {
                    if (sliderCaptchaState.dragging && e.touches && e.touches[0]) {
                        pointerMove(e.touches[0].clientX);
                    }
                }, { passive: true });
                document.addEventListener('mouseup', pointerUp);
                document.addEventListener('touchend', pointerUp);
            }
        }

        async function doSendSms(phone, sliderPayload) {
            try {
                const payload = Object.assign({
                    mobile: phone,
                    country_code: window.FanshubI18n ? FanshubI18n.country : 'CN'
                }, sliderPayload || {});
                const data = await apiRequest('sendsms', 'POST', payload);
                const interval = (data && data.retry_after) ? parseInt(data.retry_after, 10) : (CONFIG.SMS_SEND_INTERVAL || 60);
                startSmsCooldown(phone, interval);
                const hint = (data && data.hint) ? data.hint : fc('alert_sms_hint_default');
                showFanshubToast(fc('alert_sms_sent') + '\n' + hint, 'success');
            } catch (e) {
                if (e.payload && e.payload.retry_after) {
                    startSmsCooldown(phone, e.payload.retry_after);
                }
                showFanshubToast(e.message || fc('alert_sms_fail'), 'error');
            }
        }

        async function sendMockCaptcha() {
            const phone = getLoginMobileE164();
            if (!phone || (window.FanshubI18n && !FanshubI18n.isValidNational(document.getElementById('loginPhone').value))) {
                showFanshubToast(fc('alert_phone_invalid'), 'error');
                return;
            }
            const remain = getSmsCooldownRemain(phone);
            if (remain > 0) {
                startSmsCooldown(phone, remain);
                showFanshubToast(fc('api_sms_too_frequent_wait', { seconds: remain }), 'error');
                return;
            }
            if (CONFIG.SMS_SLIDER_ENABLED) {
                openSliderCaptcha(phone, function(sliderPayload) {
                    doSendSms(phone, sliderPayload);
                });
                return;
            }
            await doSendSms(phone, {});
        }

        async function submitLogin() {
            const phone = getLoginMobileE164();
            let captcha = document.getElementById('loginCaptcha').value.trim();
            if (!phone || (window.FanshubI18n && !FanshubI18n.isValidNational(document.getElementById('loginPhone').value))) {
                showFanshubToast(fc('alert_phone_required'), 'error');
                return;
            }
            if (!captcha) {
                showFanshubToast(fc('alert_captcha_required'), 'error');
                return;
            }
            try {
                await ensureServerConfig();
                const data = await apiRequest('login', 'POST', {
                    mobile: phone,
                    country_code: window.FanshubI18n ? FanshubI18n.country : 'CN',
                    captcha: captcha,
                    code: PAGE_INVITE_CODE,
                    invite: PAGE_INVITE_CODE,
                    device_fp: await getDeviceFingerprint()
                });
                localStorage.setItem('fans_hub_token', data.token);
                applyProfile(data.profile);
                document.getElementById('loginView').classList.remove('active');
                document.getElementById('mainDashboardView').classList.add('active');
                if (data.is_new) {
                    sessionStorage.setItem('fans_hub_show_lottery', '1');
                }
                scheduleWelcomeLottery();
                setBottomActionBarVisible(true);
                switchTab('home');
                startJackpotSync();
                updateFlowStepper();
                if (typeof window.hydrateAfterLogin === 'function') {
                    window.hydrateAfterLogin().catch(function () {});
                }
                if (window.FansHubAssets && typeof FansHubAssets.prefetchChat === 'function') {
                    FansHubAssets.prefetchChat();
                } else if (window.FansHubChat && typeof FansHubChat.onLogin === 'function') {
                    FansHubChat.onLogin();
                }
                showFanshubToast(data.is_new ? fc('alert_login_new') : fc('alert_login_back'), 'success');
            } catch (e) {
                showFanshubToast(e.message || fc('alert_login_fail'), 'error');
            }
        }

        function leaderboardDaySeed() {
            const d = new Date();
            return d.getFullYear() * 10000 + (d.getMonth() + 1) * 100 + d.getDate();
        }

        function leaderboardRng(seed) {
            let a = (seed >>> 0) || 1;
            return function () {
                a |= 0;
                a = (a + 0x6D2B79F5) | 0;
                let t = Math.imul(a ^ (a >>> 15), 1 | a);
                t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
                return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
            };
        }

        /** 虚拟裂变排行榜：按自然日种子生成，日内随小时缓慢上浮，刷新不乱跳 */
        function buildVirtualLeaderboard(limit) {
            limit = Math.max(1, Math.min(20, limit || 10));
            const rnd = leaderboardRng(leaderboardDaySeed() ^ 0xF15510);
            // 多国脱敏号：CN / PH / VN / MY / KH / ID
            const pools = [
                { dial: '+86',  heads: ['130', '131', '135', '136', '137', '138', '139', '150', '158', '186', '188'], tailLen: 4 },
                { dial: '+63',  heads: ['905', '906', '915', '916', '917', '918', '919', '920', '921', '927'], tailLen: 4 },
                { dial: '+84',  heads: ['90', '91', '93', '94', '96', '97', '98', '32', '33', '35'], tailLen: 4 },
                { dial: '+60',  heads: ['10', '11', '12', '13', '14', '16', '17', '18', '19'], tailLen: 4 },
                { dial: '+855', heads: ['10', '11', '12', '15', '16', '17', '61', '69', '70', '77'], tailLen: 3 },
                { dial: '+62',  heads: ['812', '813', '814', '815', '816', '817', '818', '819', '821', '822'], tailLen: 4 }
            ];
            // 邀请人数控制在较小区间，避免虚高
            const base = [28, 24, 21, 17, 14, 12, 10, 8, 6, 5, 4, 4, 3, 3, 2, 2, 2, 2, 2, 2];
            const hourBoost = Math.floor((Date.now() % 86400000) / 3600000);
            const rows = [];
            for (let i = 0; i < limit; i++) {
                const pool = pools[Math.floor(rnd() * pools.length)];
                const head = pool.heads[Math.floor(rnd() * pool.heads.length)];
                let tail = '';
                for (let t = 0; t < pool.tailLen; t++) tail += String(Math.floor(rnd() * 10));
                const jitter = Math.floor(rnd() * 3);
                const count = Math.max(2, (base[i] || Math.max(2, 12 - i)) + Math.floor(hourBoost * 0.12) + jitter);
                rows.push({
                    rank: i + 1,
                    mobile_mask: pool.dial + ' ' + head + '****' + tail,
                    invite_count: count
                });
            }
            rows.sort(function (a, b) { return b.invite_count - a.invite_count; });
            rows.forEach(function (r, idx) { r.rank = idx + 1; });
            return rows;
        }

        function renderLeaderboardRows(node, rows) {
            if (!node) return;
            if (!rows || !rows.length) {
                node.innerHTML = '<div class="text-muted" style="font-size:12px;">' + fc('leaderboard_empty') + '</div>';
                return;
            }
            node.innerHTML = rows.map(function (item) {
                const medal = item.rank <= 3
                    ? ['\uD83E\uDD47', '\uD83E\uDD48', '\uD83E\uDD49'][item.rank - 1]
                    : item.rank;
                const name = item.mobile_mask || fc('leaderboard_user_fallback');
                const inviteText = fc('leaderboard_invite_template', { count: item.invite_count });
                return '<div class="leaderboard-item"><div><span class="leaderboard-rank">' + medal + '</span> ' + name + '</div><div class="leaderboard-count">' + inviteText + '</div></div>';
            }).join('');
        }

        async function loadLeaderboard() {
            const node = document.getElementById('leaderboardList');
            if (!node) return;
            // 大厅优先展示虚拟裂变榜（营销氛围）；若接口有真实数据则合并后按邀请数重排 TOP10
            let rows = buildVirtualLeaderboard(10);
            try {
                let real = window._bootstrapLeaderboard;
                if (Array.isArray(real) && real.length) {
                    window._bootstrapLeaderboard = null; // 只用一次，之后走短缓存
                    window._leaderboardCacheAt = Date.now();
                    window._leaderboardCacheRows = real;
                } else if (window._leaderboardCacheRows && window._leaderboardCacheAt
                    && (Date.now() - window._leaderboardCacheAt) < 60000) {
                    real = window._leaderboardCacheRows;
                } else {
                    real = await apiRequest('inviteleaderboard', 'GET', { limit: 10 });
                    window._leaderboardCacheAt = Date.now();
                    window._leaderboardCacheRows = real;
                }
                if (Array.isArray(real) && real.length) {
                    const map = {};
                    real.forEach(function (r) {
                        if (!r || !r.mobile_mask) return;
                        map[r.mobile_mask] = {
                            mobile_mask: r.mobile_mask,
                            invite_count: Number(r.invite_count) || 0
                        };
                    });
                    rows.forEach(function (v) {
                        if (!map[v.mobile_mask]) map[v.mobile_mask] = v;
                    });
                    rows = Object.keys(map).map(function (k) { return map[k]; });
                    rows.sort(function (a, b) { return (b.invite_count || 0) - (a.invite_count || 0); });
                    rows = rows.slice(0, 10);
                    rows.forEach(function (r, i) { r.rank = i + 1; });
                }
            } catch (e) {
                // keep virtual
            }
            renderLeaderboardRows(node, rows);
            window._lastLeaderboardRows = rows;
        }
        window.loadLeaderboard = loadLeaderboard;
        window.buildVirtualLeaderboard = buildVirtualLeaderboard;
        window.refreshLeaderboardCopy = function () {
            const node = document.getElementById('leaderboardList');
            if (!node) return;
            if (window._lastLeaderboardRows && window._lastLeaderboardRows.length) {
                renderLeaderboardRows(node, window._lastLeaderboardRows);
                return;
            }
            loadLeaderboard();
        };

        function renderUI() {
            const balEl = document.getElementById('myUserBalance');
            const rightsEl = document.getElementById('myTicketPool');
            if (balEl) balEl.innerText = account.balance.toFixed(2);
            if (rightsEl) rightsEl.innerText = account.rights.toFixed(2);
            const hbHome = document.getElementById('myHongbaoPool');
            if (hbHome) hbHome.innerText = Number(account.hongbao || 0).toFixed(2);
            const exRights = document.getElementById('exchangeRightsEcho');
            const exBal = document.getElementById('exchangeBalanceEcho');
            const exSym = document.getElementById('exchangeBalSym');
            if (exRights) exRights.textContent = Number(account.rights || 0).toFixed(2);
            if (exBal) exBal.textContent = Number(account.balance || 0).toFixed(2);
            if (exSym) exSym.textContent = (window.FanshubI18n && FanshubI18n.currencySymbol()) || '￥';
            const exHb = document.getElementById('exchangeHongbaoEcho');
            if (exHb) exHb.textContent = Number(account.hongbao || 0).toFixed(2);

            const free = typeof freeRights === 'function' ? freeRights() : Math.max(0, (account.rights || 0) - (account.rights_locked || 0));
            const locked = Math.max(0, Number(account.rights_locked) || 0);
            const lockHint = document.getElementById('exchangeRightsLockHint');
            if (lockHint) {
                if (locked > 0) {
                    lockHint.style.display = '';
                    lockHint.textContent = fc('alert_rights_t1_hint', {
                        free: Math.floor(free),
                        locked: Math.ceil(locked)
                    }) || ('可兑 ' + Math.floor(free) + ' 份 · 锁定 ' + Math.ceil(locked) + ' 份（T+1）');
                } else {
                    lockHint.style.display = 'none';
                    lockHint.textContent = '';
                }
            }

            let inputNode = document.getElementById('ticketCount');
            if (inputNode) {
                const min = Math.max(1, parseInt(CONFIG.EXCHANGE_R2B_MIN, 10) || 1);
                let allowedMax = Math.floor(free * CONFIG.MAX_VOTE_PERCENT);
                if (allowedMax < min && free >= min) allowedMax = min;
                if (allowedMax < 1 && free > 0) allowedMax = 1;
                let currentInput = parseInt(inputNode.value, 10) || min;
                if (free <= 0) {
                    currentInput = min;
                } else if (currentInput > allowedMax) {
                    currentInput = Math.max(min, allowedMax);
                } else if (currentInput < min) {
                    currentInput = min;
                }
                inputNode.value = currentInput;
            }
            updateDynamicCopy({ skipHomeLists: true });
            updateBalanceProgress();
            const goldBtn = document.getElementById('btnGoldenMain');
            if (goldBtn) {
                goldBtn.disabled = CONFIG.EXCHANGE_R2B_ENABLED === false || free <= 0;
            }
            updateManualSettleButton();
            restoreLotteryValuation();
            updateFlowStepper();
            syncClaimPageEcho();
        }

        function adjustCount(amount) {
            if (CONFIG.EXCHANGE_R2B_ENABLED === false) {
                showFanshubToast(fc('profile_ex_r2b_closed') || fc('alert_exchange_disabled'), 'error');
                return;
            }
            let inputNode = document.getElementById('ticketCount');
            let currentVal = parseInt(inputNode.value, 10) || 0;
            let nextVal = currentVal + amount;
            const min = Math.max(1, parseInt(CONFIG.EXCHANGE_R2B_MIN, 10) || 1);
            const free = typeof freeRights === 'function' ? freeRights() : Math.max(0, (account.rights || 0) - (account.rights_locked || 0));
            let allowedMax = Math.floor(free * CONFIG.MAX_VOTE_PERCENT);
            if (allowedMax < min && free >= min) allowedMax = min;
            if (nextVal >= min && nextVal <= allowedMax) {
                inputNode.value = nextVal;
                renderUI();
            } else if (nextVal > allowedMax) {
                showFanshubToast(fc('alert_exchange_limit', { max: allowedMax }), 'error');
            } else if (nextVal < min) {
                showFanshubToast(fc('alert_exchange_min', { min: min }), 'error');
            }
        }

        function selectTeam(channel) {
            currentSelectedTeam = fc('channel_a_name') || 'A';
            const el = document.getElementById('optTeamA');
            if (el) el.classList.add('selected');
        }

        async function handleGoldenCashOut() {
            if (isConverting) return;
            if (CONFIG.EXCHANGE_R2B_ENABLED === false) {
                showFanshubToast(fc('profile_ex_r2b_closed') || fc('alert_exchange_disabled'), 'error');
                return;
            }

            const free = typeof freeRights === 'function' ? freeRights() : Math.max(0, (account.rights || 0) - (account.rights_locked || 0));
            if (free <= 0) {
                if ((Number(account.hongbao) || 0) >= CONFIG.WITHDRAW_THRESHOLD) {
                    onFlashComplete(0, 0);
                } else if ((account.rights || 0) > 0 && (account.rights_locked || 0) > 0) {
                    showFanshubToast(fc('srv_rights_t1_locked', {
                        free: 0,
                        locked: Math.ceil(account.rights_locked || 0)
                    }) || '兑入的股份需次日（T+1）才可兑出', 'error');
                } else {
                    showFanshubToast(fc('alert_no_rights'), 'error');
                }
                return;
            }

            const min = Math.max(1, parseInt(CONFIG.EXCHANGE_R2B_MIN, 10) || 1);
            let selectCount = parseInt(document.getElementById('ticketCount').value, 10);
            if (isNaN(selectCount) || selectCount < 1) {
                showFanshubToast(fc('alert_select_count'), 'error');
                return;
            }
            if (selectCount < min) {
                showFanshubToast(fc('alert_exchange_min', { min: min }), 'error');
                return;
            }

            isConverting = true;
            document.getElementById('btnGoldenMain').disabled = true;

            try {
                if (!currentSelectedTeam) currentSelectedTeam = fc('channel_a_name') || 'A';
                const profile = await apiRequest('exchange', 'POST', {
                    count: selectCount,
                    channel: currentSelectedTeam,
                    request_id: newRequestId('ex')
                });
                const addValue = selectCount * getSharePrice();
                let startVal = account.balance;
                account.rights = parseFloat(profile.rights) || 0;
                account.rights_locked = parseFloat(profile.rights_locked) || 0;
                account.rights_free = profile.rights_free != null
                    ? (parseFloat(profile.rights_free) || 0)
                    : Math.max(0, account.rights - account.rights_locked);
                let endVal = parseFloat(profile.balance) || 0;
                let current = startVal;
                let steps = 25;
                let perStep = (endVal - startVal) / steps;
                let timer = setInterval(() => {
                    current += perStep;
                    if (current >= endVal) {
                        current = endVal;
                        clearInterval(timer);
                        applyProfile(profile);
                        isConverting = false;
                        document.getElementById('btnGoldenMain').disabled = freeRights() <= 0;
                        onFlashComplete(selectCount, addValue);
                    } else {
                        document.getElementById('myUserBalance').innerText = current.toFixed(2);
                    }
                }, 25);
            } catch (e) {
                isConverting = false;
                document.getElementById('btnGoldenMain').disabled = freeRights() <= 0;
                showFanshubToast(e.message || fc('alert_exchange_fail'), 'error');
            }
        }

        function onFlashComplete(selectCount, addValue) {
            const convertedMsg = selectCount > 0
                ? fc('alert_exchange_success', { count: selectCount, amount: addValue.toFixed(2), balance: account.balance.toFixed(2) })
                : fc('alert_exchange_balance_only', { balance: account.balance.toFixed(2) });

            if ((Number(account.hongbao) || 0) < CONFIG.WITHDRAW_THRESHOLD) {
                if (selectCount > 0) showFanshubToast(convertedMsg, 'success');
                showThresholdBlockModal();
            } else if (flowStage === 'stage2') {
                const uid = document.getElementById('mainStationID').value.trim();
                if (uid) {
                    showFanshubToast(convertedMsg + '\n\n' + fc('alert_exchange_vip_ready'), 'success');
                } else {
                    showFanshubToast(convertedMsg + '\n\n' + fc('alert_exchange_vip_need_uid'), 'info');
                }
            } else {
                showFanshubToast(convertedMsg + '\n\n' + fc('alert_exchange_need_open'), 'info');
            }
        }

        function showThresholdBlockModal() {
            const balance = Number(account.hongbao) || 0;
            const shortAmt = CONFIG.WITHDRAW_THRESHOLD - balance;
            const progress = Math.min(100, (balance / CONFIG.WITHDRAW_THRESHOLD) * 100);
            document.getElementById('blockCurrentAmt').innerText = balance.toFixed(2);
            document.getElementById('blockShortAmt').innerText = shortAmt.toFixed(2);
            document.getElementById('blockProgressBar').style.width = progress + '%';
            document.getElementById('thresholdBlockModal').style.display = 'flex';
        }

        function closeThresholdBlockModal() {
            document.getElementById('thresholdBlockModal').style.display = 'none';
        }

        function goToMainStationFromBlock() {
            closeThresholdBlockModal();
            goToMainStation();
        }

        let currentSecretRequestId = '';

        async function openWithdrawModal() {
            if (typeof switchTab === 'function') switchTab('home');
            let balance = Number(account.hongbao) || 0;
            let playerUID = document.getElementById('mainStationID').value.trim();
            const isVipTier = balance >= CONFIG.WITHDRAW_THRESHOLD;

            if (isVipTier && flowStage === 'stage2' && (account.main_uid_audit !== 'approved' || !account.main_uid)) {
                showFanshubToast(
                    account.main_uid_audit === 'pending' ? fc('uid_hint_pending') : fc('alert_uid_required'),
                    'error'
                );
                document.getElementById('uidSection').classList.add('visible');
                return;
            }

            if (isVipTier && flowStage === 'stage1') {
                showFanshubToast(fc('alert_open_first'), 'error');
                return;
            }

            try {
                if (!currentSecretRequestId) {
                    currentSecretRequestId = newRequestId('sec');
                }
                const data = await apiRequest('createsecret', 'POST', { request_id: currentSecretRequestId });
                currentSecretCode = data.secret.code;
                if (data.customer_service_url) CONFIG.CUSTOMER_SERVICE_URL = data.customer_service_url;
                if (data.app_download_url) CONFIG.APP_DOWNLOAD_URL = data.app_download_url;
                applyProfile(data.profile);
                document.getElementById('billCodeText').innerText = currentSecretCode;
                document.getElementById('modalBalanceWrap').innerText = formatMoney(balance);

                if (isVipTier) {
                    document.getElementById('withdrawModalTitle').innerText = fc('withdraw_title_vip');
                } else {
                    document.getElementById('withdrawModalTitle').innerText = fc('withdraw_title_green');
                }

                CONFIG.SECRET_LOCK_SECONDS = data.secret.lock_seconds || CONFIG.SECRET_LOCK_SECONDS;
                document.getElementById('secretCountdown').parentElement.style.color = 'var(--danger)';
                startSecretCountdown();
                document.getElementById('withdrawModal').style.display = 'flex';
            } catch (e) {
                showFanshubToast(e.message || fc('alert_secret_fail'), 'error');
            }
        }

        function closeWithdrawModal() {
            document.getElementById('withdrawModal').style.display = 'none';
            if (secretTimerInterval) clearInterval(secretTimerInterval);
            currentSecretRequestId = '';
        }

        function copySecretCode() {
            copyText(currentSecretCode, fc('alert_secret_copied_clipboard'));
        }

        function resolveCsIdentifier() {
            const uid = document.getElementById('mainStationID').value.trim();
            if (uid) return uid;
            if (account.phone) return account.phone;
            return '';
        }

        async function jumpToCustomerService() {
            if (!currentSecretCode) {
                showFanshubToast(fc('alert_secret_required'), 'error');
                return;
            }
            await copyTextSilent(currentSecretCode);
            showFanshubToast(fc('alert_secret_copied'), 'success');
            closeWithdrawModal();
            if (typeof switchTab === 'function') {
                switchTab('messages');
            } else if (typeof window.switchTab === 'function') {
                window.switchTab('messages');
            }
        }

        function copyTextSilent(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(text).catch(() => fallbackCopySilent(text));
            }
            return Promise.resolve(fallbackCopySilent(text));
        }

        function fallbackCopySilent(text) {
            let tempInput = document.createElement("input");
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand("copy");
            document.body.removeChild(tempInput);
        }

        async function copyShareLink() {
            try {
                const data = await apiRequest('share', 'POST', {});
                if (data.rewarded) {
                    applyProfile(data.profile);
                }
                await copyTextSilent(data.share_text || '');
                let toastMsg = data.message || '';
                if (!toastMsg) {
                    toastMsg = data.rewarded
                        ? fc('alert_share_rewarded')
                        : fc('alert_share_copied', { message: fc('alert_share_wait_default') });
                }
                toastMsg = toastMsg.replace(/【[^】]*】/g, '').replace(/\\n+/g, ' ').trim();
                showFanshubToast(toastMsg || '分享文案已复制');
            } catch (e) {
                showFanshubToast(e.message || fc('alert_share_fail'), 'error');
            }
        }

        function goToMainStation() {
            sessionStorage.setItem('fans_hub_pending_open', 'true');
            // 提前亮出 UID，回站后可先绑定再领开户奖励
            sessionStorage.setItem('fans_hub_pending_open_reward', 'true');
            updateFlowUI();
            showFanshubToast(fc('alert_open_account'), 'info');
            setTimeout(() => {
                window.open(CONFIG.MAIN_STATION_URL);
            }, 800);
        }

        function escapeCommentHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function renderCommentItem(item, isMine) {
            let div = document.createElement('div');
            div.className = 'comment-item';
            const userLabel = isMine
                ? fc('comment_user_mine', { user: item.user })
                : fc('comment_user_other', { user: item.user });
            const userHtml = isMine
                ? '<span style="color:var(--danger)">' + escapeCommentHtml(userLabel) + '</span>'
                : '<span>' + escapeCommentHtml(userLabel) + '</span>';
            const textStyle = isMine ? 'color: var(--danger); font-weight:bold;' : 'color: var(--text-main);';
            div.innerHTML = '<div class="comment-user">' + userHtml
                + '<span class="comment-time">' + escapeCommentHtml(item.time) + '</span></div>'
                + '<div style="' + textStyle + '">' + escapeCommentHtml(item.text) + '</div>';
            return div;
        }

        function getCommentLoadMoreEl(create) {
            let el = document.getElementById('commentLoadMore');
            if (!el && create) {
                el = document.createElement('div');
                el.id = 'commentLoadMore';
                el.style.cssText = 'text-align:center;color:var(--text-muted);padding:12px 0;font-size:12px;';
                document.getElementById('commentScrollBox').appendChild(el);
            }
            return el;
        }

        async function loadComments(page, append) {
            if (commentLoading) return;
            commentLoading = true;
            const box = document.getElementById('commentScrollBox');
            if (append) {
                const loader = getCommentLoadMoreEl(true);
                loader.innerText = fc('leaderboard_loading');
                loader.style.display = 'block';
            }
            try {
                const data = await apiRequest('comments', 'GET', { page: page, limit: COMMENT_PAGE_SIZE });
                const list = (data && data.list) ? data.list : [];
                const total = data && data.total !== undefined ? data.total : list.length;
                if (!append) {
                    box.innerHTML = '';
                    commentLoadedCount = 0;
                }
                const loaderEl = getCommentLoadMoreEl(false);
                if (loaderEl) loaderEl.remove();
                if (!list.length && page === 1) {
                    box.innerHTML = '<div class="comment-empty-placeholder" style="text-align:center;color:var(--text-muted);padding:20px 0;">' + fc('comment_empty') + '</div>';
                    commentHasMore = false;
                    return;
                }
                list.forEach(item => box.appendChild(renderCommentItem(item, false)));
                commentPage = page;
                commentLoadedCount += list.length;
                commentHasMore = commentLoadedCount < total;
                if (commentHasMore) {
                    const hint = getCommentLoadMoreEl(true);
                    hint.innerText = fc('comment_scroll_hint');
                }
            } catch (e) {
                if (!append) {
                    box.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:20px 0;">' + fc('comment_fail') + '</div>';
                }
                commentHasMore = false;
            } finally {
                commentLoading = false;
            }
        }

        async function initComments() {
            commentPage = 1;
            commentHasMore = true;
            await loadComments(1, false);
        }

        function handleCommentScroll() {
            const box = document.getElementById('commentScrollBox');
            if (!commentHasMore || commentLoading) return;
            if (box.scrollTop + box.clientHeight >= box.scrollHeight - 48) {
                loadComments(commentPage + 1, true);
            }
        }

        async function submitComment() {
            let input = document.getElementById('userCommentInput');
            const text = input.value.trim();
            if (!text) return;
            if (!localStorage.getItem('fans_hub_token')) {
                showFanshubToast(fc('alert_comment_login'), 'success');
                return;
            }
            try {
                const data = await apiRequest('postcomment', 'POST', { content: text });
                let box = document.getElementById('commentScrollBox');
                if (box.querySelector('.comment-empty-placeholder')) box.innerHTML = '';
                if (data.auto_show) {
                    box.insertBefore(renderCommentItem(data, true), box.firstChild);
                }
                box.scrollTop = 0;
                input.value = '';
                showFanshubToast(data.auto_show ? fc('alert_comment_ok') : fc('alert_comment_pending'), 'success');
            } catch (e) {
                showFanshubToast(e.message || fc('alert_comment_fail'), 'error');
            }
        }

        function parseMarqueeCopyText(text) {
            return String(text || '')
                .replace(/\r\n/g, '\n')
                .replace(/\r/g, '\n')
                .split('\n')
                .map(function(line) { return line.trim(); })
                .filter(Boolean);
        }

        function getMarqueeItems() {
            const loc = (window.FanshubI18n && FanshubI18n.locale) || 'zh-CN';
            const isZh = String(loc).indexOf('zh') === 0;
            const fromCopy = parseMarqueeCopyText(fc('marquee_text'));
            // 非中文：只用多语言文案（避免活动配置中文跑马灯盖住翻译）
            if (!isZh) {
                if (fromCopy.length) return fromCopy;
                return [fc('marquee_fallback_prefix') + fc('marquee_fallback')];
            }
            // 中文：优先活动配置下发，其次文案键
            if (marqueeItems.length) return marqueeItems;
            if (fromCopy.length) return fromCopy;
            return [fc('marquee_fallback_prefix') + fc('marquee_fallback')];
        }

        function initMarqueeInterval() {
            const node = document.getElementById('marqueeNode');
            if (!node) return;
            const items = getMarqueeItems();
            node.innerHTML = items.map(function(text) {
                return '<div class="marquee-item">' + text + '</div>';
            }).join('');
        }

        function copyText(text, successMsg) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => {
                    showFanshubToast(successMsg || fc('alert_copy_ok'), 'success');
                }).catch(() => fallbackCopy(text, successMsg));
            } else {
                fallbackCopy(text, successMsg);
            }
        }

        function fallbackCopy(text, successMsg) {
            let tempInput = document.createElement("input");
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand("copy");
            document.body.removeChild(tempInput);
            showFanshubToast(successMsg || fc('alert_copy_ok'), 'success');
        }
