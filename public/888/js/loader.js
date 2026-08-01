/**
 * FansHub /888 asset loader — 登录后注入大厅 DOM + 按 Tab 懒加载 CSS/JS
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
  var dashboardPromise = null;
  var tabPromises = {};
  var dashboardReady = false;

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

  function moveChildren(fromEl, toEl) {
    if (!fromEl || !toEl) return;
    while (fromEl.firstChild) {
      toEl.appendChild(fromEl.firstChild);
    }
  }

  function injectDashboardHtml(html) {
    var wrap = document.createElement('div');
    wrap.innerHTML = String(html || '');
    var tabsFrag = wrap.querySelector('#dashTabsFragment');
    var extrasFrag = wrap.querySelector('#dashExtrasFragment');
    var dash = document.getElementById('mainDashboardView');
    var extras = document.getElementById('appExtrasMount');
    if (!dash) throw new Error('mainDashboardView missing');
    if (!extras) {
      extras = document.createElement('div');
      extras.id = 'appExtrasMount';
      document.body.appendChild(extras);
    }
    dash.innerHTML = '';
    extras.innerHTML = '';
    moveChildren(tabsFrag, dash);
    moveChildren(extrasFrag, extras);
    dash.removeAttribute('data-shell');
    extras.removeAttribute('data-shell');
    dashboardReady = true;
  }

  /** 登录后注入业务 DOM（未登录页面不含大厅/闪兑/红宝等） */
  function ensureDashboard() {
    if (dashboardReady && document.getElementById('tabHome')) {
      return Promise.resolve(true);
    }
    if (dashboardPromise) return dashboardPromise;
    dashboardPromise = fetch(url('partials/dashboard.php'), {
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (res) {
        if (!res.ok) throw new Error('dashboard HTTP ' + res.status);
        return res.text();
      })
      .then(function (html) {
        injectDashboardHtml(html);
        // 大厅基础样式 + 底栏/通用弹层
        return loadCssMany([
          'css/home.css',
          'css/tabs-extra.css',
          'css/social-modals.css'
        ]);
      })
      .then(function () {
        return true;
      })
      .catch(function (err) {
        dashboardPromise = null;
        dashboardReady = false;
        throw err;
      });
    return dashboardPromise;
  }

  function clearDashboard() {
    dashboardReady = false;
    dashboardPromise = null;
    tabPromises = {};
    var dash = document.getElementById('mainDashboardView');
    var extras = document.getElementById('appExtrasMount');
    if (dash) {
      dash.innerHTML = '';
      dash.setAttribute('data-shell', 'pending');
      dash.classList.remove('active');
    }
    if (extras) {
      extras.innerHTML = '';
      extras.setAttribute('data-shell', 'pending');
    }
  }

  /** 按 Tab 只拉当前页 CSS/JS（聊天包不阻塞切页） */
  function ensureTab(tab) {
    tab = String(tab || 'home');
    return ensureDashboard().then(function () {
      if (tabPromises[tab]) return tabPromises[tab];
      var jobs = [];
      if (tab === 'home') {
        jobs.push(loadCss('css/home.css'));
      } else if (tab === 'exchange') {
        jobs.push(loadCss('css/share-swap.css'));
      } else if (tab === 'master') {
        jobs.push(loadCss('css/tabs-extra.css'));
      } else if (tab === 'profile') {
        jobs.push(loadCssMany(['css/profile.css', 'css/profile-glass.css']));
      } else if (tab === 'messages') {
        // 红宝：切页不堵 JS bundle；CSS 先落盘
        jobs.push(loadCss('css/chat.bundle.css').catch(function () { return false; }));
      }
      if (tab === 'home' || tab === 'profile') {
        // 社交二维码等：首次进大厅/我的再拉
        jobs.push(
          loadCss('css/qr-friend.css').then(function () {
            if (global.FansHubQrFriend) return true;
            return loadJs('js/qr-friend.js').catch(function () { return false; });
          })
        );
      }
      tabPromises[tab] = Promise.all(jobs).then(function () { return true; }).catch(function (err) {
        delete tabPromises[tab];
        throw err;
      });
      return tabPromises[tab];
    });
  }

  function ensureChat() {
    if (global.FansHubChat) return Promise.resolve(global.FansHubChat);
    if (chatPromise) return chatPromise;
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
    walletPromise = ensureTab('profile')
      .then(function () {
        return loadJs('js/profile-wallet.js');
      })
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
    preloadChatHints();
    var run = function () {
      if (!localStorage.getItem('fans_hub_token')) return;
      ensureChat().then(function () {
        try {
          if (global.FansHubChat && typeof global.FansHubChat.onLogin === 'function') {
            global.FansHubChat.onLogin();
          }
        } catch (e2) {}
      }).catch(function () {});
    };
    try {
      if ('requestIdleCallback' in global) {
        global.requestIdleCallback(run, { timeout: 1200 });
      } else {
        setTimeout(run, 700);
      }
    } catch (e) {
      setTimeout(run, 700);
    }
  }

  function preloadChatHints() {
    try {
      if (!document.head) return;
      [['css/chat.bundle.css', 'style'], ['js/chat/chat.bundle.js', 'script']].forEach(function (pair) {
        var href = url(pair[0]);
        if (document.querySelector('link[rel="preload"][href="' + href + '"]')) return;
        var link = document.createElement('link');
        link.rel = 'preload';
        link.as = pair[1];
        link.href = href;
        document.head.appendChild(link);
      });
    } catch (e) {}
  }

  function warmChat() {
    if (!localStorage.getItem('fans_hub_token')) return;
    preloadChatHints();
    ensureChat().catch(function () {});
  }

  function isDashboardReady() {
    return !!(dashboardReady && document.getElementById('tabHome'));
  }

  global.FansHubAssets = {
    ver: ver,
    loadCss: loadCss,
    loadJs: loadJs,
    loadCssMany: loadCssMany,
    ensureDashboard: ensureDashboard,
    ensureTab: ensureTab,
    clearDashboard: clearDashboard,
    isDashboardReady: isDashboardReady,
    ensureChat: ensureChat,
    ensureWallet: ensureWallet,
    prefetchChat: prefetchChat,
    warmChat: warmChat,
    preloadChatHints: preloadChatHints
  };
})(window);
