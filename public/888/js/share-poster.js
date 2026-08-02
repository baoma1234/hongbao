/**
 * 分享推广海报页（红宝 + 菜单入口）
 */
(function (global) {
  'use strict';

  var libsPromise = null;
  var lastLink = '';

  function $(id) { return document.getElementById(id); }

  function toast(msg, type) {
    if (typeof showFanshubToast === 'function') showFanshubToast(msg, type || 'info');
  }

  function assetUrl(path) {
    var ver = (global.FANSHUB_ASSETS && FANSHUB_ASSETS.ver) || '1';
    var base = (global.FANSHUB_ASSETS && FANSHUB_ASSETS.base) || '';
    return base + path + (path.indexOf('?') >= 0 ? '&' : '?') + 'v=' + encodeURIComponent(ver);
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('load fail')); };
      document.head.appendChild(s);
    });
  }

  function ensureQrLib() {
    if (typeof QRCode !== 'undefined') return Promise.resolve();
    if (libsPromise) return libsPromise;
    libsPromise = loadScript(assetUrl('js/vendor/qrcode.min.js')).then(function () {
      if (typeof QRCode === 'undefined') throw new Error('QRCode missing');
    });
    return libsPromise;
  }

  function ensurePane() {
    if ($('chatSharePosterPane')) return;
    var root = document.body;
    root.insertAdjacentHTML('beforeend',
      '<div class="chat-sub-pane chat-share-poster-pane" id="chatSharePosterPane" aria-hidden="true">' +
        '<div class="chat-hero-hd">' +
          '<button type="button" class="chat-hero-back" id="chatSharePosterBack" aria-label="返回">‹</button>' +
          '<div class="chat-hero-title">分享推广赚佣金</div>' +
          '<span class="chat-hero-spacer"></span>' +
        '</div>' +
        '<div class="chat-sub-main chat-share-poster-main">' +
          '<div class="chat-share-poster-card" id="chatSharePosterCard">' +
            '<div class="chat-share-poster-brand">红宝</div>' +
            '<div class="chat-share-poster-title">邀请好友 · 共享收益</div>' +
            '<div class="chat-share-poster-uid">我的邀请码 <strong id="chatSharePosterCode">—</strong></div>' +
            '<div class="chat-share-poster-qr-wrap">' +
              '<canvas id="chatSharePosterQr" width="200" height="200"></canvas>' +
            '</div>' +
            '<div class="chat-share-poster-hint">扫码或打开链接，自动带上你的邀请码</div>' +
            '<div class="chat-share-poster-link" id="chatSharePosterLinkText">—</div>' +
          '</div>' +
          '<button type="button" class="chat-share-poster-btn primary" id="chatShareCopyLinkBtn">📋 一键复制分享链接</button>' +
          '<button type="button" class="chat-share-poster-btn" id="chatShareSavePosterBtn">💾 保存海报到相册</button>' +
        '</div>' +
      '</div>');
    var back = $('chatSharePosterBack');
    if (back) {
      back.onclick = function () { closeSharePoster(); };
    }
    var copyBtn = $('chatShareCopyLinkBtn');
    if (copyBtn) {
      copyBtn.onclick = function () { copyShareInviteLink(); };
    }
    var saveBtn = $('chatShareSavePosterBtn');
    if (saveBtn) {
      saveBtn.onclick = function () { saveSharePoster(); };
    }
  }

  function closeSharePoster() {
    var pane = $('chatSharePosterPane');
    if (!pane) return;
    pane.classList.remove('open');
    pane.setAttribute('aria-hidden', 'true');
  }

  function buildInviteDownloadLink(shareLink, inviteCode) {
    var link = String(shareLink || '').trim();
    if (link) return link;
    var code = String(inviteCode || '').trim();
    var dl = '';
    try {
      dl = String((global.CONFIG && CONFIG.APP_DOWNLOAD_URL) || '').trim();
    } catch (e0) { dl = ''; }
    if (dl && code) {
      return dl + (dl.indexOf('?') >= 0 ? '&' : '?') + 'code=' + encodeURIComponent(code);
    }
    if (code) {
      var path = location.origin + location.pathname.replace(/\/[^/]*$/, '/');
      if (path.indexOf('/888') < 0) path = location.origin + '/888/';
      return path.replace(/\/?$/, '/') + '?code=' + encodeURIComponent(code);
    }
    return location.origin + '/888/';
  }

  async function openSharePoster() {
    ensurePane();
    var pane = $('chatSharePosterPane');
    if (!pane) return;
    if (typeof closePlusMenu === 'function') closePlusMenu();
    pane.classList.add('open');
    pane.setAttribute('aria-hidden', 'false');
    var codeEl = $('chatSharePosterCode');
    var linkEl = $('chatSharePosterLinkText');
    var canvas = $('chatSharePosterQr');
    if (codeEl) codeEl.textContent = '加载中…';
    if (linkEl) linkEl.textContent = '加载中…';
    try {
      var data = {};
      if (typeof apiRequest === 'function') {
        data = await apiRequest('commission', 'GET', {}) || {};
      }
      var code = String(data.invite_code || '');
      var link = buildInviteDownloadLink(data.share_link, code);
      lastLink = link;
      if (codeEl) codeEl.textContent = code || '—';
      if (linkEl) linkEl.textContent = link || '—';
      await ensureQrLib();
      if (canvas && link) {
        await QRCode.toCanvas(canvas, link, {
          width: 200,
          margin: 1,
          color: { dark: '#1a1a1a', light: '#ffffff' }
        });
      }
    } catch (e) {
      toast((e && e.message) || '加载分享信息失败', 'error');
    }
  }

  async function copyShareInviteLink() {
    var text = lastLink || (($('chatSharePosterLinkText') && $('chatSharePosterLinkText').textContent) || '');
    text = String(text || '').trim();
    if (!text || text === '—' || text === '加载中…') {
      toast('分享链接未就绪', 'error');
      return;
    }
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        var inp = document.createElement('input');
        inp.value = text;
        document.body.appendChild(inp);
        inp.select();
        document.execCommand('copy');
        document.body.removeChild(inp);
      }
      toast('分享链接已复制', 'success');
    } catch (e) {
      toast('复制失败', 'error');
    }
  }

  function saveSharePoster() {
    var card = $('chatSharePosterCard');
    var qr = $('chatSharePosterQr');
    if (!card || !qr) return;
    try {
      var w = 720;
      var h = 1080;
      var out = document.createElement('canvas');
      out.width = w;
      out.height = h;
      var ctx = out.getContext('2d');
      var grd = ctx.createLinearGradient(0, 0, 0, h);
      grd.addColorStop(0, '#fff7f0');
      grd.addColorStop(0.45, '#ffe8d0');
      grd.addColorStop(1, '#f5d5b0');
      ctx.fillStyle = grd;
      ctx.fillRect(0, 0, w, h);

      ctx.fillStyle = '#C61114';
      ctx.font = 'bold 54px "PingFang SC","Microsoft YaHei",sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('红宝', w / 2, 120);

      ctx.fillStyle = '#1f1714';
      ctx.font = 'bold 36px "PingFang SC","Microsoft YaHei",sans-serif';
      ctx.fillText('邀请好友 · 共享收益', w / 2, 190);

      var code = ($('chatSharePosterCode') && $('chatSharePosterCode').textContent) || '';
      ctx.fillStyle = '#8a7a6e';
      ctx.font = '24px "PingFang SC","Microsoft YaHei",sans-serif';
      ctx.fillText('我的邀请码 ' + code, w / 2, 250);

      var qrSize = 360;
      var qx = (w - qrSize) / 2;
      var qy = 300;
      ctx.fillStyle = '#fff';
      roundRect(ctx, qx - 20, qy - 20, qrSize + 40, qrSize + 40, 24);
      ctx.fill();
      ctx.drawImage(qr, qx, qy, qrSize, qrSize);

      ctx.fillStyle = '#8a7a6e';
      ctx.font = '22px "PingFang SC","Microsoft YaHei",sans-serif';
      ctx.fillText('扫码加入，自动绑定邀请码', w / 2, qy + qrSize + 70);

      var link = lastLink || '';
      ctx.fillStyle = '#3d2e22';
      ctx.font = '18px ui-monospace,Consolas,monospace';
      wrapText(ctx, link, w / 2, qy + qrSize + 120, w - 80, 26);

      out.toBlob(function (blob) {
        if (!blob) {
          toast('生成海报失败', 'error');
          return;
        }
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'hongbao-share-' + (code || 'poster') + '.png';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
        toast('海报已保存，可分享到相册/会话', 'success');
      }, 'image/png');
    } catch (e) {
      toast((e && e.message) || '保存失败', 'error');
    }
  }

  function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  function wrapText(ctx, text, x, y, maxWidth, lineHeight) {
    text = String(text || '');
    if (!text) return;
    var line = '';
    for (var i = 0; i < text.length; i++) {
      var test = line + text.charAt(i);
      if (ctx.measureText(test).width > maxWidth && line) {
        ctx.fillText(line, x, y);
        line = text.charAt(i);
        y += lineHeight;
      } else {
        line = test;
      }
    }
    if (line) ctx.fillText(line, x, y);
  }

  global.FansHubSharePoster = {
    open: openSharePoster,
    close: closeSharePoster
  };
})(window);
