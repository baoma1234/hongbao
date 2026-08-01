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

  function ensureChat() {
    if (global.FansHubChat) return Promise.resolve(global.FansHubChat);
    if (chatPromise) return chatPromise;
    // 单包：1 CSS + 1 JS（避免 7+6 请求与 fetch+eval）
    chatPromise = Promise.all([
      loadCss('css/chat.bundle.css'),
      loadJs('js/chat/chat.bundle.js')
    ]).then(function () {
      if (!global.FansHubChat) throw new Error('FansHubChat missing after load');
      return global.FansHubChat;
    }).catch(function (err) {
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
    // 空闲预拉单包后再连 WS（延迟加长，减少与首页接口争抢）
    var run = function () {
      ensureChat().then(function () {
        try {
          if (!localStorage.getItem('fans_hub_token')) return;
          if (global.FansHubChat && typeof global.FansHubChat.onLogin === 'function') {
            global.FansHubChat.onLogin();
          }
        } catch (e2) {}
      }).catch(function () {});
    };
    try {
      if ('requestIdleCallback' in global) {
        global.requestIdleCallback(run, { timeout: 8000 });
      } else {
        setTimeout(run, 4500);
      }
    } catch (e) {
      setTimeout(run, 4500);
    }
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
