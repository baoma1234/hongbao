/**
 * 个人二维码 + 扫一扫加好友
 * 载荷：FANSHUB_FRIEND:<8位会员ID>
 */
(function (global) {
  'use strict';

  var PREFIX = 'FANSHUB_FRIEND:';
  var scanTimer = null;
  var scanStream = null;
  var libsPromise = null;

  function toast(msg, type) {
    if (typeof showFanshubToast === 'function') showFanshubToast(msg, type || 'info');
    else if (msg) alert(msg);
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('load fail: ' + src)); };
      document.head.appendChild(s);
    });
  }

  function assetUrl(path) {
    var ver = (global.FANSHUB_ASSETS && FANSHUB_ASSETS.ver) || '1';
    var base = (global.FANSHUB_ASSETS && FANSHUB_ASSETS.base) || '';
    return base + path + (path.indexOf('?') >= 0 ? '&' : '?') + 'v=' + encodeURIComponent(ver);
  }

  function ensureLibs() {
    if (libsPromise) return libsPromise;
    var needQr = typeof QRCode === 'undefined';
    var needJsQr = typeof jsQR === 'undefined';
    var tasks = [];
    if (needQr) tasks.push(loadScript(assetUrl('js/vendor/qrcode.min.js')));
    if (needJsQr) tasks.push(loadScript(assetUrl('js/vendor/jsQR.js')));
    libsPromise = Promise.all(tasks).then(function () {
      if (typeof QRCode === 'undefined') throw new Error('QRCode missing');
      if (typeof jsQR === 'undefined') throw new Error('jsQR missing');
    }).catch(function (e) {
      libsPromise = null;
      throw e;
    });
    return libsPromise;
  }

  function normalizeMemberId(raw) {
    var id = String(raw || '').replace(/\D+/g, '');
    return /^\d{8}$/.test(id) ? id : '';
  }

  function currentMemberId() {
    var p = global.lastProfile || null;
    if (p && p.user_id) return normalizeMemberId(p.user_id) || String(p.user_id);
    if (global.account && account.user_id) return normalizeMemberId(account.user_id) || String(account.user_id);
    var el = document.getElementById('profileUserId');
    if (el) return normalizeMemberId(el.textContent) || String(el.textContent || '').trim();
    return '';
  }

  function buildPayload(userId) {
    var id = normalizeMemberId(userId) || String(userId || '').replace(/\D+/g, '');
    return PREFIX + id;
  }

  function parsePayload(text) {
    var raw = String(text || '').trim();
    if (!raw) return null;
    var m = raw.match(/FANSHUB_FRIEND[:：]\s*(\d{8})/i);
    if (m) return m[1];
    m = raw.match(/(?:friend|uid|user_id)[=:/]\s*(\d{8})/i);
    if (m) return m[1];
    m = raw.match(/^\d{8}$/);
    if (m) return m[0];
    // 纯数字里抽 8 位（兼容多余前缀）
    m = raw.replace(/\D+/g, '').match(/(\d{8})/);
    return m ? m[1] : null;
  }

  function ensureChatReady() {
    function ready(chat) {
      if (chat && typeof chat.onTabEnter === 'function') {
        try { chat.onTabEnter(); } catch (e) { /* ignore */ }
      }
      return chat;
    }
    if (global.FansHubChat && typeof FansHubChat.addFriendByMemberId === 'function') {
      return Promise.resolve(ready(FansHubChat));
    }
    if (global.FansHubAssets && typeof FansHubAssets.ensureChat === 'function') {
      return FansHubAssets.ensureChat().then(function () {
        return ready(global.FansHubChat);
      });
    }
    return Promise.reject(new Error('聊天模块未加载'));
  }

  function addFriendByMemberId(memberId) {
    var id = normalizeMemberId(memberId);
    if (!id) {
      toast('无效的会员二维码', 'error');
      return Promise.resolve(false);
    }
    var selfId = normalizeMemberId(currentMemberId());
    if (selfId && selfId === id) {
      toast('不能添加自己为好友', 'error');
      return Promise.resolve(false);
    }
    return ensureChatReady().then(function (chat) {
      if (!chat || typeof chat.addFriendByMemberId !== 'function') {
        throw new Error('加好友功能不可用');
      }
      return chat.addFriendByMemberId(id);
    }).catch(function (e) {
      toast((e && e.message) || '添加好友失败', 'error');
      return false;
    });
  }

  function renderMyQr() {
    var canvas = document.getElementById('profileQrCanvas');
    var uidEl = document.getElementById('profileQrUid');
    var tipEl = document.getElementById('profileQrTip');
    var id = normalizeMemberId(currentMemberId());
    if (uidEl) uidEl.textContent = id || '-';
    if (!id) {
      if (tipEl) tipEl.textContent = '登录后可生成个人二维码';
      return Promise.resolve();
    }
    if (tipEl) tipEl.textContent = '好友扫一扫即可添加你';
    return ensureLibs().then(function () {
      if (!canvas) return;
      return QRCode.toCanvas(canvas, buildPayload(id), {
        width: 220,
        margin: 2,
        color: { dark: '#1a1a1a', light: '#ffffff' }
      });
    }).catch(function (e) {
      toast((e && e.message) || '二维码生成失败', 'error');
    });
  }

  function openMyQrPane() {
    if (typeof closeProfileSubPage === 'function') closeProfileSubPage();
    var pane = document.getElementById('profileQrPane');
    if (!pane) return;
    if (typeof renderProfilePanel === 'function') renderProfilePanel();
    pane.classList.add('open');
    pane.setAttribute('aria-hidden', 'false');
    if (typeof setBottomActionBarVisible === 'function') setBottomActionBarVisible(false);
    renderMyQr();
  }

  function stopScan() {
    if (scanTimer) {
      clearInterval(scanTimer);
      scanTimer = null;
    }
    if (scanStream) {
      try {
        scanStream.getTracks().forEach(function (t) { t.stop(); });
      } catch (e) { /* ignore */ }
      scanStream = null;
    }
    var video = document.getElementById('chatQrScanVideo');
    if (video) {
      try { video.srcObject = null; } catch (e2) { /* ignore */ }
    }
  }

  function closeScanPane() {
    stopScan();
    var pane = document.getElementById('chatQrScanPane');
    if (pane) {
      pane.classList.remove('open');
      pane.setAttribute('aria-hidden', 'true');
    }
  }

  function onScanSuccess(memberId) {
    stopScan();
    closeScanPane();
    addFriendByMemberId(memberId);
  }

  function tickScanFrame() {
    var video = document.getElementById('chatQrScanVideo');
    var canvas = document.getElementById('chatQrScanCanvas');
    if (!video || !canvas || video.readyState < 2) return;
    var w = video.videoWidth;
    var h = video.videoHeight;
    if (!w || !h) return;
    canvas.width = w;
    canvas.height = h;
    var ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, w, h);
    var imageData = ctx.getImageData(0, 0, w, h);
    var code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' });
    if (code && code.data) {
      var id = parsePayload(code.data);
      if (id) onScanSuccess(id);
    }
  }

  function startCameraScan() {
    var video = document.getElementById('chatQrScanVideo');
    if (!video) return Promise.reject(new Error('扫码组件缺失'));
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return Promise.reject(new Error('当前环境不支持摄像头，请改用相册识别'));
    }
    return navigator.mediaDevices.getUserMedia({
      audio: false,
      video: { facingMode: { ideal: 'environment' } }
    }).then(function (stream) {
      scanStream = stream;
      video.srcObject = stream;
      video.setAttribute('playsinline', 'true');
      return video.play();
    }).then(function () {
      if (scanTimer) clearInterval(scanTimer);
      scanTimer = setInterval(tickScanFrame, 350);
    });
  }

  function openScanPane() {
    var pane = document.getElementById('chatQrScanPane');
    if (!pane) {
      toast('扫一扫页面未就绪', 'error');
      return;
    }
    if (typeof closeProfileSubPage === 'function') closeProfileSubPage();
    var afterTab = function () {
      pane.classList.add('open');
      pane.setAttribute('aria-hidden', 'false');
      ensureLibs().then(function () {
        return startCameraScan();
      }).catch(function (e) {
        toast((e && e.message) || '无法打开摄像头，可从相册选择二维码图片', 'error');
      });
    };
    if (typeof switchTab === 'function') {
      switchTab('messages');
      ensureChatReady().then(afterTab).catch(afterTab);
    } else {
      afterTab();
    }
  }

  function scanImageFile(file) {
    if (!file) return;
    ensureLibs().then(function () {
      return new Promise(function (resolve, reject) {
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function () {
          try {
            var canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth;
            canvas.height = img.naturalHeight;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0);
            var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var code = jsQR(imageData.data, imageData.width, imageData.height);
            URL.revokeObjectURL(url);
            resolve(code && code.data ? code.data : '');
          } catch (err) {
            URL.revokeObjectURL(url);
            reject(err);
          }
        };
        img.onerror = function () {
          URL.revokeObjectURL(url);
          reject(new Error('图片读取失败'));
        };
        img.src = url;
      });
    }).then(function (text) {
      var id = parsePayload(text);
      if (!id) {
        toast('未识别到好友二维码', 'error');
        return;
      }
      onScanSuccess(id);
    }).catch(function (e) {
      toast((e && e.message) || '识别失败', 'error');
    });
  }

  function bindUi() {
    var myQrBtn = document.getElementById('profileMyQrBtn');
    if (myQrBtn) myQrBtn.onclick = function () { openMyQrPane(); };
    var scanFromProfile = document.getElementById('profileScanQrBtn');
    if (scanFromProfile) scanFromProfile.onclick = function () { openScanPane(); };
    var qrBack = document.getElementById('profileQrBack');
    if (qrBack) qrBack.onclick = function () {
      if (typeof closeProfileSubPage === 'function') closeProfileSubPage();
    };
    var scanBtn = document.getElementById('chatQrScanBtn');
    if (scanBtn) scanBtn.onclick = function () {
      var menu = document.getElementById('chatPlusMenu');
      if (menu) menu.hidden = true;
      var plusBtn = document.getElementById('chatPlusMenuBtn');
      if (plusBtn) plusBtn.setAttribute('aria-expanded', 'false');
      openScanPane();
    };
    var scanBack = document.getElementById('chatQrScanBack');
    if (scanBack) scanBack.onclick = function () { closeScanPane(); };
    var pickBtn = document.getElementById('chatQrPickBtn');
    var pickInput = document.getElementById('chatQrPickInput');
    if (pickBtn && pickInput) {
      pickBtn.onclick = function () { pickInput.click(); };
      pickInput.onchange = function () {
        var f = pickInput.files && pickInput.files[0];
        pickInput.value = '';
        if (f) scanImageFile(f);
      };
    }
    var copyBtn = document.getElementById('profileQrCopyBtn');
    if (copyBtn) {
      copyBtn.onclick = function () {
        var id = normalizeMemberId(currentMemberId());
        if (!id) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(id).then(function () {
            toast('会员ID已复制', 'success');
          }).catch(function () {
            toast(id, 'info');
          });
        } else {
          toast(id, 'info');
        }
      };
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindUi);
  } else {
    bindUi();
  }

  global.FansHubFriendQr = {
    buildPayload: buildPayload,
    parsePayload: parsePayload,
    renderMyQr: renderMyQr,
    openMyQrPane: openMyQrPane,
    openScanPane: openScanPane,
    closeScanPane: closeScanPane,
    addFriendByMemberId: addFriendByMemberId
  };
})(window);
