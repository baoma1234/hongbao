define(['jquery', 'backend'], function ($, Backend) {
    // 仅顶层后台壳轮询，避免每个 iframe 重复播音
    if (window.self !== window.top) {
        return;
    }

    var SOUND_BASE = (typeof Config !== 'undefined' && Config.site && Config.site.cdnurl
        ? String(Config.site.cdnurl)
        : '') + '/assets/sound/admin/';
    var URL_RECHARGE = SOUND_BASE + encodeURIComponent('充值.mp3');
    var URL_WITHDRAW = SOUND_BASE + encodeURIComponent('提现.mp3');
    var POLL_MS = 12000;
    var WITHDRAW_REPEAT_MS = 10000;
    var SEEN_KEY = 'fanshub_payalert_recharge_seen_v1';

    var audioUnlocked = false;
    var rechargeAudio = null;
    var withdrawAudio = null;
    var seenRecharge = {};
    var primed = false;
    var withdrawLoopTimer = null;
    var withdrawActive = false;
    var pollBusy = false;

    function loadSeen() {
        try {
            var raw = sessionStorage.getItem(SEEN_KEY);
            var arr = raw ? JSON.parse(raw) : [];
            if (Array.isArray(arr)) {
                arr.forEach(function (id) { seenRecharge[String(id)] = 1; });
            }
        } catch (e) {}
    }

    function saveSeen() {
        try {
            var ids = Object.keys(seenRecharge);
            if (ids.length > 300) {
                ids = ids.slice(ids.length - 250);
                var next = {};
                ids.forEach(function (id) { next[id] = 1; });
                seenRecharge = next;
            }
            sessionStorage.setItem(SEEN_KEY, JSON.stringify(Object.keys(seenRecharge)));
        } catch (e) {}
    }

    function ensureAudio() {
        if (!rechargeAudio) {
            rechargeAudio = new Audio(URL_RECHARGE);
            rechargeAudio.preload = 'auto';
            rechargeAudio.volume = 0.9;
        }
        if (!withdrawAudio) {
            withdrawAudio = new Audio(URL_WITHDRAW);
            withdrawAudio.preload = 'auto';
            withdrawAudio.volume = 0.9;
        }
    }

    function unlockAudio() {
        if (audioUnlocked) return;
        audioUnlocked = true;
        ensureAudio();
        try {
            rechargeAudio.volume = 0.01;
            var p = rechargeAudio.play();
            if (p && p.then) {
                p.then(function () {
                    rechargeAudio.pause();
                    rechargeAudio.currentTime = 0;
                    rechargeAudio.volume = 0.9;
                }).catch(function () {});
            }
        } catch (e) {}
        hideUnlockBtn();
    }

    function ensureUnlockBtn() {
        if (document.getElementById('fanshubPayAlertUnlock')) return;
        var btn = document.createElement('button');
        btn.id = 'fanshubPayAlertUnlock';
        btn.type = 'button';
        btn.textContent = '开启语音提醒';
        btn.title = '浏览器需用户手势后才能播放充值/提现提示音';
        btn.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:99999;padding:8px 14px;'
            + 'background:#dd4b39;color:#fff;border:0;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.25);cursor:pointer;';
        btn.onclick = function () { unlockAudio(); };
        document.body.appendChild(btn);
    }

    function hideUnlockBtn() {
        var btn = document.getElementById('fanshubPayAlertUnlock');
        if (btn && btn.parentNode) btn.parentNode.removeChild(btn);
    }

    function playOnce(audio) {
        if (!audio) return;
        try {
            audio.pause();
            audio.currentTime = 0;
            var p = audio.play();
            if (p && p.catch) {
                p.catch(function () {
                    audioUnlocked = false;
                    ensureUnlockBtn();
                });
            }
        } catch (e) {
            audioUnlocked = false;
            ensureUnlockBtn();
        }
    }

    function playRechargeOnce() {
        unlockAudio();
        ensureAudio();
        // 提现循环中也插播一次充值音
        playOnce(rechargeAudio);
    }

    function startWithdrawLoop() {
        if (withdrawActive) return;
        withdrawActive = true;
        unlockAudio();
        ensureAudio();
        playOnce(withdrawAudio);
        if (withdrawLoopTimer) clearInterval(withdrawLoopTimer);
        withdrawLoopTimer = setInterval(function () {
            if (!withdrawActive) return;
            playOnce(withdrawAudio);
        }, WITHDRAW_REPEAT_MS);
    }

    function stopWithdrawLoop() {
        withdrawActive = false;
        if (withdrawLoopTimer) {
            clearInterval(withdrawLoopTimer);
            withdrawLoopTimer = null;
        }
        try {
            if (withdrawAudio) {
                withdrawAudio.pause();
                withdrawAudio.currentTime = 0;
            }
        } catch (e) {}
    }

    function handleStats(data) {
        if (!data || typeof data !== 'object') return;
        var rechargeIds = Array.isArray(data.recharge_ids) ? data.recharge_ids : [];
        var withdrawPending = parseInt(data.withdraw_pending, 10) || 0;

        if (!primed) {
            // 首包只建基线，避免打开后台就把历史待支付刷一遍
            rechargeIds.forEach(function (id) { seenRecharge[String(id)] = 1; });
            saveSeen();
            primed = true;
            if (withdrawPending > 0) startWithdrawLoop();
            else stopWithdrawLoop();
            return;
        }

        var fresh = [];
        rechargeIds.forEach(function (id) {
            var k = String(id);
            if (!seenRecharge[k]) {
                seenRecharge[k] = 1;
                fresh.push(id);
            }
        });
        if (fresh.length) {
            saveSeen();
            playRechargeOnce();
        }

        // 提现：仅 pending（待审核）循环催；processing（待打款）不播
        if (withdrawPending > 0) startWithdrawLoop();
        else stopWithdrawLoop();
    }

    function poll() {
        if (pollBusy) return;
        pollBusy = true;
        $.ajax({
            url: 'fanshub/payalert/stats',
            type: 'GET',
            dataType: 'json',
            timeout: 8000
        }).done(function (ret) {
            if (ret && ret.code === 1 && ret.data) {
                handleStats(ret.data);
            }
        }).always(function () {
            pollBusy = false;
        });
    }

    $(function () {
        loadSeen();
        ensureUnlockBtn();
        $(document).one('click keydown touchstart', function () {
            unlockAudio();
        });
        setTimeout(poll, 1200);
        setInterval(poll, POLL_MS);
    });
});
