/**
 * FansHub /888 asset loader — parallel CSS/JS + lazy IM/wallet
 */
(function (global) {
  'use strict';

  var cfg = global.FANSHUB_ASSETS || {};
  var ver = String(cfg.ver || '1');
  var base = String(cfg.base || '');
  var cssLoaded = {};
  var jsLoaded = {};
  var chatPromise = null;
  var walletPromise = null;

  function url(path) {
    var p = String(path || '');
    if (p.indexOf('?') >= 0) return base + p + '&v=' + encodeURIComponent(ver);
    return base + p + '?v=' + encodeURIComponent(ver);
  }

  function loadCss(path) {
    var href = url(path);
    if (cssLoaded[href]) return cssLoaded[href];
    cssLoaded[href] = new Promise(function (resolve, reject) {
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      link.onload = function () { resolve(href); };
      link.onerror = function () { reject(new Error('CSS load fail: ' + path)); };
      document.head.appendChild(link);
    });
    return cssLoaded[href];
  }

  function loadJs(path) {
    var src = url(path);
    if (jsLoaded[src]) return jsLoaded[src];
    jsLoaded[src] = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.async = false;
      s.onload = function () { resolve(src); };
      s.onerror = function () { reject(new Error('JS load fail: ' + path)); };
      document.head.appendChild(s);
    });
    return jsLoaded[src];
  }

  function loadCssMany(paths) {
    return Promise.all((paths || []).map(loadCss));
  }

  function fetchText(path) {
    return fetch(url(path), { credentials: 'same-origin' }).then(function (res) {
      if (!res.ok) throw new Error('Fetch fail: ' + path + ' ' + res.status);
      return res.text();
    });
  }

  function ensureChat() {
    if (global.FansHubChat) return Promise.resolve(global.FansHubChat);
    if (chatPromise) return chatPromise;
    var cssPaths = [
      'css/chat-core.css',
      'css/chat-luxury.css',
      'css/chat-rp.css',
      'css/chat-create-group.css',
      'css/chat-community.css',
      'css/chat-notice-feed.css',
      'css/chat-glass-theme.css'
    ];
    var jsParts = [
      'js/chat/01-core.js',
      'js/chat/02-room.js',
      'js/chat/03-rp.js',
      'js/chat/04-net.js',
      'js/chat/05-community.js',
      'js/chat/06-notice.js'
    ];
    chatPromise = loadCssMany(cssPaths)
      .then(function () {
        return Promise.all(jsParts.map(fetchText));
      })
      .then(function (texts) {
        var code = '(function (global) {\n"use strict";\n'
          + texts.join('\n')
          + '\n})(window);';
        // eslint-disable-next-line no-eval
        (0, eval)(code);
        if (!global.FansHubChat) throw new Error('FansHubChat missing after load');
        return global.FansHubChat;
      })
      .catch(function (err) {
        chatPromise = null;
        throw err;
      });
    return chatPromise;
  }

  function ensureWallet() {
    if (global.openProfileWalletPage) return Promise.resolve(true);
    if (walletPromise) return walletPromise;
    walletPromise = loadJs('js/profile-wallet.js')
      .then(function () {
        if (!global.openProfileWalletPage) throw new Error('FansHubWallet missing after load');
        global.FansHubWallet = global.FansHubWallet || { loaded: true };
        return global.FansHubWallet;
      })
      .catch(function (err) {
        walletPromise = null;
        throw err;
      });
    return walletPromise;
  }

  function prefetchChat() {
    try {
      if ('requestIdleCallback' in global) {
        global.requestIdleCallback(function () { ensureChat().catch(function () {}); }, { timeout: 4000 });
      } else {
        setTimeout(function () { ensureChat().catch(function () {}); }, 2500);
      }
    } catch (e) {}
  }

  global.FansHubAssets = {
    ver: ver,
    loadCss: loadCss,
    loadJs: loadJs,
    loadCssMany: loadCssMany,
    ensureChat: ensureChat,
    ensureWallet: ensureWallet,
    prefetchChat: prefetchChat
  };
})(window);
