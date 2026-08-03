(function (global) {
"use strict";
/* === 01-core.js === */
/* js/chat/01-core.js — state, utils, unread */

  var READ_KEY = 'fans_hub_chat_read';
  var state = {
    ws: null,
    reqSeq: 1,
    pending: {},
    userId: 0,
    money: null,
    connected: false,
    connecting: false,
    list: [],
    room: null,
    messages: [],
    unread: {},
    unreadSyncTimer: null,
    reconnectTimer: null,
    reconnectAttempt: 0,
    pingTimer: null,
    loadedOnce: false,
    emojiLoaded: false,
    emojiLoading: false,
    emojiTree: [],
    emojiGroupIdx: 0,
    emojiKeyword: '',
    exprMode: 'emoji',
    stickerLoaded: false,
    stickerLoading: false,
    stickerManifest: null,
    stickerGroupIdx: 0,
    stickerKeyword: '',
    stickerQuota: { count: 0, limit: 50, is_admin: false },
    isImAdmin: false,
    canCreateGroup: false,
    groupMeta: null,
    noticeDismissed: {},
    members: [],
    memberKeyword: '',
    candidates: [],
    inviteSelected: {},
    inviteKeyword: '',
    memberActionTarget: null,
    memberSearchTimer: null,
    inviteSearchTimer: null,
    listKeyword: '',
    listSearchTimer: null,
    homeTab: 'chat',
    communitySubTab: 'official',
    recommendGroups: [],
    myGroups: [],
    friends: [],
    rpCover: {},
    senderCache: {},
    profileTarget: null,
    createGroup: {
      privacy: 'private',
      chatMode: 'chat',
      avatarEmoji: '🐵',
      submitting: false,
      bindOwnerRebate: false
    }
  };

  var CREATE_GROUP_AVATARS = ['🐵', '🐼', '🦊', '🐯', '🦁', '🐶', '🐱', '🐰', '🐻', '🐨', '🐸', '🐷'];
  var EMOJI_DATA_URL = (function () {
    var ver = (global.FANSHUB_ASSETS && global.FANSHUB_ASSETS.ver) || '';
    return 'data/emoji-tree.json' + (ver ? ('?v=' + encodeURIComponent(ver)) : '');
  })();
  var STICKERS_DATA_URL = 'data/stickers.json';

  function chatT(key, extra) {
    // Prefer fc/COPY (locale + admin merge), same as the rest of H5
    if (typeof global.fc === 'function') {
      var viaFc = global.fc(key, extra || {});
      if (viaFc) return viaFc;
    }
    var tpl = '';
    var defaults = global.FANSHUB_COPY_DEFAULTS || {};
    if (global.FanshubI18n && typeof global.FanshubI18n.text === 'function') {
      tpl = global.FanshubI18n.text(key, defaults) || '';
    }
    if (!tpl) tpl = defaults[key] || '';
    if (!tpl) return key;
    var vars = extra || {};
    Object.keys(vars).forEach(function (k) {
      tpl = tpl.replace(new RegExp('\\{' + k + '\\}', 'g'), String(vars[k]));
    });
    return tpl;
  }

  /** Map IM / WS English error codes to localized copy */
  function mapChatApiError(msg, fallbackKey) {
    msg = String(msg || '').trim();
    var codeMap = {
      'only recipient can grab': 'chat_rp_only_recipient',
      'private red packet: robot grab disabled': 'chat_rp_only_recipient',
      'robot only: members cannot send red packets': 'chat_rp_robot_only',
      'grab mode: only admin can send red packets': 'chat_rp_admin_only',
      'packet type not allowed in this group': 'chat_rp_type_not_allowed',
      'not in group': 'chat_err_not_in_group',
      'target not in group': 'chat_err_not_in_group',
      'private group': 'chat_err_private_group',
      'group unavailable': 'chat_err_group_unavailable',
      'group full': 'chat_err_group_full',
      'user not discoverable': 'chat_err_user_not_discoverable',
      'no permission': 'chat_err_no_permission',
      'invalid group': 'chat_err_invalid_group',
      'invalid params': 'chat_err_invalid_group',
      '未连接': 'chat_err_not_connected',
      '超时': 'chat_err_timeout'
    };
    if (codeMap[msg]) {
      return chatT(codeMap[msg]);
    }
    if (/^chat_[a-z0-9_]+$/i.test(msg)) {
      var viaKey = chatT(msg);
      if (viaKey && viaKey !== msg) return viaKey;
    }
    if (msg && /[\u4e00-\u9fff]/.test(msg)) return msg;
    if (fallbackKey) {
      var fb = chatT(fallbackKey);
      if (fb && fb !== fallbackKey) return fb;
    }
    return msg || (fallbackKey ? chatT(fallbackKey) : '');
  }

  function moneyText(amount) {
    var n = parseFloat(amount);
    if (isNaN(n)) n = 0;
    if (typeof global.formatMoney === 'function') {
      return global.formatMoney(n);
    }
    var sym = (global.FanshubI18n && global.FanshubI18n.currencySymbol && global.FanshubI18n.currencySymbol()) || '￥';
    return sym + n.toFixed(2);
  }
  function $(id) { return document.getElementById(id); }

  function defaultWsUrl() {
    if (global.CONFIG && CONFIG.IM_WS_URL) return String(CONFIG.IM_WS_URL);
    // 同源反代 /im-ws（nginx → 7272），避免直连额外端口被墙
    var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    var host = location.host || ((location.hostname || '127.0.0.1') + (location.port ? ':' + location.port : ''));
    return proto + '//' + host + '/im-ws';
  }

  /** 握手 URL 带 token，服务端 onConnect 即可鉴权（少一轮 RTT） */
  function connectWsUrl() {
    var base = defaultWsUrl();
    var t = token();
    if (!t) return base;
    var fp = '';
    try { fp = localStorage.getItem('fans_hub_device_fp') || ''; } catch (e) {}
    var q = 'token=' + encodeURIComponent(t);
    if (fp) q += '&device_fp=' + encodeURIComponent(fp);
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + q;
  }

  function token() {
    try { return localStorage.getItem('fans_hub_token') || ''; } catch (e) { return ''; }
  }

  function loadReadMap() {
    try {
      return JSON.parse(localStorage.getItem(READ_KEY) || '{}') || {};
    } catch (e) { return {}; }
  }

  function saveReadMap(map) {
    try { localStorage.setItem(READ_KEY, JSON.stringify(map)); } catch (e) {}
  }

  function convKey(type, id) {
    return String(type) + ':' + String(id);
  }

  function peerFromMsg(msg) {
    if (!msg || !state.userId) return 0;
    if ((msg.from_user_id | 0) === state.userId) return msg.to_user_id | 0;
    return msg.from_user_id | 0;
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function encodeUriPath(url) {
    if (!url) return '';
    if (/^https?:\/\//i.test(url)) {
      try {
        var u = new URL(url);
        u.pathname = u.pathname.split('/').map(function (seg, i) {
          if (i === 0 || seg === '') return seg;
          try { return encodeURIComponent(decodeURIComponent(seg)); } catch (e) { return encodeURIComponent(seg); }
        }).join('/');
        return u.toString();
      } catch (e) { return url; }
    }
    return String(url).split('/').map(function (seg, i) {
      if (i === 0 || seg === '') return seg;
      try { return encodeURIComponent(decodeURIComponent(seg)); } catch (e) { return encodeURIComponent(seg); }
    }).join('/');
  }

  function chatBasePath() {
    var m = location.pathname.match(/^(.*\/888\/)/);
    return m ? m[1] : '/888/';
  }

  function publicUrl(pathOrUrl) {
    if (!pathOrUrl) return '';
    var url = String(pathOrUrl);
    if (/^https?:\/\//i.test(url)) return encodeUriPath(url);
    if (url.charAt(0) !== '/') {
      url = chatBasePath().replace(/\/?$/, '/') + url.replace(/^\.\//, '');
    }
    // 内置表情：页面在 /888/ 下，用同目录相对路径最稳
    if (url.indexOf('/888/stickers/') === 0) {
      return encodeUriPath(url.replace(/^\/888\//, ''));
    }
    if (url.indexOf('/stickers/') === 0) {
      return encodeUriPath(url.replace(/^\//, ''));
    }
    if (url.indexOf('/uploads/') === 0) {
      return encodeUriPath(location.origin + url);
    }
    if (url.charAt(0) === '/') {
      return encodeUriPath(location.origin + url);
    }
    return encodeUriPath(url);
  }

  /** 无头像时的默认吉祥物 */
  function defaultAvatarUrl() {
    var ver = (global.FANSHUB_ASSETS && global.FANSHUB_ASSETS.ver) || '';
    return 'img/default-avatar.png' + (ver ? ('?v=' + encodeURIComponent(ver)) : '');
  }

  function avatarSrc(url) {
    var u = publicUrl(url || '');
    return u || defaultAvatarUrl();
  }

  function avatarImgHtml(url, cls) {
    var src = avatarSrc(url);
    return '<img' + (cls ? (' class="' + cls + '"') : '') + ' src="' + escapeHtml(src) + '" alt="">';
  }

  function buildStickerLookup(manifest) {
    var map = {};
    getStickerCategories(manifest || {}).forEach(function (cat) {
      (cat.items || []).forEach(function (item) {
        if (!item || !item.code || !item.url) return;
        map[item.code] = {
          pack: item.pack || cat.packId || 'wechat',
          code: item.code,
          url: item.url
        };
      });
    });
    return map;
  }

  function resolveStickerFromContent(content) {
    var m = String(content || '').match(/^\[(.+)\]$/);
    if (!m) return null;
    var map = (state.stickerManifest && state.stickerManifest._lookup) || {};
    return map[m[1]] || null;
  }

  function renderStickerBubble(mine, time, stExtra, fallbackContent) {
    var stUrl = publicUrl((stExtra && (stExtra.url || stExtra.fullurl)) || '');
    var code = (stExtra && stExtra.code) || '';
    var stHtml = stUrl
      ? '<img class="chat-sticker-img" src="' + escapeHtml(stUrl) + '" alt="' + escapeHtml(code || '表情') + '" data-preview="' + escapeHtml(stUrl) + '" data-preview-type="image">'
      : escapeHtml(fallbackContent || (code ? ('[' + code + ']') : '[表情]'));
    return (
      '<div class="chat-msg-row' + (mine ? ' me' : '') + '">' +
        '<div class="chat-bubble sticker">' + stHtml +
          '<span class="meta">' + time + '</span></div>' +
      '</div>'
    );
  }

  function ensureMediaLightbox() {
    var box = $('chatMediaLightbox');
    if (box) return box;
    box = document.createElement('div');
    box.id = 'chatMediaLightbox';
    box.className = 'chat-media-lightbox';
    box.setAttribute('aria-hidden', 'true');
    box.innerHTML =
      '<button type="button" class="chat-media-lightbox-close" id="chatMediaLightboxClose" aria-label="关闭">×</button>' +
      '<div class="chat-media-lightbox-body" id="chatMediaLightboxBody"></div>';
    document.body.appendChild(box);
    return box;
  }

  function closeMediaLightbox() {
    var box = $('chatMediaLightbox');
    if (!box) return;
    box.classList.remove('open');
    box.setAttribute('aria-hidden', 'true');
    var body = $('chatMediaLightboxBody');
    if (body) {
      var vid = body.querySelector('video');
      if (vid) {
        try { vid.pause(); } catch (e) {}
      }
      body.innerHTML = '';
    }
  }

  function openMediaLightbox(src, type) {
    src = String(src || '').trim();
    if (!src) return;
    type = type === 'video' ? 'video' : 'image';
    var box = ensureMediaLightbox();
    var body = $('chatMediaLightboxBody');
    if (!body) return;
    if (type === 'video') {
      body.innerHTML = '<video class="chat-media-lightbox-video" src="' + escapeHtml(src) + '" controls playsinline autoplay></video>';
    } else {
      body.innerHTML = '<img class="chat-media-lightbox-img" src="' + escapeHtml(src) + '" alt="预览">';
    }
    box.classList.add('open');
    box.setAttribute('aria-hidden', 'false');
  }

  function mergeStickerManifest(base, customPayload) {
    var packs = (base && base.packs) ? base.packs.slice() : [];
    var customItems = (customPayload && customPayload.items) || [];
    if (customItems.length) {
      packs.unshift({
        id: 'custom',
        name: '我的',
        categories: [{ id: 'mine', name: '自定义', items: customItems }]
      });
    }
    var manifest = {
      version: 1,
      packs: packs,
      quota: customPayload || { count: 0, limit: 50, is_admin: false }
    };
    manifest._lookup = buildStickerLookup(manifest);
    return manifest;
  }

  function getStickerCategories(manifest) {
    var categories = [];
    (manifest.packs || []).forEach(function (pack) {
      (pack.categories || []).forEach(function (cat) {
        categories.push({
          packId: pack.id || '',
          packName: pack.name || '',
          id: cat.id || '',
          name: cat.name || pack.name || '',
          items: cat.items || []
        });
      });
    });
    return categories;
  }

  function stickerQuotaText() {
    var q = state.stickerQuota || {};
    if (q.is_admin) return '管理员不限数量';
    var limit = q.limit | 0;
    var count = q.count | 0;
    if (!limit) return '';
    return count + '/' + limit;
  }

  function emojiGroupLabel(group) {
    if (!group) return '';
    var i18n = group.name_i18n || {};
    return i18n.zh_CN || i18n.zh || group.name || '';
  }

  function emojiItemLabel(item) {
    if (!item) return '';
    var i18n = item.name_i18n || {};
    return i18n.zh_CN || i18n.zh || item.name || '';
  }

  /** 过滤旧系统常显示为方块的新 emoji（如 🫡 U+1FAE1） */
  function emojiCodesTooNew(codes) {
    var parts = String(codes || '').split(/[^0-9A-Fa-f]+/);
    var ignore = {
      FE0E: 1, FE0F: 1, '200D': 1, '20E3': 1,
      '1F3FB': 1, '1F3FC': 1, '1F3FD': 1, '1F3FE': 1, '1F3FF': 1
    };
    for (var i = 0; i < parts.length; i++) {
      var hex = parts[i];
      if (!hex || ignore[hex.toUpperCase()]) continue;
      var cp = parseInt(hex, 16);
      if (!cp && cp !== 0) continue;
      if (cp >= 0x1FA00 && cp <= 0x1FAFF) return true;
      if (cp === 0x1F90C) return true;
    }
    return false;
  }

  function filterCompatibleEmojiTree(tree) {
    if (!Array.isArray(tree)) return [];
    function walk(list) {
      var out = [];
      (list || []).forEach(function (node) {
        if (!node) return;
        if (node.list) {
          var child = walk(node.list);
          if (child.length) {
            var copy = {};
            Object.keys(node).forEach(function (k) { copy[k] = node[k]; });
            copy.list = child;
            out.push(copy);
          }
          return;
        }
        if (node.char && !emojiCodesTooNew(node.codes)) out.push(node);
      });
      return out;
    }
    return walk(tree);
  }

  function flattenEmojiGroup(group) {
    var list = [];
    if (!group || !group.list) return list;
    group.list.forEach(function (sub) {
      if (!sub || !sub.list) return;
      sub.list.forEach(function (item) {
        if (item && item.char && !emojiCodesTooNew(item.codes)) list.push(item);
      });
    });
    return list;
  }

  function collectEmojiItems(keyword) {
    var kw = String(keyword || '').trim().toLowerCase();
    var out = [];
    state.emojiTree.forEach(function (group) {
      flattenEmojiGroup(group).forEach(function (item) {
        if (!kw) {
          out.push(item);
          return;
        }
        var label = emojiItemLabel(item).toLowerCase();
        var en = String(item.name || '').toLowerCase();
        if (label.indexOf(kw) >= 0 || en.indexOf(kw) >= 0 || String(item.char || '').indexOf(kw) >= 0) {
          out.push(item);
        }
      });
    });
    return out;
  }

  function insertEmojiIntoInput(em) {
    if (state.room && state.room.type === 2 && typeof canSendCapability === 'function' && !canSendCapability('emoji')) {
      if (typeof showFanshubToast === 'function') showFanshubToast('本群禁止发表情', 'error');
      return;
    }
    var input = $('chatInput');
    if (!input || !em) return;
    input.value += em;
    input.focus();
  }

  function renderExpressionPanelContent() {
    var panel = $('chatEmojiPanel');
    if (!panel) return;
    var modeTabs =
      '<div class="chat-expr-mode-tabs">' +
        '<button type="button" class="chat-expr-mode-btn' + (state.exprMode === 'emoji' ? ' active' : '') + '" data-mode="emoji">Emoji</button>' +
        '<button type="button" class="chat-expr-mode-btn' + (state.exprMode === 'sticker' ? ' active' : '') + '" data-mode="sticker">表情包</button>' +
      '</div>';
    if (state.exprMode === 'sticker') {
      if (state.stickerLoading) {
        panel.innerHTML = modeTabs + '<div class="chat-emoji-status">表情包加载中…</div>';
        return;
      }
      if (!state.stickerLoaded || !state.stickerManifest) {
        panel.innerHTML = modeTabs + '<div class="chat-emoji-status">表情包加载失败</div>';
        return;
      }
      var categories = getStickerCategories(state.stickerManifest);
      var stickerItems;
      if (state.stickerKeyword) {
        stickerItems = [];
        categories.forEach(function (cat) {
          (cat.items || []).forEach(function (item) {
            if ((item.code || '').indexOf(state.stickerKeyword) >= 0) stickerItems.push(item);
          });
        });
      } else {
        var scat = categories[state.stickerGroupIdx] || categories[0] || { items: [] };
        stickerItems = scat.items || [];
      }
      var scTabs = '';
      if (!state.stickerKeyword && categories.length) {
        scTabs = '<div class="chat-emoji-tabs">' + categories.map(function (cat, idx) {
          return '<button type="button" class="chat-emoji-tab' + (idx === state.stickerGroupIdx ? ' active' : '') + '" data-sticker-idx="' + idx + '">' + escapeHtml(cat.name || cat.id || '') + '</button>';
        }).join('') + '</div>';
      }
      var uploadBar =
        '<div class="chat-sticker-upload-bar">' +
          '<button type="button" class="chat-sticker-upload-btn" id="chatStickerUploadBtn">＋ 上传表情</button>' +
          '<span class="chat-sticker-quota" id="chatStickerQuota">' + escapeHtml(stickerQuotaText()) + '</span>' +
        '</div>';
      var sGrid;
      if (!stickerItems.length) {
        sGrid = '<div class="chat-emoji-status">' + (state.stickerKeyword ? '未找到相关表情' : '暂无表情，可上传 gif/png/jpg') + '</div>';
      } else {
        sGrid = '<div class="chat-sticker-grid">' + stickerItems.map(function (item) {
          var src = publicUrl(item.url || '');
          var pack = item.pack || 'wechat';
          return '<button type="button" class="chat-sticker-item" data-code="' + escapeHtml(item.code || '') + '" data-url="' + escapeHtml(item.url || '') + '" data-pack="' + escapeHtml(pack) + '" title="' + escapeHtml(item.code || '') + '">' +
            '<img src="' + escapeHtml(src) + '" alt="' + escapeHtml(item.code || '') + '" loading="lazy">' +
          '</button>';
        }).join('') + '</div>';
      }
      panel.innerHTML = modeTabs +
        uploadBar +
        '<div class="chat-emoji-toolbar">' +
          '<input type="search" class="chat-emoji-search" id="chatStickerSearch" placeholder="搜索表情包，如：微笑、捂脸" value="' + escapeHtml(state.stickerKeyword) + '">' +
        '</div>' + scTabs + sGrid;
      return;
    }
    if (state.emojiLoading) {
      panel.innerHTML = modeTabs + '<div class="chat-emoji-status">表情加载中…</div>';
      return;
    }
    if (!state.emojiLoaded) {
      panel.innerHTML = modeTabs + '<div class="chat-emoji-status">Emoji 加载失败</div>';
      return;
    }
    var items;
    if (state.emojiKeyword) {
      items = collectEmojiItems(state.emojiKeyword);
    } else {
      var group = state.emojiTree[state.emojiGroupIdx] || state.emojiTree[0];
      items = flattenEmojiGroup(group);
    }
    var tabsHtml = '';
    if (!state.emojiKeyword && state.emojiTree.length) {
      tabsHtml = '<div class="chat-emoji-tabs">' + state.emojiTree.map(function (group, idx) {
        var label = escapeHtml(emojiGroupLabel(group));
        return '<button type="button" class="chat-emoji-tab' + (idx === state.emojiGroupIdx ? ' active' : '') + '" data-idx="' + idx + '">' + label + '</button>';
      }).join('') + '</div>';
    }
    var gridHtml;
    if (!items.length) {
      gridHtml = '<div class="chat-emoji-status">未找到相关表情</div>';
    } else {
      gridHtml = '<div class="chat-emoji-grid">' + items.map(function (item) {
        var em = item.char || '';
        var title = escapeHtml(emojiItemLabel(item));
        return '<button type="button" class="chat-emoji-item" data-emoji="' + escapeHtml(em) + '" title="' + title + '">' + em + '</button>';
      }).join('') + '</div>';
    }
    panel.innerHTML = modeTabs +
      '<div class="chat-emoji-toolbar">' +
        '<input type="search" class="chat-emoji-search" id="chatEmojiSearch" placeholder="搜索 Emoji，如：笑脸、爱心" value="' + escapeHtml(state.emojiKeyword) + '">' +
      '</div>' +
      tabsHtml +
      gridHtml;
  }

  function refocusPanelSearch(id) {
    var next = $(id);
    if (!next) return;
    next.focus();
    try { next.setSelectionRange(next.value.length, next.value.length); } catch (e) {}
  }

  async function ensureEmojiTreeLoaded() {
    if (state.emojiLoaded || state.emojiLoading) return;
    state.emojiLoading = true;
    renderExpressionPanelContent();
    try {
      var res = await fetch(EMOJI_DATA_URL, { cache: 'force-cache' });
      if (!res.ok) throw new Error('load failed');
      var tree = await res.json();
      if (!Array.isArray(tree) || !tree.length) throw new Error('empty emoji data');
      state.emojiTree = filterCompatibleEmojiTree(tree);
      if (!state.emojiTree.length) throw new Error('empty emoji data');
      state.emojiLoaded = true;
      state.emojiGroupIdx = 0;
    } catch (e) {
      state.emojiTree = [];
      state.emojiLoaded = false;
      if (typeof showFanshubToast === 'function') showFanshubToast('表情库加载失败', 'error');
    } finally {
      state.emojiLoading = false;
      renderExpressionPanelContent();
    }
  }

  async function ensureStickersLoaded(force) {
    if (!force && (state.stickerLoaded || state.stickerLoading)) return;
    state.stickerLoading = true;
    renderExpressionPanelContent();
    try {
      var baseRes = await fetch(STICKERS_DATA_URL, { cache: 'force-cache' });
      if (!baseRes.ok) throw new Error('load failed');
      var base = await baseRes.json();
      var custom = { count: 0, limit: 50, is_admin: false, items: [] };
      if (token() && typeof global.apiRequest === 'function') {
        try {
          custom = await global.apiRequest('stickerlist', 'GET', {});
        } catch (e) {}
      }
      state.stickerManifest = mergeStickerManifest(base, custom);
      state.stickerQuota = {
        count: custom.count | 0,
        limit: custom.limit | 0,
        is_admin: !!custom.is_admin
      };
      state.stickerLoaded = true;
      state.stickerGroupIdx = 0;
      if (state.room && state.messages && state.messages.length) {
        renderMessages();
      }
    } catch (e) {
      state.stickerManifest = null;
      state.stickerLoaded = false;
      if (typeof showFanshubToast === 'function') showFanshubToast('表情包加载失败', 'error');
    } finally {
      state.stickerLoading = false;
      renderExpressionPanelContent();
    }
  }

  async function uploadCustomSticker(file) {
    if (!file) return;
    var q = state.stickerQuota || {};
    if (!q.is_admin && (q.limit | 0) > 0 && (q.count | 0) >= (q.limit | 0)) {
      if (typeof showFanshubToast === 'function') showFanshubToast('最多上传 ' + q.limit + ' 个自定义表情', 'error');
      return;
    }
    if (typeof showFanshubToast === 'function') showFanshubToast('上传中…', 'info');
    try {
      var data;
      if (typeof global.uploadFanshubFile === 'function') {
        data = await global.uploadFanshubFile('stickerupload', file);
      } else {
        var fd = new FormData();
        fd.append('file', file);
        var headers = {};
        var t = token();
        if (t) headers.token = t;
        var res = await fetch(apiBase() + '/api/fanshub/stickerupload', { method: 'POST', headers: headers, body: fd });
        var json = await res.json();
        if (json.code !== 1) throw new Error(json.msg || json.message || '上传失败');
        data = json.data;
      }
      state.stickerQuota = {
        count: (data && data.count) | 0,
        limit: (data && data.limit) | 0,
        is_admin: !!(data && data.is_admin)
      };
      await ensureStickersLoaded(true);
      if (typeof showFanshubToast === 'function') showFanshubToast('上传成功', 'success');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '上传失败', 'error');
    }
  }

  async function sendSticker(code, relUrl, pack) {
    if (!state.room) {
      if (typeof showFanshubToast === 'function') showFanshubToast('请先打开会话', 'info');
      return;
    }
    if (state.room.type === 2 && typeof canSendCapability === 'function' && !canSendCapability('emoji')) {
      if (typeof showFanshubToast === 'function') showFanshubToast('本群禁止发表情', 'error');
      return;
    }
    code = String(code || '').trim();
    relUrl = String(relUrl || '').trim();
    pack = String(pack || 'wechat').trim();
    if (!code || !relUrl) return;
    var path = relUrl.charAt(0) === '/' ? relUrl : assetPath(relUrl);
    try {
      var msg = await sendPayload({
        msg_type: 6,
        content: '[' + code + ']',
        extra: {
          pack: pack,
          code: code,
          url: path,
          fullurl: publicUrl(path)
        }
      });
      appendSentMessage(msg);
      closeComposerPanels();
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '发送失败', 'error');
    }
  }

  function apiBase() {
    return (global.API_BASE != null ? String(global.API_BASE) : '') || '';
  }

  function parseExtra(msg) {
    var extra = msg && msg.extra;
    if (typeof extra === 'string') {
      try { extra = JSON.parse(extra); } catch (e) { extra = {}; }
    }
    return extra && typeof extra === 'object' ? extra : {};
  }

  function mediaUrl(extra) {
    if (!extra) return '';
    return publicUrl(extra.fullurl || extra.url || '');
  }

  function assetPath(rel) {
    if (!rel) return '';
    if (/^https?:\/\//i.test(rel)) {
      try { return new URL(rel).pathname; } catch (e) { return rel; }
    }
    if (rel.charAt(0) === '/') return rel;
    return chatBasePath().replace(/\/?$/, '/') + rel.replace(/^\.\//, '');
  }

  function isEmojiOnlyText(text) {
    var t = String(text || '').trim();
    if (!t || t.length > 24) return false;
    // 含中日韩/字母/数字的短句不能当「纯表情」——否则会透明气泡+白色字导致空白
    if (/[\u4e00-\u9fff\u3400-\u4dbf\u3040-\u30ff\uac00-\ud7af0-9a-zA-Z]/i.test(t)) return false;
    // 须含 emoji / 杂项符号
    return /(?:[\uD800-\uDBFF][\uDC00-\uDFFF]|[\u2600-\u27BF])/.test(t);
  }

  function closeComposerPanels() {
    var emojiPanel = $('chatEmojiPanel');
    var attachPanel = $('chatAttachPanel');
    var emojiBtn = $('chatEmojiBtn');
    var attachBtn = $('chatAttachBtn');
    if (emojiPanel) {
      emojiPanel.classList.remove('open');
      emojiPanel.setAttribute('aria-hidden', 'true');
    }
    if (attachPanel) {
      attachPanel.classList.remove('open');
      attachPanel.setAttribute('aria-hidden', 'true');
    }
    if (emojiBtn) emojiBtn.classList.remove('active');
    if (attachBtn) attachBtn.classList.remove('active');
  }

  function toggleEmojiPanel() {
    if (state.room && state.room.type === 2 && typeof canSendCapability === 'function' && !canSendCapability('emoji')) {
      if (typeof showFanshubToast === 'function') showFanshubToast('本群禁止发表情', 'error');
      return;
    }
    var panel = $('chatEmojiPanel');
    var attachPanel = $('chatAttachPanel');
    var btn = $('chatEmojiBtn');
    var attachBtn = $('chatAttachBtn');
    if (!panel) return;
    var open = !panel.classList.contains('open');
    panel.classList.toggle('open', open);
    panel.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (btn) btn.classList.toggle('active', open);
    if (open && attachPanel) {
      attachPanel.classList.remove('open');
      attachPanel.setAttribute('aria-hidden', 'true');
      if (attachBtn) attachBtn.classList.remove('active');
    }
    if (open) {
      buildEmojiPanel();
      ensureEmojiTreeLoaded();
      ensureStickersLoaded();
    }
  }

  function toggleAttachPanel() {
    var panel = $('chatAttachPanel');
    var emojiPanel = $('chatEmojiPanel');
    var btn = $('chatAttachBtn');
    var emojiBtn = $('chatEmojiBtn');
    if (!panel) return;
    var open = !panel.classList.contains('open');
    panel.classList.toggle('open', open);
    panel.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (btn) btn.classList.toggle('active', open);
    if (open && emojiPanel) {
      emojiPanel.classList.remove('open');
      emojiPanel.setAttribute('aria-hidden', 'true');
      if (emojiBtn) emojiBtn.classList.remove('active');
    }
  }

  function buildEmojiPanel() {
    var panel = $('chatEmojiPanel');
    if (!panel || panel._built) return;
    panel._built = true;
    renderExpressionPanelContent();
    panel.addEventListener('input', function (ev) {
      if (ev.target && ev.target.id === 'chatEmojiSearch') {
        state.emojiKeyword = String(ev.target.value || '').trim();
        renderExpressionPanelContent();
        refocusPanelSearch('chatEmojiSearch');
      }
      if (ev.target && ev.target.id === 'chatStickerSearch') {
        state.stickerKeyword = String(ev.target.value || '').trim();
        renderExpressionPanelContent();
        refocusPanelSearch('chatStickerSearch');
      }
    });
    panel.addEventListener('click', function (ev) {
      var modeBtn = ev.target.closest('.chat-expr-mode-btn');
      if (modeBtn) {
        state.exprMode = modeBtn.getAttribute('data-mode') || 'emoji';
        renderExpressionPanelContent();
        return;
      }
      var tab = ev.target.closest('.chat-emoji-tab');
      if (tab) {
        if (tab.hasAttribute('data-sticker-idx')) {
          state.stickerGroupIdx = parseInt(tab.getAttribute('data-sticker-idx'), 10) || 0;
          state.stickerKeyword = '';
        } else {
          state.emojiGroupIdx = parseInt(tab.getAttribute('data-idx'), 10) || 0;
          state.emojiKeyword = '';
        }
        renderExpressionPanelContent();
        return;
      }
      var sticker = ev.target.closest('.chat-sticker-item');
      if (sticker) {
        sendSticker(
          sticker.getAttribute('data-code') || '',
          sticker.getAttribute('data-url') || '',
          sticker.getAttribute('data-pack') || 'wechat'
        );
        return;
      }
      if (ev.target.closest('#chatStickerUploadBtn')) {
        var stickerInput = $('chatStickerUploadInput');
        if (stickerInput) stickerInput.click();
        return;
      }
      var btn = ev.target.closest('.chat-emoji-item');
      if (!btn) return;
      insertEmojiIntoInput(btn.getAttribute('data-emoji') || btn.textContent || '');
    });
  }

  function formatTime(ts) {
    ts = (ts | 0) * 1000;
    if (!ts) return '';
    var d = new Date(ts);
    var now = new Date();
    var hh = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    if (d.toDateString() === now.toDateString()) return hh;
    return (d.getMonth() + 1) + '/' + d.getDate() + ' ' + hh;
  }

  /** 红包发送/领取：精确到秒 */
  function formatTimeSec(ts) {
    ts = (ts | 0) * 1000;
    if (!ts) return '';
    var d = new Date(ts);
    var now = new Date();
    var pad = function (n) { return ('0' + n).slice(-2); };
    var hms = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    if (d.toDateString() === now.toDateString()) return hms;
    return (d.getMonth() + 1) + '/' + d.getDate() + ' ' + hms;
  }

  function previewText(msg) {
    if (!msg) return '暂无消息';
    if ((msg.status | 0) === 2) return '[已撤回]';
    var type = msg.msg_type | 0;
    if (type === 2) return '[红包] ' + (msg.content || '').replace(/^\[红包\]/, '');
    if (type === 8) {
      var tExtra = parseExtra(msg);
      var amt = tExtra.amount != null ? Number(tExtra.amount) : NaN;
      if (!isNaN(amt)) return '[转账] ￥' + amt.toFixed(2);
      return '[转账]';
    }
    if (type === 3) return msg.content || '[系统消息]';
    if (type === 4) return '[图片]';
    if (type === 5) return '[视频]';
    if (type === 6) {
      var sExtra = parseExtra(msg);
      return '[' + (sExtra.code || msg.content || '表情').replace(/^\[|\]$/g, '') + ']';
    }
    if (type === 7) {
      var fExtra = parseExtra(msg);
      return '[文件] ' + (fExtra.name || msg.content || '');
    }
    return msg.content || '';
  }

  function setConnStatus(text, cls) {
    var el = $('chatConnStatus');
    if (!el) return;
    el.textContent = text;
    el.className = 'chat-conn' + (cls ? ' ' + cls : '');
  }

  function refreshConnStatusLabel() {
    if (state.connected) {
      setConnStatus(chatT('chat_conn_ok'), 'ok');
    } else if (state.connecting) {
      setConnStatus(chatT('chat_conn_connecting'), '');
    } else {
      setConnStatus(chatT('chat_conn_off'), '');
    }
  }

  function updateMoneyLabel() {
    var el = $('chatMyId');
    if (!el) return;
    var id = state.userId || '-';
    var hasBal = state.money != null && !isNaN(state.money);
    var amount = hasBal ? moneyText(state.money) : '';
    if (state.isImAdmin && hasBal) {
      el.textContent = chatT('chat_my_id_admin_balance', { id: id, amount: amount });
    } else if (state.isImAdmin) {
      el.textContent = chatT('chat_my_id_admin', { id: id });
    } else if (hasBal) {
      el.textContent = chatT('chat_my_id_with_balance', { id: id, amount: amount });
    } else {
      el.textContent = chatT('chat_my_id', { id: id });
    }
  }

  function syncBalanceFromAccount() {
    try {
      var el = document.getElementById('myHongbaoPool') || document.getElementById('myUserBalance');
      if (el) {
        var n = parseFloat(String(el.textContent || '').replace(/,/g, ''));
        if (!isNaN(n)) {
          state.money = n;
        }
      }
      updateMoneyLabel();
      var balEl = $('chatRpBalance');
      if (balEl && state.money != null && !isNaN(state.money)) {
        balEl.textContent = moneyText(state.money);
      }
    } catch (e) {}
  }

  function sendViaWs(type, data, opts) {
    opts = opts || {};
    var timeoutMs = (opts.timeoutMs | 0) || 15000;
    return new Promise(function (resolve, reject) {
      if (!state.ws || state.ws.readyState !== 1) {
        reject(new Error('未连接'));
        return;
      }
      var reqId = 'r' + (state.reqSeq++);
      state.pending[reqId] = { resolve: resolve, reject: reject, type: type };
      state.ws.send(JSON.stringify({ type: type, data: data || {}, req_id: reqId }));
      setTimeout(function () {
        if (state.pending[reqId]) {
          delete state.pending[reqId];
          reject(new Error('超时'));
        }
      }, timeoutMs);
    });
  }

  function defaultImHttpBase() {
    try {
      if (window.FANS_HUB_IM_HTTP) return String(window.FANS_HUB_IM_HTTP).replace(/\/$/, '');
    } catch (e0) {}
    try {
      if (global.CONFIG && CONFIG.IM_HTTP_BASE) return String(CONFIG.IM_HTTP_BASE).replace(/\/$/, '');
    } catch (e1) {}
    // 同源反代 /im-api（nginx → 7273），与页面同端口，避免跨端口/防火墙
    var origin = '';
    try { origin = location.origin; } catch (e2) { origin = ''; }
    if (!origin) {
      origin = (location.protocol || 'http:') + '//' + (location.host || location.hostname || '127.0.0.1');
    }
    return origin.replace(/\/$/, '') + '/im-api';
  }

  /** HTTP 优先路由（失败回退 WS） */
  var HTTP_ROUTES = {
    'conversation.list': '/im/conversations',
    'history': '/im/history',
    'redpacket.send': '/im/redpacket/send',
    'redpacket.grab': '/im/redpacket/grab',
    'redpacket.detail': '/im/redpacket/detail',
    'transfer.send': '/im/transfer/send',
    'friend.list': '/im/friend/list',
    'friend.lookup': '/im/friend/lookup',
    'friend.request': '/im/friend/request',
    'friend.add': '/im/friend/add',
    'friend.requests': '/im/friend/requests',
    'friend.accept': '/im/friend/accept',
    'friend.reject': '/im/friend/reject',
    'friend.cancel': '/im/friend/cancel',
    'group.list': '/im/group/list',
    'group.join': '/im/group/join',
    'group.info': '/im/group/info',
    'group.leave': '/im/group/leave',
    'group.create': '/im/group/create',
    'group.update': '/im/group/update',
    'group.members': '/im/group/members',
    'group.kick': '/im/group/kick',
    'group.mute': '/im/group/mute',
    'group.set_admin': '/im/group/set_admin',
    'group.mute_all': '/im/group/mute_all',
    'group.set_forbid': '/im/group/set_forbid',
    'group.candidates': '/im/group/candidates',
    'group.add_members': '/im/group/add_members'
  };

  /** HTTP 写读接口（失败则回退 WS） */
  function sendViaHttp(type, data) {
    var path = HTTP_ROUTES[type];
    if (!path) return Promise.reject(new Error('no http route'));
    var body = Object.assign({ token: token() }, data || {});
    var ctrl = null;
    var timer = null;
    try { ctrl = new AbortController(); } catch (e1) { ctrl = null; }
    var fetchOpts = {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Fans-Token': token() },
      body: JSON.stringify(body),
      credentials: 'omit'
    };
    if (ctrl) fetchOpts.signal = ctrl.signal;
    var p = fetch(defaultImHttpBase() + path, fetchOpts).then(function (res) {
      return res.json().then(function (json) {
        if (!res.ok || (json && json.code === 0)) {
          throw new Error((json && json.message) || ('HTTP ' + res.status));
        }
        var respType = (json && json.ws_type) || type;
        var payload;
        if (json && Object.prototype.hasOwnProperty.call(json, 'data') && json.data != null) {
          payload = json.data;
        } else {
          payload = Object.assign({}, json || {});
          try { delete payload.code; delete payload.ws_type; delete payload.message; } catch (eStrip) {}
        }
        return { type: respType, data: payload, via: 'http' };
      });
    });
    if (ctrl) {
      timer = setTimeout(function () { try { ctrl.abort(); } catch (e2) {} }, 12000);
      p = p.then(function (v) { clearTimeout(timer); return v; }, function (err) {
        clearTimeout(timer);
        throw err;
      });
    }
    return p;
  }

  function send(type, data, opts) {
    // 非聊天写读优先 HTTP，不占 WS Worker；仅网络类失败再回退 WS
    // 注意：业务错误（如仅机器人可发）必须直接抛出，不可再走 WS，否则旧 Worker 会放行
    if (HTTP_ROUTES[type]) {
      return sendViaHttp(type, data).catch(function (httpErr) {
        var hm = String((httpErr && httpErr.message) || '');
        var isBiz = hm && hm !== '未连接' && hm !== '超时'
          && hm !== 'Failed to fetch'
          && hm !== 'The user aborted a request.'
          && hm.indexOf('NetworkError') < 0
          && hm.indexOf('Load failed') < 0
          && hm.indexOf('HTTP ') !== 0;
        if (isBiz) {
          throw httpErr;
        }
        return sendViaWs(type, data, opts).catch(function (wsErr) {
          throw wsErr || httpErr;
        });
      });
    }
    return sendViaWs(type, data, opts);
  }

  function resolvePending(packet) {
    var reqId = packet.req_id || '';
    if (!reqId || !state.pending[reqId]) return false;
    var p = state.pending[reqId];
    delete state.pending[reqId];
    if (packet.type === 'error') {
      p.reject(new Error((packet.data && packet.data.message) || 'error'));
    } else {
      p.resolve(packet);
    }
    return true;
  }

  function totalUnread() {
    var n = 0;
    Object.keys(state.unread).forEach(function (k) { n += state.unread[k] | 0; });
    return n;
  }

  function updateTabBadge() {
    var btn = document.querySelector('#bottomActionBar .tab-btn[data-tab="messages"]');
    var badge = $('chatTabBadge');
    var n = totalUnread();
    if (btn) btn.classList.toggle('has-chat-unread', n > 0);
    if (badge) {
      badge.textContent = n > 99 ? '99+' : String(n);
      badge.style.display = n > 0 ? 'inline-block' : 'none';
    }
  }

  function markRead(type, id, lastMsgId) {
    var key = convKey(type, id);
    state.unread[key] = 0;
    var map = loadReadMap();
    map[key] = Math.max(map[key] | 0, lastMsgId | 0);
    saveReadMap(map);
    updateTabBadge();
    if (typeof scheduleRenderList === 'function') scheduleRenderList();
    else renderList();
    if (state.connected && lastMsgId > 0) {
      send('conversation.read', {
        conversation_type: type | 0,
        conversation_id: String(id),
        last_read_msg_id: lastMsgId | 0
      }).catch(function () {});
    }
  }

  function bumpUnread(type, id, msgId) {
    var key = convKey(type, id);
    if (state.room && state.room.type === type && String(state.room.id) === String(id)) {
      markRead(type, id, msgId);
      return;
    }
    var map = loadReadMap();
    if (msgId > 0 && (msgId | 0) <= (map[key] | 0)) return;
    state.unread[key] = (state.unread[key] | 0) + 1;
    updateTabBadge();
    if (typeof scheduleRenderList === 'function') scheduleRenderList();
    else renderList();
  }

/* === 02-room.js === */
/* js/chat/02-room.js — list, room, group, media */

  var _renderListTimer = null;
  var _saveListCacheTimer = null;
  var _saveHistCacheTimer = null;
  var LIST_CACHE_PREFIX = 'fans_hub_chat_list_v1_';
  var HIST_CACHE_PREFIX = 'fans_hub_chat_hist_v1_';
  var CACHE_UID_KEY = 'fans_hub_chat_cache_uid';

  function cacheUid() {
    var uid = state.userId | 0;
    if (uid > 0) {
      try { localStorage.setItem(CACHE_UID_KEY, String(uid)); } catch (e0) {}
      return uid;
    }
    try { return parseInt(localStorage.getItem(CACHE_UID_KEY) || '0', 10) || 0; } catch (e1) { return 0; }
  }

  function loadListCache() {
    var uid = cacheUid();
    if (!uid) return null;
    try {
      var raw = localStorage.getItem(LIST_CACHE_PREFIX + uid);
      if (!raw) return null;
      var j = JSON.parse(raw);
      if (!j || !Array.isArray(j.list) || !j.list.length) return null;
      if (j.at && (Date.now() - (j.at | 0)) > 7 * 86400000) return null;
      return j;
    } catch (e) {
      return null;
    }
  }

  function saveListCache() {
    var uid = cacheUid();
    if (!uid || !state.list || !state.list.length) return;
    try {
      localStorage.setItem(LIST_CACHE_PREFIX + uid, JSON.stringify({
        at: Date.now(),
        list: state.list,
        unread: state.unread || {}
      }));
    } catch (e) {}
  }

  function scheduleSaveListCache() {
    if (_saveListCacheTimer) return;
    _saveListCacheTimer = setTimeout(function () {
      _saveListCacheTimer = null;
      saveListCache();
    }, 400);
  }

  /** 用本地缓存秒开列表；无缓存返回 false */
  function hydrateListFromCache() {
    if (state.list && state.list.length) return true;
    var j = loadListCache();
    if (!j) return false;
    state.list = j.list || [];
    var cachedUnread = j.unread || {};
    Object.keys(cachedUnread).forEach(function (k) {
      state.unread[k] = Math.max(state.unread[k] | 0, cachedUnread[k] | 0);
    });
    renderList();
    updateTabBadge();
    return true;
  }

  function showListSkeleton() {
    var box = $('chatConvList');
    if (!box) return;
    if (box.querySelector('.chat-conv-item')) return;
    var html = '';
    for (var i = 0; i < 7; i++) {
      html += '<div class="chat-conv-skel" aria-hidden="true">' +
        '<div class="sk-av"></div>' +
        '<div class="sk-body"><div class="sk-line w70"></div><div class="sk-line w45"></div></div>' +
      '</div>';
    }
    box.innerHTML = html;
  }

  function histCacheStorageKey(type, id) {
    return HIST_CACHE_PREFIX + cacheUid() + '_' + (type | 0) + '_' + String(id);
  }

  function loadHistCache(type, id) {
    if (!cacheUid()) return null;
    try {
      var raw = localStorage.getItem(histCacheStorageKey(type, id));
      if (!raw) return null;
      var j = JSON.parse(raw);
      if (!j || !Array.isArray(j.messages) || !j.messages.length) return null;
      if (j.at && (Date.now() - (j.at | 0)) > 3 * 86400000) return null;
      return j;
    } catch (e) {
      return null;
    }
  }

  function saveHistCache(type, id, messages, groupMeta) {
    if (!cacheUid() || !messages || !messages.length) return;
    try {
      // 只留最近 40 条，控制 localStorage 体积
      var slice = messages.length > 40 ? messages.slice(messages.length - 40) : messages;
      localStorage.setItem(histCacheStorageKey(type, id), JSON.stringify({
        at: Date.now(),
        messages: slice,
        groupMeta: groupMeta || null
      }));
    } catch (e) {}
  }

  function clearHistCache(type, id) {
    if (!cacheUid()) return;
    try {
      localStorage.removeItem(histCacheStorageKey(type, id));
    } catch (e) {}
  }

  function scheduleSaveHistCache() {
    if (!state.room) return;
    if (_saveHistCacheTimer) clearTimeout(_saveHistCacheTimer);
    _saveHistCacheTimer = setTimeout(function () {
      _saveHistCacheTimer = null;
      if (!state.room) return;
      saveHistCache(state.room.type, state.room.id, state.messages, state.groupMeta);
    }, 500);
  }

  var _histPrefetching = {};
  /** 后台预拉会话历史写入本地缓存，点开时可秒开 */
  function prefetchHistory(opts) {
    opts = opts || {};
    var type = opts.type | 0;
    var id = opts.id;
    if (!type || id == null || id === '') return Promise.resolve();
    if (!state.connected) return Promise.resolve();
    var key = type + ':' + String(id);
    if (_histPrefetching[key]) return _histPrefetching[key];
    var cached = loadHistCache(type, id);
    if (cached && cached.at && (Date.now() - (cached.at | 0)) < 60000) {
      return Promise.resolve(cached);
    }
    var payload = { conversation_type: type, limit: 30 };
    if (type === 1) {
      payload.to_user_id = opts.peer | 0;
      payload.conversation_id = String(id);
    } else {
      payload.group_id = parseInt(id, 10) || 0;
      payload.with_group = 1;
    }
    _histPrefetching[key] = send('history', payload).then(function (packet) {
      var list = (packet.data && packet.data.list) || [];
      var meta = null;
      if (type === 2 && packet.data && (packet.data.group || packet.data.policy)) {
        try { meta = mergeGroupMeta(packet.data); } catch (e0) { meta = null; }
      }
      saveHistCache(type, id, list, meta);
      return { messages: list, groupMeta: meta, at: Date.now() };
    }).catch(function () {
      return null;
    }).then(function (res) {
      delete _histPrefetching[key];
      return res;
    });
    return _histPrefetching[key];
  }

  function prefetchTopHistories() {
    if (!state.connected || !state.list || !state.list.length) return;
    var n = 0;
    for (var i = 0; i < state.list.length && n < 5; i++) {
      var item = state.list[i];
      var type = item.conversation_type | 0;
      var id = type === 2 ? (item.group_id || item.conversation_id) : item.conversation_id;
      if (!id) continue;
      (function (t, cid, peer, delay) {
        setTimeout(function () {
          prefetchHistory({ type: t, id: cid, peer: peer | 0 });
        }, delay);
      })(type, id, item.peer_user_id | 0, 80 + n * 140);
      n++;
    }
  }

  function scheduleRenderList() {
    if (_renderListTimer) return;
    _renderListTimer = setTimeout(function () {
      _renderListTimer = null;
      renderList();
    }, 80);
  }

  function renderList() {
    if (_renderListTimer) {
      clearTimeout(_renderListTimer);
      _renderListTimer = null;
    }
    var box = $('chatConvList');
    if (!box) return;
    if (!state.list.length) {
      box.innerHTML = '<div class="chat-empty" data-copy="chat_empty_no_conv">' + escapeHtml(chatT('chat_empty_no_conv')).replace(/\n/g, '<br>') + '</div>';
      return;
    }
    var kw = String(state.listKeyword || '').trim().toLowerCase();
    var list = state.list;
    if (kw) {
      list = state.list.filter(function (item) {
        var type = item.conversation_type | 0;
        var titleRaw = item.title || (type === 2 ? ('群 ' + (item.group_id || item.conversation_id)) : ('用户' + (item.peer_user_id || '')));
        var prev = previewText(item.last_message) || '';
        var hay = (titleRaw + ' ' + prev + ' ' + (item.conversation_id || '') + ' ' + (item.peer_user_id || '')).toLowerCase();
        return hay.indexOf(kw) >= 0;
      });
    }
    if (!list.length) {
      box.innerHTML = '<div class="chat-empty">' + escapeHtml(chatT('chat_search_empty')) + '</div>';
      return;
    }
    // 顺序未变时只改预览/时间/角标，避免整表 innerHTML 卡顿
    if (!kw && patchConvListDom(box, list)) {
      return;
    }
    box.innerHTML = list.map(function (item) {
      return buildConvItemHtml(item);
    }).join('');
  }

  function sortConvListInPlace() {
    state.list.sort(function (a, b) {
      var ap = a.pinned ? 1 : 0;
      var bp = b.pinned ? 1 : 0;
      if (ap !== bp) return bp - ap;
      return (b.updatetime | 0) - (a.updatetime | 0);
    });
  }

  var _convActionTarget = null;

  function closeConvActionSheet() {
    var sheet = $('chatConvActionSheet');
    if (!sheet) return;
    sheet.classList.remove('open');
    sheet.setAttribute('aria-hidden', 'true');
    _convActionTarget = null;
  }

  function openConvActionSheet(item) {
    if (!item) return;
    _convActionTarget = item;
    var sheet = $('chatConvActionSheet');
    var titleEl = $('chatConvActionTitle');
    var pinBtn = $('chatConvActPin');
    var unpinBtn = $('chatConvActUnpin');
    var delBtn = $('chatConvActDelete');
    if (!sheet) return;
    var title = item.title || (item.conversation_type === 2 ? ('群 ' + (item.group_id || item.conversation_id)) : '会话');
    if (titleEl) titleEl.textContent = title;
    if (pinBtn) pinBtn.style.display = (item.pinned || item.undeletable || item.is_default_cs) ? 'none' : '';
    if (unpinBtn) unpinBtn.style.display = (item.pinned && !item.undeletable && !item.is_default_cs) ? '' : 'none';
    // 私聊 / 群聊均可删除（群聊为本端软删水位）；默认客服不可删
    if (delBtn) {
      delBtn.style.display = (item.undeletable || item.is_default_cs) ? 'none' : '';
    }
    sheet.classList.add('open');
    sheet.setAttribute('aria-hidden', 'false');
  }

  function findConvItemFromBtn(btn) {
    if (!btn) return null;
    var type = parseInt(btn.getAttribute('data-type'), 10) || 1;
    var id = btn.getAttribute('data-id') || '';
    for (var i = 0; i < state.list.length; i++) {
      var it = state.list[i];
      var iid = (it.conversation_type | 0) === 2 ? (it.group_id || it.conversation_id) : it.conversation_id;
      if ((it.conversation_type | 0) === type && String(iid) === String(id)) return it;
    }
    return {
      conversation_type: type,
      conversation_id: String(id),
      group_id: type === 2 ? (parseInt(id, 10) || 0) : 0,
      peer_user_id: parseInt(btn.getAttribute('data-peer'), 10) || 0,
      title: btn.getAttribute('data-title') || '',
      pinned: btn.getAttribute('data-pinned') === '1'
    };
  }

  async function toggleConvPin(pinned) {
    var item = _convActionTarget;
    closeConvActionSheet();
    if (!item) return;
    var type = item.conversation_type | 0;
    var cid = String(type === 2 ? (item.group_id || item.conversation_id) : item.conversation_id);
    try {
      await send(pinned ? 'conversation.pin' : 'conversation.unpin', {
        conversation_type: type,
        conversation_id: cid,
        group_id: type === 2 ? (item.group_id | 0) : 0,
        to_user_id: type === 1 ? (item.peer_user_id | 0) : 0
      });
      item.pinned = !!pinned;
      // sync into state.list
      for (var i = 0; i < state.list.length; i++) {
        var it = state.list[i];
        var iid = (it.conversation_type | 0) === 2 ? (it.group_id || it.conversation_id) : it.conversation_id;
        if ((it.conversation_type | 0) === type && String(iid) === String(cid)) {
          it.pinned = !!pinned;
          break;
        }
      }
      sortConvListInPlace();
      scheduleRenderList();
      scheduleSaveListCache();
      if (typeof showFanshubToast === 'function') {
        showFanshubToast(pinned ? '已置顶' : '已取消置顶', 'success');
      }
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '操作失败', 'error');
    }
  }

  async function deletePrivateConvFromList(fromItem) {
    var item = fromItem || _convActionTarget;
    closeConvActionSheet();
    if (!item) return;
    var ctype = item.conversation_type | 0;
    if (ctype === 2) {
      await deleteGroupConvFromList(item);
      return;
    }
    if (ctype !== 1) return;
    var cid = String(item.conversation_id || '');
    var peer = item.peer_user_id | 0;
    if (!cid && peer > 0) {
      cid = '';
    }
    if (!window.confirm('删除后将从会话列表移除（对方不受影响）。确定删除？')) {
      return;
    }
    try {
      await send('conversation.hide', {
        conversation_type: 1,
        conversation_id: cid,
        to_user_id: peer
      });
      var key = convKey(1, cid || item.conversation_id);
      state.list = state.list.filter(function (it) {
        if ((it.conversation_type | 0) !== 1) return true;
        var iid = String(it.conversation_id || '');
        if (cid && iid === cid) return false;
        if (peer > 0 && (it.peer_user_id | 0) === peer) return false;
        return true;
      });
      if (key && state.unread) delete state.unread[key];
      if (state.room && (state.room.type | 0) === 1) {
        var rid = String(state.room.id || '');
        if ((cid && rid === cid) || (peer > 0 && (state.room.peer | 0) === peer)) {
          if (typeof closeRoom === 'function') closeRoom();
        }
      }
      scheduleRenderList();
      scheduleSaveListCache();
      if (typeof showFanshubToast === 'function') showFanshubToast('已删除聊天', 'success');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '删除失败', 'error');
    }
  }

  async function deleteGroupConvFromList(item) {
    if (!item || (item.conversation_type | 0) !== 2) return;
    var gid = item.group_id | 0 || parseInt(item.conversation_id, 10) || 0;
    if (gid <= 0) return;
    if (!window.confirm('删除后本端将清空该群历史（其他人不受影响）。有新消息时会话会再次出现。确定删除？')) {
      return;
    }
    var clearedMsgId = 0;
    try {
      if (item.last_message && item.last_message.id) {
        clearedMsgId = item.last_message.id | 0;
      }
    } catch (e0) {}
    try {
      await send('conversation.hide', {
        conversation_type: 2,
        conversation_id: String(gid),
        group_id: gid,
        cleared_msg_id: clearedMsgId
      });
      var key = convKey(2, gid);
      state.list = state.list.filter(function (it) {
        if ((it.conversation_type | 0) !== 2) return true;
        var iid = (it.group_id | 0) || parseInt(it.conversation_id, 10) || 0;
        return iid !== gid;
      });
      if (key && state.unread) delete state.unread[key];
      try { clearHistCache(2, gid); } catch (e1) {}
      if (state.room && (state.room.type | 0) === 2 && (state.room.id | 0) === gid) {
        if (typeof closeRoom === 'function') closeRoom();
      }
      scheduleRenderList();
      scheduleSaveListCache();
      if (typeof showFanshubToast === 'function') showFanshubToast('已删除群聊记录', 'success');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '删除失败', 'error');
    }
  }

  function buildConvItemHtml(item) {
    var type = item.conversation_type | 0;
    var id = type === 2 ? (item.group_id || item.conversation_id) : item.conversation_id;
    var key = convKey(type, id);
    var unread = state.unread[key] | 0;
    var titleRaw = item.title || (type === 2 ? ('群 ' + id) : ('用户' + (item.peer_user_id || '')));
    if (item.is_im_admin && titleRaw && titleRaw.indexOf('客服') < 0 && type === 1) {
      titleRaw = '客服 · ' + titleRaw;
    } else if (item.is_im_admin && type === 1 && (!item.title || item.title.indexOf('ID') === 0)) {
      titleRaw = item.title || '客服';
    }
    var title = escapeHtml(titleRaw);
    var prev = escapeHtml(previewText(item.last_message) || (item.is_im_admin ? '点击开始咨询' : '暂无消息'));
    var time = formatTime(item.updatetime || (item.last_message && item.last_message.createtime));
    var avatarHtml = avatarImgHtml(item.avatar);
    var adminBadge = item.is_im_admin ? '<span class="chat-admin-tag">客服</span>' : '';
    var pinMark = item.pinned ? '<span class="chat-conv-pin" aria-hidden="true">📌</span>' : '';
    var btn =
      '<button type="button" class="chat-conv-item' + (item.is_im_admin ? ' is-admin' : '') + (item.pinned ? ' is-pinned' : '') + '" data-type="' + type + '" data-id="' + escapeHtml(String(id)) + '" data-peer="' + (item.peer_user_id | 0) + '" data-title="' + title + '" data-pinned="' + (item.pinned ? '1' : '0') + '">' +
        '<div class="chat-avatar' + (type === 2 ? ' group' : '') + (item.is_im_admin ? ' admin' : '') + '">' + avatarHtml + '</div>' +
        '<div class="chat-conv-body">' +
          '<div class="chat-conv-title"><span>' + pinMark + title + adminBadge + '</span><span class="chat-conv-time">' + time + '</span></div>' +
          '<div class="chat-conv-preview">' + prev + '</div>' +
        '</div>' +
        (unread ? '<span class="chat-badge">' + (unread > 99 ? '99+' : unread) + '</span>' : '') +
      '</button>';
    // 私聊 / 群聊：左滑露出删除
    if (type === 1 || type === 2) {
      return (
        '<div class="chat-conv-swipe" data-type="' + type + '" data-id="' + escapeHtml(String(id)) + '" data-peer="' + (item.peer_user_id | 0) + '">' +
          '<div class="chat-conv-swipe-actions">' +
            '<button type="button" class="chat-conv-swipe-del" data-act="delete">删除</button>' +
          '</div>' +
          btn +
        '</div>'
      );
    }
    return btn;
  }

  function patchConvListDom(box, list) {
    var nodes = box.querySelectorAll('.chat-conv-item');
    if (!nodes || nodes.length !== list.length) return false;
    for (var i = 0; i < list.length; i++) {
      var item = list[i];
      var type = item.conversation_type | 0;
      var id = String(type === 2 ? (item.group_id || item.conversation_id) : item.conversation_id);
      var el = nodes[i];
      if ((el.getAttribute('data-type') || '') !== String(type) || (el.getAttribute('data-id') || '') !== id) {
        return false;
      }
    }
    for (var j = 0; j < list.length; j++) {
      var it = list[j];
      var t = it.conversation_type | 0;
      var cid = t === 2 ? (it.group_id || it.conversation_id) : it.conversation_id;
      var key = convKey(t, cid);
      var unread = state.unread[key] | 0;
      var el2 = nodes[j];
      var prevEl = el2.querySelector('.chat-conv-preview');
      var timeEl = el2.querySelector('.chat-conv-time');
      var prev = previewText(it.last_message) || (it.is_im_admin ? '点击开始咨询' : '暂无消息');
      var time = formatTime(it.updatetime || (it.last_message && it.last_message.createtime));
      if (prevEl && prevEl.textContent !== prev) prevEl.textContent = prev;
      if (timeEl && timeEl.textContent !== time) timeEl.textContent = time;
      var badge = el2.querySelector('.chat-badge');
      if (unread > 0) {
        var text = unread > 99 ? '99+' : String(unread);
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'chat-badge';
          el2.appendChild(badge);
        }
        if (badge.textContent !== text) badge.textContent = text;
      } else if (badge && badge.parentNode) {
        badge.parentNode.removeChild(badge);
      }
    }
    return true;
  }

  function canRecallMessage(msg) {
    if (!msg || (msg.status | 0) === 2) return false;
    if ((msg.msg_type | 0) === 3) return false;
    var mine = (msg.from_user_id | 0) === state.userId;
    if (state.isImAdmin) return true;
    if (!mine) return false;
    var isPrivate = ((state.room && state.room.type) | 0) === 1
      || (msg.conversation_type | 0) === 1;
    // 私聊本人可随时删除；群聊仍限 2 分钟撤回
    if (isPrivate) return true;
    return (timeSec() - (msg.createtime | 0)) <= 120;
  }

  function timeSec() {
    return Math.floor(Date.now() / 1000);
  }

  function formatFileSize(n) {
    n = n | 0;
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function msgActionsHtml(msg, mine) {
    // 会话消息列表不再展示删除/撤回按钮
    return '';
  }

  function groupPolicy() {
    return (state.groupMeta && state.groupMeta.policy) || {};
  }

  function groupForbidModes() {
    var policy = groupPolicy();
    var fm = (policy && policy.forbid_modes) || (state.groupMeta && state.groupMeta.forbid_modes) || {};
    return {
      text: !!fm.text,
      image: !!fm.image,
      emoji: !!fm.emoji,
      video: !!fm.video,
      rp: !!fm.rp
    };
  }

  function canSendCapability(cap) {
    if (!(state.room && state.room.type === 2)) return true;
    var policy = groupPolicy();
    var key = 'can_send_' + cap;
    if (policy && policy[key] != null) return !!policy[key];
    var myRole = (state.groupMeta && state.groupMeta.my_role) | 0;
    if (myRole >= 2) return true;
    var fm = groupForbidModes();
    return !fm[cap];
  }

  function mergeGroupMeta(data) {
    data = data || {};
    var prev = state.groupMeta || {};
    state.groupMeta = {
      group: data.group || prev.group || null,
      my_role: (data.my_role != null ? data.my_role : prev.my_role) | 0,
      mute_all: data.mute_all != null ? !!data.mute_all : !!prev.mute_all,
      forbid_modes: data.forbid_modes || (data.policy && data.policy.forbid_modes) || prev.forbid_modes || {},
      member_count: (data.member_count != null ? data.member_count : prev.member_count) | 0,
      member_list_hidden: data.member_list_hidden != null ? !!data.member_list_hidden : !!prev.member_list_hidden,
      can_speak: data.can_speak !== false,
      policy: data.policy || prev.policy || {}
    };
    if (state.groupMeta.policy && state.groupMeta.policy.member_list_hidden != null) {
      state.groupMeta.member_list_hidden = !!state.groupMeta.policy.member_list_hidden;
    }
    if (state.groupMeta.policy && state.groupMeta.policy.forbid_modes) {
      state.groupMeta.forbid_modes = state.groupMeta.policy.forbid_modes;
    }
    return state.groupMeta;
  }

  function cacheSender(userId, info) {
    userId = userId | 0;
    if (userId > 0 && info) state.senderCache[userId] = info;
  }

  function cacheSenderFromMsg(msg) {
    if (!msg) return;
    var uid = msg.from_user_id | 0;
    if (uid <= 0) return;
    var fu = msg.from_user || {};
    var nick = String(msg.from_nickname || fu.nickname || fu.username || '').trim();
    var av = msg.from_avatar || fu.avatar || '';
    if (!nick || nick === '群友') return;
    var prev = state.senderCache[uid];
    // 已有真实昵称时不降级覆盖
    if (prev && prev.nickname && prev.nickname !== '群友' && !av && prev.avatar) {
      av = prev.avatar;
    }
    cacheSender(uid, { user_id: uid, nickname: nick, avatar: av || (prev && prev.avatar) || '' });
  }

  function getSenderBrief(userId) {
    userId = userId | 0;
    if (state.senderCache[userId] && state.senderCache[userId].nickname && state.senderCache[userId].nickname !== '群友') {
      return state.senderCache[userId];
    }
    var found = null;
    (state.members || []).some(function (m) {
      if ((m.user_id | 0) === userId) { found = m; return true; }
      return false;
    });
    if (found) {
      var nick = String(found.nickname || found.username || '').trim();
      if (nick && nick !== '群友' && nick.indexOf('群友') !== 0) {
        var brief = { user_id: userId, nickname: nick, avatar: found.avatar || '' };
        cacheSender(userId, brief);
        return brief;
      }
    }
    if (state.senderCache[userId]) return state.senderCache[userId];
    return { user_id: userId, nickname: '', avatar: '' };
  }

  var CHAT_BACK_SVG = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/></svg>';

  function ensureChatOverlays() {
    var room = $('chatRoomPane');
    // 挂到会话页同级，避免被 .chat-room-pane .chat-hero-back{font-size:0} 藏掉返回键
    var root = (room && room.parentNode) || document.body;
    if (!$('chatUserProfilePane')) {
      root.insertAdjacentHTML('beforeend',
        '<div class="chat-action-sheet" id="chatUserProfilePane" aria-hidden="true">' +
          '<div class="chat-action-sheet-mask" id="chatUserProfileMask"></div>' +
          '<div class="chat-action-sheet-panel chat-profile-panel">' +
            '<div class="chat-profile-avatar" id="chatProfileAvatar">U</div>' +
            '<div class="chat-profile-name" id="chatProfileName">用户</div>' +
            '<div class="chat-profile-id" id="chatProfileId"></div>' +
            '<button type="button" class="chat-action-item" id="chatProfileAddFriend" style="display:none">加好友</button>' +
            '<button type="button" class="chat-action-item" id="chatProfilePrivateChat" style="display:none">发私信</button>' +
            '<button type="button" class="chat-action-item cancel" id="chatProfileClose">关闭</button>' +
          '</div></div>');
    }
    if (!$('chatRpDetailPane')) {
      root.insertAdjacentHTML('beforeend',
        '<div class="chat-sub-pane" id="chatRpDetailPane" aria-hidden="true">' +
          '<div class="chat-hero-hd">' +
            '<button type="button" class="chat-hero-back" id="chatRpDetailBack" aria-label="返回">' + CHAT_BACK_SVG + '</button>' +
            '<div class="chat-hero-title" id="chatRpDetailTitle">红包详情</div><span class="chat-hero-spacer"></span></div>' +
          '<div class="chat-sub-main">' +
            '<div id="chatRpDetailBody">' +
              '<div class="chat-rp-detail-head" id="chatRpDetailHead"></div>' +
              '<div class="chat-rp-detail-list" id="chatRpDetailList"></div>' +
              '<button type="button" class="chat-rp-detail-grab-btn" id="chatRpDetailGrabBtn" style="display:none">开红包</button>' +
            '</div>' +
            '<div id="chatRpFairPane" class="chat-rp-fair-pane" hidden>' +
              '<div class="chat-rp-fair-err" id="chatRpFairErr" style="display:none"></div>' +
              '<div class="chat-rp-fair-result" id="chatRpFairResult"></div>' +
            '</div>' +
          '</div></div>');
    } else {
      var rpPane = $('chatRpDetailPane');
      if (room && rpPane && rpPane.parentNode === room) {
        root.appendChild(rpPane);
      }
      var rpBack = $('chatRpDetailBack');
      if (rpBack && !rpBack.querySelector('svg')) {
        rpBack.innerHTML = CHAT_BACK_SVG;
        rpBack.setAttribute('aria-label', '返回');
      }
      // 旧 DOM 补齐内嵌验证层
      if (rpPane && !$('chatRpFairPane')) {
        var main = rpPane.querySelector('.chat-sub-main');
        if (main && !$('chatRpDetailBody')) {
          var body = document.createElement('div');
          body.id = 'chatRpDetailBody';
          while (main.firstChild) body.appendChild(main.firstChild);
          main.appendChild(body);
        }
        if (main) {
          main.insertAdjacentHTML('beforeend',
            '<div id="chatRpFairPane" class="chat-rp-fair-pane" hidden>' +
              '<div class="chat-rp-fair-err" id="chatRpFairErr" style="display:none"></div>' +
              '<div class="chat-rp-fair-result" id="chatRpFairResult"></div>' +
            '</div>');
        }
        var titleEl = rpPane.querySelector('.chat-hero-title');
        if (titleEl && !titleEl.id) titleEl.id = 'chatRpDetailTitle';
      }
    }
    if (!$('chatGroupModeBlock') && $('chatGroupEditBlock')) {
      $('chatGroupEditBlock').insertAdjacentHTML('beforeend',
        '<div id="chatGroupModeBlock" style="display:none;margin-top:12px;">' +
          '<label class="chat-setting-label">群属性</label>' +
          '<select class="chat-setting-input" id="chatGroupPrivacyMode">' +
            '<option value="open">🔓 开放群（公开社交）</option>' +
            '<option value="private">🔒 隐私群（防挖人）</option>' +
          '</select>' +
          '<label class="chat-setting-label" style="margin-top:8px;">互动模式</label>' +
          '<select class="chat-setting-input" id="chatGroupChatMode">' +
            '<option value="chat">聊天模式（自由发言/发红包）</option>' +
            '<option value="grab">红宝模式（全员禁言，仅管理员发红包）</option>' +
          '</select>' +
          '<button type="button" class="chat-setting-save-btn" id="chatGroupModeSaveBtn" style="margin-top:8px;">保存群属性</button>' +
        '</div>');
    }
  }

  function renderSenderAvatarHtml(userId, clickable) {
    var brief = getSenderBrief(userId);
    var av = avatarImgHtml(brief.avatar);
    var cls = 'chat-msg-avatar' + (clickable ? ' clickable' : ' locked');
    if (clickable) {
      return '<button type="button" class="' + cls + '" data-uid="' + (userId | 0) + '">' + av + '</button>';
    }
    return '<div class="' + cls + '">' + av + '</div>';
  }

  var RP_ICON_SVG =
    '<svg class="rp-env-svg" viewBox="0 0 40 46" aria-hidden="true">' +
      '<rect x="4" y="12" width="32" height="30" rx="3.2" fill="#F8E2A8"/>' +
      '<path d="M4 15.2L20 27.2L36 15.2V13.2c0-1.9-1.4-3.2-3.2-3.2H7.2C5.4 10 4 11.3 4 13.2v2z" fill="#E3B268"/>' +
      '<path d="M4 15.2L20 27.2L36 15.2" stroke="rgba(138,100,39,.35)" stroke-width="1" fill="none"/>' +
      '<circle cx="20" cy="25" r="8.2" fill="#C61114"/>' +
      '<circle cx="20" cy="25" r="8.2" fill="none" stroke="rgba(253,228,179,.85)" stroke-width="1.2"/>' +
      '<text x="20" y="28.2" text-anchor="middle" font-size="9.5" font-weight="800" fill="#FDE4B3" font-family="PingFang SC,Microsoft YaHei,sans-serif">開</text>' +
    '</svg>';
  var NOTICE_ICON_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4V6h16v12z"/><path d="M12 11c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>';

  function sysNoticeHtml(text) {
    return '<div class="sys-notice"><div class="notice-inner">' + NOTICE_ICON_SVG + escapeHtml(text || '') + '</div></div>';
  }

  function sysTimeHtml(label) {
    return '<div class="sys-time"><span>' + escapeHtml(label || '') + '</span></div>';
  }

  function renderRpCardHtml(extra, msg, time) {
    var pid = (extra && extra.packet_id) || 0;
    var bless = escapeHtml((extra && extra.blessing) || msg.content || '恭喜发财，大吉大利');
    var amt = extra && (extra.total_amount != null) ? parseFloat(extra.total_amount) : NaN;
    var cnt = extra && (extra.total_count != null) ? (extra.total_count | 0) : 0;
    var ptype = extra && (extra.packet_type != null) ? (extra.packet_type | 0) : 2;
    var mineRaw = extra && extra.mine_digit;
    var pending = !!(extra && extra.mine_pending);
    var mine = (mineRaw != null && mineRaw !== '') ? (parseInt(mineRaw, 10) || 0) : null;
    if (mine != null && (mine < 0 || mine > 9)) mine = 0;
    var cover = (state.rpCover && state.rpCover[pid]) || {};
    var grabbed = !!(cover.grabbed || (extra && extra.cover_grabbed));
    var expired = !!(cover.expired || (extra && extra.cover_expired));
    if (!expired && extra && (extra.expiretime | 0) > 0) {
      expired = (Math.floor(Date.now() / 1000) >= (extra.expiretime | 0));
    }
    var faded = !!(cover.faded || (extra && extra.cover_faded) || grabbed || expired);
    var descHtml;
    if (!isNaN(amt) && amt > 0) {
      descHtml = '<span class="rp-amt"><span class="rp-yen">¥</span>' + amt.toFixed(2) + '</span>'
        + (cnt > 0 ? '<span class="rp-cnt">' + cnt + '个</span>' : '');
    } else {
      descHtml = '<span class="rp-amt">红包</span>';
    }
    var sendTime = time || formatTimeSec((msg && msg.createtime) || 0);
    var bottom = '红包福利';
    if (ptype === 3) bottom = pending ? '埋雷 · 匹配中' : '埋雷红包';
    else if (ptype === 2) bottom = '拼手气红包';
    else if (ptype === 4) bottom = '随机红包';
    else if (ptype === 1) bottom = '普通红包';
    if (extra && extra.mode_label) bottom = String(extra.mode_label);
    if (grabbed) bottom = '已领取';
    else if (expired) bottom = '已过期';
    bottom = escapeHtml(bottom);
    var mineBadge = (ptype === 3 && mine != null)
      ? ('<div class="rp-mine-badge" aria-label="埋雷数字"><span class="rp-mine-badge-lab">雷</span><span class="rp-mine-badge-num">' + mine + '</span></div>')
      : '';
    var cls = 'chat-rp-card bubble-rp'
      + (ptype === 3 ? ' is-mine' : '')
      + (faded ? ' is-faded' : '')
      + (grabbed ? ' is-grabbed' : '')
      + (expired ? ' is-expired' : '');
    var bottomRight = sendTime
      ? ('<span class="rp-time">' + escapeHtml(sendTime) + '</span>')
      : '';
    return (
      '<button type="button" class="' + cls + '" data-packet="' + (pid | 0) + '">' +
        '<div class="rp-top">' +
          '<div class="rp-icon-box">' + RP_ICON_SVG + '</div>' +
          '<div class="rp-info">' +
            '<div class="rp-title">' + bless + '</div>' +
            '<div class="rp-desc">' + descHtml + '</div>' +
          '</div>' +
          mineBadge +
        '</div>' +
        '<div class="rp-ribbon" aria-hidden="true"></div>' +
        '<div class="rp-bottom"><span class="rp-bottom-lab">' + bottom + '</span>' + bottomRight + '</div>' +
      '</button>'
    );
  }

  function renderTransferCardHtml(extra, msg, time) {
    var amt = extra && extra.amount != null ? parseFloat(extra.amount) : NaN;
    var remark = (extra && extra.remark) ? String(extra.remark) : '';
    var mine = msg && ((msg.from_user_id | 0) === state.userId);
    var title = remark ? escapeHtml(remark) : (mine ? '转账给对方' : '收到转账');
    var amtHtml = !isNaN(amt)
      ? ('<span class="tf-yen">¥</span>' + amt.toFixed(2))
      : '转账';
    return (
      '<div class="chat-transfer-card' + (mine ? ' me' : '') + '">' +
        '<div class="tf-top">' +
          '<div class="tf-icon" aria-hidden="true">💸</div>' +
          '<div class="tf-info">' +
            '<div class="tf-amt">' + amtHtml + '</div>' +
            '<div class="tf-title">' + title + '</div>' +
          '</div>' +
        '</div>' +
        '<div class="tf-bottom"><span class="tf-lab">转账</span>' +
          (time ? '<span class="tf-time">' + escapeHtml(time) + '</span>' : '') +
        '</div>' +
      '</div>'
    );
  }

  function markRpCover(packetId, patch) {
    packetId = packetId | 0;
    if (!packetId) return;
    if (!state.rpCover) state.rpCover = {};
    var cur = state.rpCover[packetId] || {};
    var next = Object.assign({}, cur, patch || {});
    if (next.grabbed || next.expired) next.faded = true;
    state.rpCover[packetId] = next;
    // 同步到消息 extra，重渲染时保留
    if (state.messages && state.messages.length) {
      state.messages.forEach(function (m) {
        if (!m || (m.msg_type | 0) !== 2) return;
        var ex = m.extra;
        if (typeof ex === 'string') {
          try { ex = JSON.parse(ex); } catch (e) { ex = {}; }
        }
        if (!ex || typeof ex !== 'object') ex = {};
        if ((ex.packet_id | 0) !== packetId) return;
        if (next.grabbed) ex.cover_grabbed = true;
        if (next.expired) ex.cover_expired = true;
        if (next.faded) ex.cover_faded = true;
        if (patch && patch.mine_digit != null) ex.mine_digit = patch.mine_digit | 0;
        m.extra = ex;
      });
    }
  }

  /** 只替换对应红包卡片，避免整表 innerHTML 重绘 */
  function refreshRpCardsInDom(packetId) {
    packetId = packetId | 0;
    if (!packetId) return false;
    var box = $('chatMsgScroll');
    if (!box) return false;
    var cards = box.querySelectorAll('.chat-rp-card[data-packet="' + packetId + '"]');
    if (!cards.length) return false;
    var msg = null;
    var i;
    for (i = 0; i < state.messages.length; i++) {
      var m = state.messages[i];
      if (!m || (m.msg_type | 0) !== 2) continue;
      var ex = m.extra;
      if (typeof ex === 'string') {
        try { ex = JSON.parse(ex); } catch (e) { ex = {}; }
      }
      if (!ex || typeof ex !== 'object') continue;
      if ((ex.packet_id | 0) === packetId) {
        msg = m;
        break;
      }
    }
    if (!msg) return false;
    var extra = msg.extra;
    if (typeof extra === 'string') {
      try { extra = JSON.parse(extra); } catch (e2) { extra = {}; }
    }
    var html = renderRpCardHtml(extra || {}, msg, formatTimeSec(msg.createtime));
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    var neu = tmp.firstElementChild;
    if (!neu) return false;
    for (i = 0; i < cards.length; i++) {
      var card = cards[i];
      var node = (i === cards.length - 1) ? neu : neu.cloneNode(true);
      if (card.parentNode) card.parentNode.replaceChild(node, card);
    }
    return true;
  }

  function refreshRpOrMessages(packetId, skipScroll) {
    if (refreshRpCardsInDom(packetId)) return;
    try { renderMessages(!!skipScroll); } catch (e) {}
  }

  function groupMessageWrap(mine, fromUserId, innerHtml, actions, msgOrId) {
    var msg = (msgOrId && typeof msgOrId === 'object') ? msgOrId : null;
    var msgId = msg ? (msg.id | 0) : (msgOrId | 0);
    if (msg) cacheSenderFromMsg(msg);
    var midAttr = msgId ? (' data-mid="' + msgId + '"') : '';
    var isGroup = state.room && state.room.type === 2;
    var uid = mine ? (state.userId | 0) : (fromUserId | 0);
    // 已关闭：点击头像/昵称查看资料
    var clickable = false;
    var nickHtml = '';
    if (isGroup && !mine) {
      var fu = (msg && msg.from_user) || {};
      var nick = String((msg && (msg.from_nickname || fu.nickname || fu.username)) || '').trim();
      if (!nick || nick === '群友') {
        nick = String((getSenderBrief(fromUserId).nickname) || '').trim();
      }
      if (!nick || nick === '群友') nick = '用户';
      nickHtml = '<div class="chat-msg-nick locked" data-uid="' + (fromUserId | 0) + '">' +
        escapeHtml(nick) + '</div>';
    }
    return (
      '<div class="chat-msg-row' + (mine ? ' me' : '') + (isGroup && !mine ? ' group-msg' : '') + '"' + midAttr + '>' +
        renderSenderAvatarHtml(uid, clickable) +
        '<div class="chat-msg-main">' + nickHtml + innerHtml + (actions || '') + '</div>' +
      '</div>'
    );
  }

  async function openUserProfile() {
    // 全站关闭点击头像看资料
  }

  function closeUserProfile() {
    var pane = $('chatUserProfilePane');
    if (pane) { pane.classList.remove('open'); pane.setAttribute('aria-hidden', 'true'); }
    state.profileTarget = null;
  }

  var _rpFairRetryTimer = null;

  function isRpFairViewOpen() {
    var fair = $('chatRpFairPane');
    return !!(fair && !fair.hidden);
  }

  function hideRpFairVerify() {
    if (_rpFairRetryTimer) {
      clearTimeout(_rpFairRetryTimer);
      _rpFairRetryTimer = null;
    }
    var body = $('chatRpDetailBody');
    var fair = $('chatRpFairPane');
    var title = $('chatRpDetailTitle');
    if (body) body.hidden = false;
    if (fair) {
      fair.hidden = true;
      fair.setAttribute('hidden', 'hidden');
    }
    if (title) title.textContent = '红包详情';
    state._rpFairOpen = false;
  }

  function rpFairApiBase() {
    var path = location.pathname || '/';
    var idx = path.indexOf('/888/');
    if (idx >= 0) return location.origin + path.slice(0, idx) + '/';
    return location.origin + '/';
  }

  function rpFairYnTag(ok, okText, badText) {
    return ok
      ? '<span class="chat-rp-fair-tag ok">' + escapeHtml(okText || '通过') + '</span>'
      : '<span class="chat-rp-fair-tag bad">' + escapeHtml(badText || '不一致') + '</span>';
  }

  function rpFairCentsChips(arr) {
    if (!arr || !arr.length) return '<span class="chat-rp-fair-sub">—</span>';
    return '<div class="chat-rp-fair-cents">' + arr.map(function (c, i) {
      var yuan = (Number(c) / 100).toFixed(2);
      return '<span class="chat-rp-fair-cent">#' + (i + 1) + ' ¥' + yuan + ' <small>(' + c + '分)</small></span>';
    }).join('') + '</div>';
  }

  function rpFairStatusLabel(st) {
    var m = { 1: '可抢', 2: '已抢完', 3: '已过期', 4: '已关闭', 5: '已结算' };
    return m[st] || ('status ' + st);
  }

  function rpFairTronStatusLabel(st) {
    var m = { 0: '未开奖', 1: '等待区块确认', 2: '已绑定波场哈希', 3: '拉取失败（可重试）' };
    return m[st] || '';
  }

  function renderRpFairResultHtml(d) {
    d = d || {};
    var blockNum = d.targetBlockNum || d.tron_block_num || 0;
    var blockId = d.block_id || d.tron_block_id || d.fair_hash || '';
    var lucky = d.luckyNumber || d.tron_lucky || '';
    var luckyDigit = d.lucky_digit != null ? d.lucky_digit : null;
    var mineDigit = d.mine_digit != null ? d.mine_digit : null;
    var tronscan = d.tronscan_url || (blockNum ? ('https://tronscan.org/#/block/' + blockNum) : (blockId ? ('https://tronscan.org/#/block/' + blockId) : ''));
    var av = d.amount_verify || {};
    var computed = d.computed_cents || [];
    var stored = d.fair_cents || [];
    var grabs = d.grab_cents || [];
    var row = function (lab, val) {
      return '<div class="chat-rp-fair-row"><span>' + lab + '</span><span>' + val + '</span></div>';
    };
    var html = '';
    html += '<div class="chat-rp-fair-card">'
      + row('玩法', '<strong>' + escapeHtml(d.type_label || '') + '</strong>')
      + row('红包状态', escapeHtml(rpFairStatusLabel(d.status)))
      + row('波场开奖', escapeHtml(rpFairTronStatusLabel(d.tron_status | 0)))
      + row('可抢池', '¥' + Number(d.pool_amount != null ? d.pool_amount : d.total_amount || 0).toFixed(2) + ' / ' + (d.total_count | 0) + '个')
      + row('发包总额', '¥' + Number(d.total_amount || 0).toFixed(2))
      + ((d.packet_type | 0) === 3
        ? row('手填雷号', '<strong>' + escapeHtml(mineDigit != null ? String(mineDigit) : '—') + '</strong>')
        : '')
      + '</div>';

    var card2 = '<p class="chat-rp-fair-sub" style="margin-top:0">波场（TRON）官方区块哈希</p>';
    card2 += row('官方区块高度', '<strong>' + escapeHtml(blockNum || '—') + '</strong>');
    if (lucky) card2 += row('哈希末位字符', '<strong>' + escapeHtml(lucky) + '</strong>');
    if (d.revealed && luckyDigit != null) card2 += row('末位映射 0-9', '<strong>' + escapeHtml(String(luckyDigit)) + '</strong>');
    card2 += '<label class="chat-rp-fair-label">Block Hash</label><div class="chat-rp-fair-mono">' + escapeHtml(blockId || '尚未出块，请稍候刷新') + '</div>';
    if (d.verify_hint) card2 += '<p class="chat-rp-fair-sub">' + escapeHtml(d.verify_hint) + '</p>';
    if (tronscan) {
      card2 += '<a class="chat-rp-fair-tron" href="' + escapeHtml(tronscan) + '" target="_blank" rel="noopener">前往 TronScan 官方核实</a>';
      card2 += '<a class="chat-rp-fair-tron is-oklink" href="https://www.oklink.com/zh-hans/tron/block/'
        + encodeURIComponent(String(blockNum || blockId)) + '" target="_blank" rel="noopener">前往 OKLink 核实</a>';
    }
    if (!d.revealed) {
      card2 += '<p class="chat-rp-fair-sub">尚未绑定波场哈希；页面将自动重试</p><span class="chat-rp-fair-tag wait">待开奖</span>';
    } else {
      card2 += ' <span class="chat-rp-fair-tag ok">波场哈希已公开</span>';
    }
    html += '<div class="chat-rp-fair-card">' + card2 + '</div>';

    if (d.revealed) {
      var cardAmt = '<p class="chat-rp-fair-sub" style="margin-top:0"><strong>金额验证</strong>（哈希 + 单号链下复算）</p>';
      cardAmt += row('总体结果', av.ok ? rpFairYnTag(true, '金额校验通过') : rpFairYnTag(false, '金额校验失败'));
      cardAmt += row('复算合计=可抢池', rpFairYnTag(!!av.sum_ok, '一致', '不一致'));
      cardAmt += row('与入库拆包序列', av.has_stored
        ? rpFairYnTag(!!av.match_stored, '完全一致', '不一致')
        : '<span class="chat-rp-fair-tag wait">无入库序列（旧包）</span>');
      cardAmt += row('与实际领取顺序', av.has_grabs
        ? rpFairYnTag(!!av.match_grabs, '前缀一致', '不一致')
        : '<span class="chat-rp-fair-tag wait">尚无领取</span>');
      if ((d.packet_type | 0) === 3) {
        cardAmt += row('哈希末位=手填雷号', rpFairYnTag(!!av.mine_digit_match, '已匹配 ' + escapeHtml(String(mineDigit)), '不匹配'));
      }
      cardAmt += '<label class="chat-rp-fair-label">链下复算金额序列</label>' + rpFairCentsChips(computed);
      if (stored && stored.length) cardAmt += '<label class="chat-rp-fair-label">入库 fair_cents</label>' + rpFairCentsChips(stored);
      if (grabs && grabs.length) cardAmt += '<label class="chat-rp-fair-label">实际领取（按抢包顺序）</label>' + rpFairCentsChips(grabs);
      cardAmt += '<div class="chat-rp-fair-card is-inset"><p class="chat-rp-fair-sub" style="margin:0 0 8px"><strong>核对步骤</strong></p>';
      cardAmt += '<p class="chat-rp-fair-sub" style="margin:0">1. 在 TronScan 核对区块 <strong>#' + escapeHtml(String(blockNum)) + '</strong> 的 Block Hash</p>';
      cardAmt += '<p class="chat-rp-fair-sub" style="margin:4px 0 0">2. 用该 Hash + 单号 <strong>' + escapeHtml(d.packet_no || '') + '</strong> 按二倍均值法复算金额</p>';
      cardAmt += '<p class="chat-rp-fair-sub" style="margin:4px 0 0">3. 对比本页「复算序列」与领取金额是否一致</p>';
      if ((d.packet_type | 0) === 3) {
        cardAmt += '<p class="chat-rp-fair-sub" style="margin:4px 0 0">4. 扫雷：金额尾数 = 手填雷号 <strong>'
          + escapeHtml(String(mineDigit != null ? mineDigit : '—')) + '</strong> 即中雷</p>';
      }
      cardAmt += '</div>';
      html += '<div class="chat-rp-fair-card">' + cardAmt + '</div>';
    }
    return html;
  }

  async function loadRpFairVerify(packetNo, opts) {
    opts = opts || {};
    var errEl = $('chatRpFairErr');
    var resultEl = $('chatRpFairResult');
    if (!resultEl) return;
    if (errEl) errEl.style.display = 'none';
    packetNo = String(packetNo || '').trim();
    if (!packetNo) {
      if (errEl) {
        errEl.textContent = '缺少红包单号';
        errEl.style.display = 'block';
      }
      return;
    }
    if (!opts.silent) {
      resultEl.innerHTML = '<div class="chat-empty">验证加载中…</div>';
    }
    try {
      var url = rpFairApiBase() + 'api/fanshub/rpfair?packet_no=' + encodeURIComponent(packetNo);
      var res = await fetch(url, { credentials: 'same-origin' });
      var json = await res.json();
      if (!json || Number(json.code) !== 1) {
        throw new Error((json && json.msg) ? json.msg : ('HTTP ' + res.status));
      }
      if (!state._rpFairOpen) return;
      var d = json.data || {};
      resultEl.innerHTML = renderRpFairResultHtml(d);
      if (_rpFairRetryTimer) {
        clearTimeout(_rpFairRetryTimer);
        _rpFairRetryTimer = null;
      }
      if (!d.revealed && !opts.noRetry) {
        _rpFairRetryTimer = setTimeout(function () {
          if (!state._rpFairOpen) return;
          loadRpFairVerify(packetNo, { silent: true, noRetry: false }).catch(function () {});
        }, 3500);
      }
    } catch (e) {
      if (!state._rpFairOpen) return;
      if (errEl) {
        errEl.textContent = (e && e.message) || '网络错误';
        errEl.style.display = 'block';
      }
      if (!opts.silent) {
        resultEl.innerHTML = '';
      }
    }
  }

  function showRpFairVerify(packetNo) {
    ensureChatOverlays();
    var body = $('chatRpDetailBody');
    var fair = $('chatRpFairPane');
    var title = $('chatRpDetailTitle');
    if (!fair) return;
    state._rpFairOpen = true;
    state._rpFairPacketNo = String(packetNo || '').trim();
    if (body) body.hidden = true;
    fair.hidden = false;
    fair.removeAttribute('hidden');
    if (title) title.textContent = '波场哈希验证';
    loadRpFairVerify(state._rpFairPacketNo, {}).catch(function () {});
  }

  function applyRedPacketDetailData(packetId, data) {
    data = data || {};
    var head = $('chatRpDetailHead');
    var list = $('chatRpDetailList');
    var grabBtn = $('chatRpDetailGrabBtn');
    var p = data.packet || {};
    var bless = p.blessing || '恭喜发财';
    var privacyMode = (data.privacy_mode || (data.policy && data.policy.privacy_mode) || '').toString();
    // 隐私隐藏仅看群隐私 / 服务端 rp_detail_locked；不要把「不可点资料」当成隐私脱敏
    var locked = data.rp_detail_locked === true
      || privacyMode === 'private'
      || (privacyMode !== 'open' && data.member_list_hidden === true);
    if (privacyMode !== 'open' && privacyMode !== 'private') {
      privacyMode = locked ? 'private' : 'open';
    }
    if (head) {
      var fairHash = p.tron_block_id || p.fair_hash || '';
      var fairBits = '';
      var blockNum = p.tron_block_num || p.targetBlockNum || 0;
      var ptype = p.packet_type | 0;
      var mineLine = '';
      if (ptype === 3) {
        mineLine = '<div class="chat-rp-detail-meta">埋雷数字：<strong>' + (p.mine_digit | 0) + '</strong>'
          + (p.mine_pending ? '（匹配波场哈希末位中）' : '（已匹配波场哈希末位）')
          + '</div>';
      }
      if ((fairHash || blockNum) && ptype !== 1) {
        var hashLabel = blockNum ? ('TRON #' + blockNum) : 'TRON';
        var tronTarget = blockNum ? String(blockNum) : String(fairHash || '');
        var tronHref = tronTarget
          ? ('https://tronscan.org/#/block/' + encodeURIComponent(tronTarget))
          : '';
        fairBits = '<div class="chat-rp-fair-hash"><span class="chat-rp-fair-label">' + hashLabel + '</span><code>' + escapeHtml(fairHash || '开奖后公开') + '</code></div>';
        if (fairHash && tronHref) {
          fairBits += '<a class="chat-rp-tron-btn" href="' + tronHref + '" target="_blank" rel="noopener">前往波场验证</a>';
        } else if (blockNum && tronHref) {
          fairBits += '<a class="chat-rp-tron-btn" href="' + tronHref + '" target="_blank" rel="noopener">查看锁定区块</a>';
        }
        fairBits += '<button type="button" class="chat-rp-fair-link" data-packet-no="'
          + escapeHtml(p.packet_no || '') + '">本站验证详情</button>';
      }
      head.innerHTML = '<div class="chat-rp-detail-bless">' + escapeHtml(bless) + '</div>' +
        '<div class="chat-rp-detail-meta">共 ' + (p.total_count | 0) + ' 个 · ￥' + (parseFloat(p.total_amount || 0).toFixed(2)) + '</div>' +
        (p.createtime
          ? ('<div class="chat-rp-detail-send-time">发送时间 ' + escapeHtml(formatTimeSec(p.createtime)) + '</div>')
          : '') +
        mineLine +
        fairBits +
        (locked
          ? '<div class="chat-rp-privacy-tip locked">🔒 隐私群：领取人资料已隐藏</div>'
          : '');
      // 内嵌验证：点击切换，不跳页
      var fairBtn = head.querySelector('.chat-rp-fair-link');
      if (fairBtn && !fairBtn._bound) {
        fairBtn._bound = true;
        fairBtn.onclick = function (ev) {
          ev.preventDefault();
          showRpFairVerify(fairBtn.getAttribute('data-packet-no') || '');
        };
      }
    }
    if (list) {
      list.classList.toggle('is-private', locked);
      list.classList.toggle('is-open', !locked);
      var rows = data.records || [];
      var ptype2 = (p.packet_type | 0);
      var settled = (p.status | 0) === 5;
      var remainN = (p.remain_count | 0);
      var stPkt = (p.status | 0);
      // 未领完不展示领取历史（优先服务端 claims_visible）
      var claimsVisible = data.claims_visible;
      if (claimsVisible == null) {
        claimsVisible = remainN <= 0 || stPkt === 2 || stPkt === 3 || stPkt === 4 || stPkt === 5;
      } else {
        claimsVisible = !!claimsVisible;
      }
      if (ptype2 === 3 && settled && head) {
        var hitN = rows.filter(function (r) { return (r.is_mine_hit | 0) === 1; }).length;
        var sumCls = hitN > 0 ? 'chat-rp-mine-summary' : 'chat-rp-mine-summary is-safe';
        var sumText = hitN > 0 ? ('本局中雷 ' + hitN + ' 人') : '本局无人中雷';
        head.insertAdjacentHTML('beforeend', '<div class="' + sumCls + '">' + sumText + '</div>');
      }
      if (!claimsVisible) {
        var hideTip = chatT('chat_rp_claims_after_finish');
        if (!hideTip || hideTip === 'chat_rp_claims_after_finish') {
          hideTip = '红包领完后可查看领取详情';
        }
        list.innerHTML = '<div class="chat-empty chat-rp-claims-hidden">' + escapeHtml(hideTip) + '</div>';
      } else if (!rows.length) {
        list.innerHTML = '<div class="chat-empty">' + escapeHtml(chatT('chat_no_claims')) + '</div>';
      } else {
        list.innerHTML = rows.map(function (r) {
          var uid = r.user_id | 0;
          var isSelf = uid === (state.userId | 0);
          var gray = locked && !isSelf;
          var nick = r.nickname || '用户';
          var avInner;
          if (!gray && r.avatar) {
            avInner = '<img src="' + escapeHtml(encodeUriPath(r.avatar)) + '" alt="">';
          } else if (!gray) {
            avInner = avatarImgHtml('');
          } else {
            avInner = escapeHtml((nick || 'U').charAt(0));
          }
          // locked=不可点资料；is-gray=隐私脱敏灰头
          var avHtml = '<div class="chat-rp-record-avatar locked' + (gray ? ' is-gray' : '') + '" aria-disabled="true">' + avInner + '</div>';
          var nameCls = 'chat-rp-record-name locked' + (gray ? ' is-masked' : '');
          var lockIcon = gray ? '<span class="chat-rp-lock" aria-hidden="true">🔒</span>' : '';
          var tags = '';
          if (r.is_best) tags += ' 手气最佳';
          if (r.is_worst) tags += ' 手气最差';
          if (ptype2 === 3) {
            var tail = (r.tail_digit != null) ? (r.tail_digit | 0) : null;
            var hit = (r.is_mine_hit | 0) === 1;
            if (settled || hit) {
              tags += hit
                ? ' <span class="is-mine-hit">中雷</span>'
                : ' <span class="is-mine-safe">未中雷</span>';
            }
            if (tail != null) tags += ' · 尾' + tail;
          } else if (r.is_mine_hit) {
            tags += ' 中雷';
          }
          var grabTime = r.createtime ? formatTimeSec(r.createtime) : '';
          return (
            '<div class="chat-rp-record-item' + (gray ? ' is-locked' : '') + '">' + avHtml +
              '<div class="chat-rp-record-main">' +
                '<div class="' + nameCls + '" data-uid="' + uid + '">' +
                  lockIcon + escapeHtml(nick) +
                '</div>' +
                '<div class="chat-rp-record-amt">￥' + parseFloat(r.amount || 0).toFixed(2) + tags +
                '</div>' +
                (grabTime
                  ? ('<div class="chat-rp-record-time">领取时间 ' + escapeHtml(grabTime) + '</div>')
                  : '') +
              '</div></div>'
          );
        }).join('');
      }
    }
    if (grabBtn) {
      var grabbed = !!data.mine;
      var finished = (p.remain_count | 0) <= 0;
      var st = (p.status | 0);
      var expired = st === 3 || st === 4 || ((p.expiretime | 0) > 0 && Math.floor(Date.now() / 1000) >= (p.expiretime | 0) && st !== 2 && st !== 5);
      // 封面状态延后更新，避免阻塞详情页首帧
      setTimeout(function () {
        if (state._rpDetailPacketId !== packetId) return;
        if (grabbed || expired) {
          markRpCover(packetId, {
            grabbed: grabbed,
            expired: expired,
            faded: true,
            mine_digit: (p.packet_type | 0) === 3 ? (p.mine_digit | 0) : undefined
          });
          try { refreshRpOrMessages(packetId, true); } catch (eCover) {}
        } else if ((p.packet_type | 0) === 3 && p.mine_digit != null) {
          markRpCover(packetId, { mine_digit: p.mine_digit | 0 });
          try { refreshRpOrMessages(packetId, true); } catch (eMine) {}
        }
      }, 0);
      grabBtn.style.display = (!grabbed && !finished && !expired) ? '' : 'none';
      // 私聊红包：仅对方显示「开红包」
      if ((p.scope_type | 0) === 1) {
        var isRecipient = (p.to_user_id | 0) === (state.userId | 0);
        if (!isRecipient) grabBtn.style.display = 'none';
      }
      grabBtn.setAttribute('data-packet', String(packetId));
    }
  }

  async function openRedPacketDetail(packetId) {
    packetId = packetId | 0;
    if (!packetId) return;
    ensureChatOverlays();
    hideRpFairVerify();
    state._rpDetailPacketId = packetId;
    var head = $('chatRpDetailHead');
    var list = $('chatRpDetailList');
    var grabBtn = $('chatRpDetailGrabBtn');
    if (grabBtn) grabBtn.style.display = 'none';
    if (head) {
      head.innerHTML = '<div class="chat-rp-detail-bless">红包</div>' +
        '<div class="chat-rp-detail-meta">加载中…</div>';
    }
    if (list) {
      list.classList.remove('is-private', 'is-open');
      list.innerHTML = '<div class="chat-empty">加载中…</div>';
    }
    // 先打开面板，再异步拉数据（避免点击卡顿）
    openSubPane('chatRpDetailPane');
    var lastErr = null;
    var attempt = 0;
    while (attempt < 3) {
      attempt++;
      try {
        // detail 优先走 HTTP，不强制等 WS
        var packet = await send('redpacket.detail', { packet_id: packetId }, { timeoutMs: 20000 });
        if (state._rpDetailPacketId !== packetId) return;
        applyRedPacketDetailData(packetId, packet.data || {});
        return;
      } catch (e) {
        lastErr = e;
        var msg = (e && e.message) || '';
        if (msg !== '超时' && msg !== '未连接' && msg !== 'The user aborted a request.') break;
        await new Promise(function (r) { setTimeout(r, 350 * attempt); });
      }
    }
    if (state._rpDetailPacketId !== packetId) return;
    if (head) {
      head.innerHTML = '<div class="chat-rp-detail-bless">红包</div>' +
        '<div class="chat-rp-detail-meta">加载失败</div>';
    }
    if (list) {
      list.innerHTML = '<div class="chat-empty">' + escapeHtml((lastErr && lastErr.message) || '加载失败') + '</div>';
    }
    if (typeof showFanshubToast === 'function') showFanshubToast((lastErr && lastErr.message) || '加载失败', 'error');
  }

  function scrollMsgToLatest() {
    var box = $('chatMsgScroll');
    if (!box) return;
    var go = function () {
      try {
        var rows = box.querySelectorAll('.chat-msg-row');
        var last = rows.length ? rows[rows.length - 1] : null;
        if (last && last.scrollIntoView) {
          last.scrollIntoView({ block: 'end', inline: 'nearest', behavior: 'auto' });
        }
        // flex/图片高度变化时再顶一次到底
        box.scrollTop = Math.max(box.scrollHeight - box.clientHeight, 0);
      } catch (e) {
        try { box.scrollTop = box.scrollHeight; } catch (e2) {}
      }
    };
    go();
    if (typeof requestAnimationFrame === 'function') {
      requestAnimationFrame(function () {
        go();
        requestAnimationFrame(go);
      });
    }
    setTimeout(go, 60);
    setTimeout(go, 180);
    setTimeout(go, 400);
  }

  /** 打开会话：有未读滚到首条未读，否则滚到底 */
  function scrollRoomOnOpen(lastReadId, unreadCount) {
    var box = $('chatMsgScroll');
    if (!box) return;
    lastReadId = lastReadId | 0;
    unreadCount = unreadCount | 0;
    var targetMid = 0;
    if (unreadCount > 0) {
      for (var i = 0; i < state.messages.length; i++) {
        var m = state.messages[i];
        var mid = m.id | 0;
        if (mid <= 0) continue;
        if (lastReadId > 0 && mid <= lastReadId) continue;
        if ((m.from_user_id | 0) === (state.userId | 0)) continue;
        if ((m.status | 0) === 2) continue;
        targetMid = mid;
        break;
      }
    }
    var go = function () {
      if (targetMid > 0) {
        var el = box.querySelector('.chat-msg-row[data-mid="' + targetMid + '"]');
        if (el) {
          try {
            el.scrollIntoView({ block: 'start', inline: 'nearest', behavior: 'auto' });
            return;
          } catch (e0) {
            try {
              box.scrollTop = Math.max(0, el.offsetTop - 12);
              return;
            } catch (e1) {}
          }
        }
      }
      scrollMsgToLatest();
    };
    go();
    if (typeof requestAnimationFrame === 'function') {
      requestAnimationFrame(function () {
        go();
        requestAnimationFrame(go);
      });
    }
    setTimeout(go, 60);
    setTimeout(go, 200);
    setTimeout(go, 450);
  }

  function buildMessageRowHtml(msg) {
    cacheSenderFromMsg(msg);
    var mine = (msg.from_user_id | 0) === state.userId;
    var time = formatTimeSec(msg.createtime);
    var type = msg.msg_type | 0;
    var recalled = (msg.status | 0) === 2;
    if (recalled) {
      var isPrivate = ((state.room && state.room.type) | 0) === 1
        || (msg.conversation_type | 0) === 1;
      var tip = isPrivate
        ? (mine ? '你删除了一条消息' : '对方删除了一条消息')
        : (mine ? '你撤回了一条消息' : '对方撤回了一条消息');
      return '<div class="chat-msg-row system" data-mid="' + (msg.id | 0) + '">' +
        sysNoticeHtml(tip) +
      '</div>';
    }
    if (type === 3) {
      return '<div class="chat-msg-row system">' + sysNoticeHtml(msg.content || '') + '</div>';
    }
    var actions = msgActionsHtml(msg, mine);
    if (type === 2) {
      var extra = parseExtra(msg);
      return groupMessageWrap(mine, msg.from_user_id, renderRpCardHtml(extra, msg, formatTimeSec(msg.createtime)), actions, msg);
    }
    if (type === 8) {
      var tfExtra = parseExtra(msg);
      return groupMessageWrap(mine, msg.from_user_id, renderTransferCardHtml(tfExtra, msg, time), actions, msg);
    }
    if (type === 4) {
      var imgExtra = parseExtra(msg);
      var imgUrl = mediaUrl(imgExtra);
      var imgHtml = imgUrl
        ? '<img class="chat-media-img" src="' + escapeHtml(imgUrl) + '" alt="图片" data-preview="' + escapeHtml(imgUrl) + '" data-preview-type="image">'
        : escapeHtml(msg.content || '[图片]');
      return groupMessageWrap(mine, msg.from_user_id,
        '<div class="chat-bubble media">' + imgHtml + '<span class="meta">' + time + '</span></div>', actions, msg);
    }
    if (type === 5) {
      var vidExtra = parseExtra(msg);
      var vidUrl = mediaUrl(vidExtra);
      var vidHtml = vidUrl
        ? ('<div class="chat-media-video-wrap">' +
            '<video class="chat-media-video" src="' + escapeHtml(vidUrl) + '" controls playsinline preload="metadata"></video>' +
            '<button type="button" class="chat-media-zoom-btn" data-preview="' + escapeHtml(vidUrl) + '" data-preview-type="video" title="放大">放大</button>' +
          '</div>')
        : escapeHtml(msg.content || '[视频]');
      return groupMessageWrap(mine, msg.from_user_id,
        '<div class="chat-bubble media">' + vidHtml + '<span class="meta">' + time + '</span></div>', actions, msg);
    }
    if (type === 6) {
      var stExtra = parseExtra(msg);
      var stUrl = mediaUrl(stExtra);
      var stCode = stExtra.code || '';
      var stHtml = stUrl
        ? '<img class="chat-sticker-img" src="' + escapeHtml(stUrl) + '" alt="' + escapeHtml(stCode || '表情') + '" data-preview="' + escapeHtml(stUrl) + '" data-preview-type="image">'
        : escapeHtml(msg.content || '[表情]');
      return groupMessageWrap(mine, msg.from_user_id,
        '<div class="chat-bubble sticker">' + stHtml + '<span class="meta">' + time + '</span></div>', actions, msg);
    }
    if (type === 7) {
      var fileExtra = parseExtra(msg);
      var fileUrl = mediaUrl(fileExtra);
      var fileName = fileExtra.name || '文件';
      var fileSize = fileExtra.size ? (' · ' + formatFileSize(fileExtra.size)) : '';
      var fileHtml = fileUrl
        ? ('<a class="chat-file-card" href="' + escapeHtml(fileUrl) + '" target="_blank" rel="noopener">' +
            '<span class="chat-file-icon">📎</span>' +
            '<span class="chat-file-meta"><span class="chat-file-name">' + escapeHtml(fileName) + '</span>' +
            '<span class="chat-file-size">' + escapeHtml((fileExtra.ext || '') + fileSize) + '</span></span></a>')
        : escapeHtml(msg.content || '[文件]');
      return groupMessageWrap(mine, msg.from_user_id,
        '<div class="chat-bubble media">' + fileHtml + '<span class="meta">' + time + '</span></div>', actions, msg);
    }
    if (type === 1) {
      var recovered = resolveStickerFromContent(msg.content || '');
      if (recovered) {
        var rUrl = mediaUrl(recovered);
        var rHtml = rUrl
          ? '<img class="chat-sticker-img" src="' + escapeHtml(rUrl) + '" alt="' + escapeHtml(recovered.code || '表情') + '" data-preview="' + escapeHtml(rUrl) + '" data-preview-type="image">'
          : escapeHtml(msg.content || '');
        return groupMessageWrap(mine, msg.from_user_id,
          '<div class="chat-bubble sticker">' + rHtml + '<span class="meta">' + time + '</span></div>', actions, msg);
      }
    }
    var text = msg.content || '';
    var emojiOnly = isEmojiOnlyText(text);
    // 文字：内容 + 空格 + 时间（时间贴在最后一行末尾，略小）；纯表情仍单独一行时间
    var bubbleCls = 'chat-bubble' + (emojiOnly ? ' emoji-only' : ' text-msg');
    var timeHtml = emojiOnly
      ? ('<span class="meta">' + time + '</span>')
      : (' <span class="meta">' + time + '</span>');
    return groupMessageWrap(mine, msg.from_user_id,
      '<div class="' + bubbleCls + '">' + escapeHtml(text) + timeHtml + '</div>', actions, msg);
  }

  function renderMessages(skipScroll) {
    var box = $('chatMsgScroll');
    if (!box) return;
    if (!state.messages.length) {
      box.innerHTML = '<div class="chat-empty">' + escapeHtml(chatT('chat_no_messages')) + '</div>';
      return;
    }
    box.innerHTML = state.messages.map(function (msg) {
      // 文字消息时间贴在气泡内容末尾；其它类型仍用气泡内 meta
      return buildMessageRowHtml(msg);
    }).join('');
    if (!skipScroll) scrollMsgToLatest();
  }

  function applyRecalledMessage(msg) {
    if (!msg || !msg.id) return;
    var found = false;
    for (var i = 0; i < state.messages.length; i++) {
      if ((state.messages[i].id | 0) === (msg.id | 0) || state.messages[i].msg_id === msg.msg_id) {
        state.messages[i] = Object.assign({}, state.messages[i], msg, { status: 2, content: '[已撤回]' });
        found = true;
        break;
      }
    }
    if (found) renderMessages();
    upsertListFromMessage(Object.assign({}, msg, { status: 2, content: '[已撤回]' }));
  }

  async function recallMessage(messageId) {
    messageId = messageId | 0;
    if (!messageId) return;
    try {
      var packet = await send('message.recall', { message_id: messageId });
      var msg = packet.data && packet.data.message;
      if (msg) applyRecalledMessage(msg);
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '撤回失败', 'error');
    }
  }

  function upsertListFromMessage(msg) {
    if (!msg) return;
    var type = msg.conversation_type | 0;
    var id = type === 2 ? (msg.group_id || msg.conversation_id) : msg.conversation_id;
    var peer = type === 1 ? peerFromMsg(msg) : 0;
    var found = null;
    for (var i = 0; i < state.list.length; i++) {
      var it = state.list[i];
      var iid = (it.conversation_type | 0) === 2 ? (it.group_id || it.conversation_id) : it.conversation_id;
      if ((it.conversation_type | 0) === type && String(iid) === String(id)) {
        found = it;
        break;
      }
    }
    if (!found) {
      found = {
        conversation_type: type,
        conversation_id: String(msg.conversation_id),
        peer_user_id: peer,
        group_id: type === 2 ? (msg.group_id | 0) : 0,
        title: type === 2 ? ('群 ' + id) : ('ID ' + peer),
        avatar: '',
        last_message: msg,
        updatetime: msg.createtime | 0,
        pinned: false
      };
      state.list.unshift(found);
    } else {
      found.last_message = msg;
      found.updatetime = msg.createtime | 0;
    }
    sortConvListInPlace();
    scheduleRenderList();
    scheduleSaveListCache();
  }

  function appendMessage(msg) {
    if (!msg) return;
    if (!msg.msg_id && !(msg.id | 0)) return;
    cacheSenderFromMsg(msg);
    for (var i = 0; i < state.messages.length; i++) {
      if ((msg.msg_id && state.messages[i].msg_id === msg.msg_id)
        || ((msg.id | 0) && (state.messages[i].id | 0) === (msg.id | 0))) {
        return;
      }
    }
    state.messages.push(msg);
    // 增量追加，避免每条推送整页重绘
    var box = $('chatMsgScroll');
    if (box && state.messages.length > 1 && box.querySelector('.chat-msg-row, .sys-time, .sys-notice')) {
      try {
        box.insertAdjacentHTML('beforeend', buildMessageRowHtml(msg));
        scrollMsgToLatest();
        scheduleSaveHistCache();
        return;
      } catch (eInc) {}
    }
    renderMessages();
    scrollMsgToLatest();
    scheduleSaveHistCache();
  }

  function scheduleUnreadSync() {
    if (state.unreadSyncTimer) clearTimeout(state.unreadSyncTimer);
    // 本地 upsert 已更新预览；全量列表仅作低频校准
    state.unreadSyncTimer = setTimeout(function () {
      state.unreadSyncTimer = null;
      refreshList(false).catch(function () {});
    }, 8000);
  }

  function playIncomingBeep() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      if (!state._audioCtx) state._audioCtx = new Ctx();
      var ctx = state._audioCtx;
      if (ctx.state === 'suspended') {
        ctx.resume().catch(function () {});
      }
      var o = ctx.createOscillator();
      var g = ctx.createGain();
      o.type = 'sine';
      o.frequency.value = 920;
      g.gain.value = 0.05;
      o.connect(g);
      g.connect(ctx.destination);
      var t0 = ctx.currentTime;
      o.start(t0);
      g.gain.exponentialRampToValueAtTime(0.001, t0 + 0.22);
      o.stop(t0 + 0.24);
    } catch (e) {}
  }

  function notifyIncomingMessage(msg) {
    if (!msg || ((msg.from_user_id | 0) === (state.userId | 0))) return;
    var type = msg.conversation_type | 0;
    var id = type === 2 ? (msg.group_id || msg.conversation_id) : msg.conversation_id;
    // 正在看该会话：气泡已出现，不再弹提示
    if (state.room && state.room.type === type && String(state.room.id) === String(id)) {
      return;
    }
    var mtype = msg.msg_type | 0;
    var isRp = mtype === 2;
    var isTf = mtype === 8;
    var prev = '';
    try { prev = previewText(msg) || ''; } catch (e0) { prev = msg.content || ''; }
    var tip = isRp
      ? ('🧧 ' + (prev || '收到红包'))
      : (isTf ? ('💸 ' + (prev || '收到转账')) : ('💬 ' + (prev || '新消息')));
    // 节流：1.2s 内最多一条 toast，避免刷屏
    var now = Date.now();
    if (!state._lastIncomingToastAt || (now - state._lastIncomingToastAt) > 1200) {
      state._lastIncomingToastAt = now;
      if (typeof showFanshubToast === 'function') {
        showFanshubToast(tip, 'info', 2600);
      }
    }
    playIncomingBeep();
    try {
      if (document.hidden || !document.hasFocus()) {
        var oldTitle = document.title || '';
        var bare = oldTitle.replace(/^【[^】]*】\s*/, '');
        document.title = (isRp ? '【红包】' : '【新消息】') + bare;
        clearTimeout(state._titleFlashTimer);
        state._titleFlashTimer = setTimeout(function () {
          document.title = bare;
        }, 2500);
      }
    } catch (e1) {}
  }

  function onIncomingMessage(msg) {
    upsertListFromMessage(msg);
    var type = msg.conversation_type | 0;
    var id = type === 2 ? (msg.group_id || msg.conversation_id) : msg.conversation_id;
    if (state.room && state.room.type === type && String(state.room.id) === String(id)) {
      appendMessage(msg);
      markRead(type, id, msg.id);
    } else if ((msg.from_user_id | 0) !== state.userId) {
      bumpUnread(type, id, msg.id);
      notifyIncomingMessage(msg);
      scheduleUnreadSync();
    }
  }

  async function refreshList(force) {
    // 先出缓存/骨架，网络回来再覆盖
    if (!state.list.length) {
      if (!hydrateListFromCache()) showListSkeleton();
    }
    var now = Date.now();
    if (!force && state._listFetchAt && (now - state._listFetchAt) < 3000 && state.list && state.list.length) {
      scheduleRenderList();
      return;
    }
    if (state._listFetching) {
      return state._listFetching;
    }
    state._listFetching = (async function () {
      try {
        var packet = await send('conversation.list', { limit: 50 });
        var prevUnread = state.unread || {};
        state.list = (packet.data && packet.data.list) || [];
        sortConvListInPlace();
        state.unread = {};
        state.list.forEach(function (item) {
          var type = item.conversation_type | 0;
          var id = type === 2 ? (item.group_id || item.conversation_id) : item.conversation_id;
          var key = convKey(type, id);
          var serverUnread = item.unread_count | 0;
          var localUnread = prevUnread[key] | 0;
          var merged = Math.max(serverUnread, localUnread);
          if (merged > 0) {
            state.unread[key] = merged;
          }
        });
        state._listFetchAt = Date.now();
        updateTabBadge();
        renderList();
        saveListCache();
        setTimeout(function () { prefetchTopHistories(); }, 200);
      } finally {
        state._listFetching = null;
      }
    })();
    return state._listFetching;
  }

  var _groupViewPingTimer = null;
  var _viewingGroupId = 0;

  function leaveGroupViewPresence() {
    if (_groupViewPingTimer) {
      clearInterval(_groupViewPingTimer);
      _groupViewPingTimer = null;
    }
    var gid = _viewingGroupId | 0;
    _viewingGroupId = 0;
    if (gid > 0 && typeof send === 'function') {
      try { send('group.view.leave', { group_id: gid }).catch(function () {}); } catch (eLeave) {}
    }
  }

  function enterGroupViewPresence(groupId) {
    groupId = groupId | 0;
    if (groupId <= 0) return;
    if (_viewingGroupId && _viewingGroupId !== groupId) {
      leaveGroupViewPresence();
    }
    _viewingGroupId = groupId;
    if (typeof send !== 'function') return;
    var ping = function () {
      if ((_viewingGroupId | 0) !== groupId) return;
      if (!state.room || state.room.type !== 2 || (state.room.id | 0) !== groupId) return;
      send('group.view.ping', { group_id: groupId }).catch(function () {});
    };
    try {
      send('group.view.enter', { group_id: groupId }).catch(function () {});
    } catch (eEnter) {}
    if (_groupViewPingTimer) clearInterval(_groupViewPingTimer);
    _groupViewPingTimer = setInterval(ping, 40000);
  }

  async function openRoom(opts) {
    closeComposerPanels();
    closeGroupSubPanes();
    ensureStickersLoaded(false);
    // 离开上一群时扣在线
    leaveGroupViewPresence();
    state.room = {
      type: opts.type | 0,
      id: opts.id,
      peer: opts.peer | 0,
      title: opts.title || ''
    };
    if (state.room.type === 2) {
      enterGroupViewPresence(state.room.id | 0);
    }
    state.messages = [];
    state.groupMeta = null;
    state.rpCover = {};
    hideNoticePin();
    var roomKey = convKey(state.room.type, state.room.id);
    var openUnread = state.unread[roomKey] | 0;
    var openLastRead = 0;
    try { openLastRead = (loadReadMap()[roomKey] | 0); } catch (eRead) { openLastRead = 0; }
    var titleEl = $('chatRoomTitle');
    if (titleEl) titleEl.textContent = state.room.title || (state.room.type === 2 ? chatT('chat_group_default_name') : chatT('chat_private'));
    var moreBtn = $('chatGroupMoreBtn');
    var heroSpacer = $('chatRoomHeroSpacer');
    if (moreBtn) {
      moreBtn.hidden = false;
      moreBtn.style.display = '';
      moreBtn.setAttribute('aria-label', state.room.type === 2 ? '群设置' : '更多');
    }
    if (heroSpacer) {
      heroSpacer.hidden = true;
      heroSpacer.style.display = 'none';
    }
    var pane = $('chatRoomPane');
    if (pane) pane.classList.add('open');
    document.body.classList.add('chat-room-open');
    var dash = $('mainDashboardView');
    if (dash) dash.classList.add('chat-room-open');
    if (typeof setBottomActionBarVisible === 'function') setBottomActionBarVisible(false);
    setComposerMuted(false, '');
    updateComposerPolicy();
    // 先用本地历史秒开对话框，再拉最新
    var cachedHist = loadHistCache(state.room.type, state.room.id);
    var cacheAge = (cachedHist && cachedHist.at) ? (Date.now() - (cachedHist.at | 0)) : 1e15;
    if (cachedHist && cachedHist.messages && cachedHist.messages.length) {
      state.messages = cachedHist.messages;
      (state.messages || []).forEach(cacheSenderFromMsg);
      if (state.room.type === 2 && cachedHist.groupMeta) {
        state.groupMeta = cachedHist.groupMeta;
        try {
          applySpeakState(state.groupMeta);
          applyGroupRoomHeader(state.groupMeta);
          updateComposerPolicy();
        } catch (eMeta) {}
      }
      try {
        renderMessages(true);
        scrollRoomOnOpen(openLastRead, openUnread);
      } catch (eRender) {
        state.messages = [];
        var box0 = $('chatMsgScroll');
        if (box0) box0.innerHTML = '<div class="chat-empty">加载中…</div>';
        cacheAge = 1e15; // 强制走网络重拉
      }
    } else {
      var box = $('chatMsgScroll');
      if (box) box.innerHTML = '<div class="chat-empty">加载中…</div>';
    }
    var histPayload = { conversation_type: state.room.type, limit: 30 };
    if (state.room.type === 1) {
      histPayload.to_user_id = state.room.peer;
      histPayload.conversation_id = String(state.room.id);
    } else {
      histPayload.group_id = state.room.id | 0;
      histPayload.with_group = 1;
    }
    try { if (typeof ensureConnected === 'function') ensureConnected(); } catch (eConn) {}
    var applyHistoryPacket = function (packet) {
      if (!state.room || state.room.type !== (opts.type | 0) || String(state.room.id) !== String(opts.id)) {
        return;
      }
      state.messages = (packet.data && packet.data.list) || [];
      (state.messages || []).forEach(cacheSenderFromMsg);
      if (state.room.type === 2 && packet.data && (packet.data.group || packet.data.policy)) {
        state.groupMeta = mergeGroupMeta(packet.data);
        applySpeakState(state.groupMeta);
        applyGroupRoomHeader(state.groupMeta);
        updateComposerPolicy();
      }
      renderMessages(true);
      scrollRoomOnOpen(openLastRead, openUnread);
      var last = state.messages.length ? state.messages[state.messages.length - 1] : null;
      markRead(state.room.type, state.room.id, last ? last.id : 0);
      saveHistCache(state.room.type, state.room.id, state.messages, state.groupMeta);
      if (state.room.type === 2 && !state.groupMeta) {
        refreshGroupMeta().catch(function () {});
      }
    };
    // 预拉缓存很新（按下预取 / 后台预热）：先标已读，后台静默刷新，不挡首屏
    if (cacheAge < 8000 && cachedHist && cachedHist.messages && cachedHist.messages.length) {
      var lastCached = state.messages.length ? state.messages[state.messages.length - 1] : null;
      markRead(state.room.type, state.room.id, lastCached ? lastCached.id : 0);
      send('history', histPayload).then(applyHistoryPacket).catch(function () {});
      return;
    }
    try {
      var packet = await send('history', histPayload);
      applyHistoryPacket(packet);
    } catch (e) {
      var errMsg = String((e && e.message) || '');
      var friendly = mapChatApiError(errMsg, 'chat_err_load_fail');
      if (!friendly) friendly = chatT('chat_err_load_fail') || '加载失败';
      if (typeof showFanshubToast === 'function') {
        showFanshubToast(friendly, 'error');
      }
      if (!(cachedHist && cachedHist.messages && cachedHist.messages.length)) {
        var emptyBox = $('chatMsgScroll');
        if (emptyBox) {
          emptyBox.innerHTML = '<div class="chat-empty">' + escapeHtml(friendly) + '</div>';
        }
      }
      if (errMsg === 'not in group' && state.room && (state.room.type | 0) === 2) {
        var deadGid = state.room.id;
        state.list = (state.list || []).filter(function (it) {
          if ((it.conversation_type | 0) !== 2) return true;
          var iid = it.group_id || it.conversation_id;
          return String(iid) !== String(deadGid);
        });
        try { clearHistCache(2, deadGid); } catch (eClr) {}
        scheduleRenderList();
        scheduleSaveListCache();
        setTimeout(function () {
          try { closeRoom(); } catch (eClose) {}
        }, 600);
      }
    }
  }

  function closeRoom() {
    closeRpSendPage();
    if (typeof closeTransferSendPage === 'function') closeTransferSendPage();
    closeComposerPanels();
    closeMediaLightbox();
    closeGroupSubPanes();
    closeMemberSheets();
    leaveGroupViewPresence();
    state.room = null;
    state.groupMeta = null;
    setComposerMuted(false, '');
    hideNoticePin();
    var moreBtn = $('chatGroupMoreBtn');
    if (moreBtn) {
      moreBtn.hidden = false;
      moreBtn.style.display = '';
    }
    var heroSpacer = $('chatRoomHeroSpacer');
    if (heroSpacer) {
      heroSpacer.hidden = true;
      heroSpacer.style.display = 'none';
    }
    var pane = $('chatRoomPane');
    if (pane) pane.classList.remove('open');
    document.body.classList.remove('chat-room-open');
    var dash = $('mainDashboardView');
    if (dash) dash.classList.remove('chat-room-open');
    if (typeof setBottomActionBarVisible === 'function') setBottomActionBarVisible(true);
    renderList();
  }

  function setComposerMuted(muted, placeholder) {
    var wrap = $('chatComposerWrap');
    var input = $('chatInput');
    var sendBtn = $('chatSendBtn');
    if (wrap) wrap.classList.toggle('is-muted', !!muted);
    if (input) {
      input.disabled = !!muted;
      input.placeholder = muted
        ? (placeholder || '本群禁止发言，仅管理员可发言')
        : '输入消息…';
    }
    if (sendBtn) sendBtn.disabled = !!muted;
  }

  /** 禁止发文字时：按仍允许的能力生成输入框提示 */
  function buildForbidSpeakPlaceholder() {
    var parts = [];
    var policy = groupPolicy();
    var canRpSend = canSendCapability('rp')
      && policy.can_send_rp !== false
      && policy.rp_robot_only !== true;
    if (canRpSend) parts.push('发抢红包');
    if (canSendCapability('image')) parts.push('发图片');
    if (canSendCapability('video')) parts.push('发视频');
    if (canSendCapability('emoji')) parts.push('发表情');
    if (!parts.length) return '本群禁止发言，仅管理员可发言';
    return '仅可' + parts.join('、') + '操作';
  }

  function applySpeakState(meta) {
    if (!state.room || state.room.type !== 2) {
      setComposerMuted(false, '');
      return;
    }
    meta = meta || state.groupMeta || {};
    var canText = canSendCapability('text');
    // 个人禁言：policy 允许但 can_speak=false
    if (meta.can_speak === false && canText) {
      setComposerMuted(true, '你已被禁言，暂时无法发言');
      return;
    }
    if (!canText) {
      setComposerMuted(true, buildForbidSpeakPlaceholder());
    } else {
      setComposerMuted(false, '');
    }
  }

  async function refreshGroupMeta() {
    if (!state.room || state.room.type !== 2) return null;
    var packet = await send('group.info', { group_id: state.room.id | 0 });
    var data = packet.data || {};
    state.groupMeta = mergeGroupMeta(data);
    applySpeakState(state.groupMeta);
    applyGroupRoomHeader(state.groupMeta);
    renderGroupSettings();
    updateComposerPolicy();
    return state.groupMeta;
  }

  function updateComposerPolicy() {
    var policy = groupPolicy();
    var rpBtn = $('chatAttachRpBtn');
    var tfBtn = $('chatAttachTransferBtn');
    var imgBtn = $('chatAttachImageBtn') || document.querySelector('[data-attach="image"]');
    var vidBtn = $('chatAttachVideoBtn') || document.querySelector('[data-attach="video"]');
    var emojiBtn = $('chatEmojiBtn');
    var isGroup = !!(state.room && state.room.type === 2);
    var isPrivate = !!(state.room && state.room.type === 1);
    var canImage = !isGroup || canSendCapability('image');
    var canVideo = !isGroup || canSendCapability('video');
    var canEmoji = !isGroup || canSendCapability('emoji');
    if (rpBtn) {
      if (isGroup) {
        var blockRp = policy.can_send_rp === false || policy.rp_robot_only === true || !canSendCapability('rp');
        rpBtn.style.display = blockRp ? 'none' : '';
      } else {
        rpBtn.style.display = '';
      }
    }
    if (imgBtn) imgBtn.style.display = canImage ? '' : 'none';
    if (vidBtn) vidBtn.style.display = canVideo ? '' : 'none';
    if (emojiBtn) {
      emojiBtn.style.opacity = canEmoji ? '' : '0.4';
      emojiBtn.setAttribute('data-forbid-emoji', canEmoji ? '0' : '1');
    }
    if (tfBtn) {
      tfBtn.hidden = !isPrivate;
      tfBtn.style.display = isPrivate ? '' : 'none';
    }
    try {
      if (typeof syncRpTypeTabs === 'function') syncRpTypeTabs();
    } catch (eSync) {}
    // 附件策略变化时同步禁言提示文案（如仅允许发图/红包）
    try { applySpeakState(state.groupMeta); } catch (eSpeak) {}
  }

  function applyGroupRoomHeader(meta) {
    meta = meta || state.groupMeta || {};
    var g = meta.group || {};
    if (!state.room || state.room.type !== 2) {
      hideNoticePin();
      return;
    }
    var name = g.name || state.room.title || '群聊';
    state.room.title = name;
    var titleEl = $('chatRoomTitle');
    if (titleEl) titleEl.textContent = name;
    applyNoticePin(resolveGroupNotice(g));
    // 同步会话列表标题/头像（不强制整表重绘，交给防抖）
    var gid = state.room.id | 0;
    state.list.forEach(function (it) {
      if ((it.conversation_type | 0) === 2 && ((it.group_id | 0) === gid || String(it.conversation_id) === String(gid))) {
        it.title = name;
        if (g.avatar != null) it.avatar = g.avatar || '';
      }
    });
    scheduleRenderList();
  }

  function hideNoticePin() {
    var pin = $('chatNoticePin');
    if (!pin) return;
    pin.style.display = 'none';
    pin.setAttribute('aria-hidden', 'true');
    pin.classList.remove('is-expanded');
  }

  function resolveGroupNotice(g) {
    g = g || {};
    var base = String(g.notice || '').trim();
    var map = g.notice_i18n;
    if (typeof map === 'string') {
      try { map = JSON.parse(map); } catch (e) { map = null; }
    }
    if (!map || typeof map !== 'object') return base;
    var loc = (global.FanshubI18n && global.FanshubI18n.locale) || 'zh-CN';
    var local = String(map[loc] || '').trim();
    return local || base;
  }

  function applyNoticePin(notice) {
    var pin = $('chatNoticePin');
    var textEl = $('chatNoticePinText');
    if (!pin || !textEl) return;
    if (notice == null) {
      notice = resolveGroupNotice((state.groupMeta && state.groupMeta.group) || {});
    }
    notice = String(notice || '').trim();
    if (!notice || !state.room || state.room.type !== 2) {
      hideNoticePin();
      return;
    }
    var gid = String(state.room.id);
    if (state.noticeDismissed[gid] === notice) {
      hideNoticePin();
      return;
    }
    textEl.textContent = notice;
    pin.style.display = '';
    pin.setAttribute('aria-hidden', 'false');
    pin.classList.remove('is-expanded');
  }

  function dismissNoticePin() {
    if (!state.room || state.room.type !== 2) return;
    var notice = resolveGroupNotice((state.groupMeta && state.groupMeta.group) || {});
    if (notice) state.noticeDismissed[String(state.room.id)] = notice;
    hideNoticePin();
  }

  function closeGroupSubPanes() {
    ['chatGroupSettingsPane', 'chatGroupMembersPane', 'chatGroupInvitePane', 'chatAddFriendPane', 'chatFriendReqPane', 'chatQrScanPane'].forEach(function (id) {
      var el = $(id);
      if (el) {
        el.classList.remove('open');
        el.setAttribute('aria-hidden', 'true');
      }
    });
  }

  function openSubPane(id) {
    var el = $(id);
    if (!el) return;
    el.classList.add('open');
    el.setAttribute('aria-hidden', 'false');
  }

  function closeSubPane(id) {
    var el = $(id);
    if (!el) return;
    if (id === 'chatRpDetailPane') {
      try { hideRpFairVerify(); } catch (eFair) {}
    }
    el.classList.remove('open');
    el.setAttribute('aria-hidden', 'true');
  }

  function openCreateGroupPane(opts) {
    opts = opts || {};
    var pane = $('chatCreateGroupPane');
    if (!pane) return;
    state.createGroup.privacy = 'private';
    state.createGroup.chatMode = 'chat';
    state.createGroup.submitting = false;
    state.createGroup.bindOwnerRebate = !!opts.fromCreateCard;
    var nameInput = $('chatCreateGroupName');
    if (nameInput) {
      nameInput.value = opts.fromCreateCard ? '我的专属保密对战群' : '';
    }
    var av = $('chatCreateGroupAvatar');
    if (av) av.textContent = state.createGroup.avatarEmoji || '🐵';
    syncCreateGroupCards();
    pane.classList.add('open');
    pane.setAttribute('aria-hidden', 'false');
    setTimeout(function () {
      if (nameInput) nameInput.focus();
    }, 50);
  }

  function closeCreateGroupPane() {
    var pane = $('chatCreateGroupPane');
    if (!pane) return;
    pane.classList.remove('open');
    pane.setAttribute('aria-hidden', 'true');
  }

  function syncCreateGroupCards() {
    var privacy = state.createGroup.privacy || 'private';
    var mode = state.createGroup.chatMode || 'chat';
    var privacyCards = document.querySelectorAll('#chatCgPrivacyCards .chat-cg-card');
    Array.prototype.forEach.call(privacyCards, function (card) {
      var val = card.getAttribute('data-privacy');
      card.classList.toggle('active', val === privacy);
      card.classList.remove('active-light');
    });
    var modeCards = document.querySelectorAll('#chatCgModeCards .chat-cg-card');
    Array.prototype.forEach.call(modeCards, function (card) {
      var val = card.getAttribute('data-mode');
      var on = val === mode;
      card.classList.toggle('active', on && val === 'grab');
      card.classList.toggle('active-light', on && val === 'chat');
    });
  }

  function cycleCreateGroupAvatar() {
    var cur = state.createGroup.avatarEmoji || '🐵';
    var idx = CREATE_GROUP_AVATARS.indexOf(cur);
    var next = CREATE_GROUP_AVATARS[(idx + 1 + CREATE_GROUP_AVATARS.length) % CREATE_GROUP_AVATARS.length];
    state.createGroup.avatarEmoji = next;
    var av = $('chatCreateGroupAvatar');
    if (av) av.textContent = next;
  }

  async function submitCreateGroup() {
    if (state.createGroup.submitting) return;
    var nameInput = $('chatCreateGroupName');
    var name = nameInput ? String(nameInput.value || '').trim() : '';
    if (!name) {
      if (typeof showFanshubToast === 'function') showFanshubToast('请输入群名称', 'error');
      if (nameInput) nameInput.focus();
      return;
    }
    state.createGroup.submitting = true;
    ['chatCreateGroupNext', 'chatCreateGroupNextTop'].forEach(function (id) {
      var btn = $(id);
      if (btn) btn.disabled = true;
    });
    try {
      var packet = await send('group.create', {
        name: name,
        member_ids: [],
        privacy_mode: state.createGroup.privacy || 'private',
        chat_mode: state.createGroup.chatMode || 'chat',
        bind_owner_rebate: state.createGroup.bindOwnerRebate ? 1 : 0
      });
      var g = packet.data && packet.data.group;
      if (!g) throw new Error('创建失败');
      closeCreateGroupPane();
      refreshList().catch(function () {});
      openRoom({ type: 2, id: g.id, peer: 0, title: g.name || name || '群聊' });
      if (typeof showFanshubToast === 'function') showFanshubToast('群聊已创建', 'success');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '创建失败', 'error');
    } finally {
      state.createGroup.submitting = false;
      ['chatCreateGroupNext', 'chatCreateGroupNextTop'].forEach(function (id) {
        var btn = $(id);
        if (btn) btn.disabled = false;
      });
    }
  }

  function closeMemberSheets() {
    ['chatMemberActionSheet', 'chatMuteDurationSheet'].forEach(function (id) {
      var el = $(id);
      if (el) {
        el.classList.remove('open');
        el.setAttribute('aria-hidden', 'true');
      }
    });
    state.memberActionTarget = null;
  }

  function roleLabel(role) {
    if ((role | 0) === 3) return '群主';
    if ((role | 0) === 2) return '管理员';
    return '';
  }

  function renderGroupSettings() {
    var meta = state.groupMeta || {};
    var g = meta.group || {};
    var myRole = meta.my_role | 0;
    var canEdit = myRole >= 2;
    var policy = groupPolicy();
    var nameEl = $('chatGroupSettingsName');
    var metaEl = $('chatGroupSettingsMeta');
    var noticeEl = $('chatGroupNoticeHint');
    var muteRow = $('chatMuteAllRow');
    var muteSwitch = $('chatMuteAllSwitch');
    var editBlock = $('chatGroupEditBlock');
    var nameInput = $('chatGroupNameInput');
    var noticeInput = $('chatGroupNoticeInput');
    var avImg = $('chatGroupSettingsAvatar');
    var avFb = $('chatGroupSettingsAvatarFb');
    var avBtn = $('chatGroupAvatarBtn');
    var avEditHint = $('chatGroupAvatarEditHint');
    var name = g.name || (state.room && state.room.title) || '群聊';
    if (nameEl) nameEl.textContent = name;
    if (metaEl) {
      var cnt = meta.member_count | 0;
      var policy = groupPolicy();
      var tag = policy.privacy_label ? (' · ' + policy.privacy_label) : '';
      var modeTag = policy.chat_mode_label ? (' · ' + policy.chat_mode_label) : '';
      metaEl.textContent = chatT('chat_group_members_count', { count: cnt }) + tag + modeTag + (meta.member_list_hidden ? '' : (' · ' + (roleLabel(meta.my_role) || '成员')));
    }
    var viewBtn = $('chatViewMembersBtn');
    if (viewBtn) viewBtn.style.display = meta.member_list_hidden ? 'none' : '';
    var settingsAddBtn = $('chatSettingsAddMemberBtn');
    if (settingsAddBtn) settingsAddBtn.style.display = canEdit ? '' : 'none';
    if (noticeEl) {
      noticeEl.textContent = canEdit ? '' : (g.notice ? ('群公告：' + g.notice) : '暂无群公告');
      noticeEl.style.display = canEdit ? 'none' : '';
    }
    if (muteRow) muteRow.style.display = canEdit ? '' : 'none';
    if (muteSwitch) {
      muteSwitch.checked = !!meta.mute_all;
      muteSwitch.disabled = !canEdit;
    }
    var forbidBlock = $('chatForbidModesBlock');
    if (forbidBlock) {
      forbidBlock.style.display = canEdit ? '' : 'none';
      var fm = (meta.forbid_modes) || (policy.forbid_modes) || groupForbidModes();
      forbidBlock.querySelectorAll('[data-forbid]').forEach(function (inp) {
        var k = inp.getAttribute('data-forbid');
        inp.checked = !!fm[k];
        inp.disabled = !canEdit;
      });
    }
    if (editBlock) editBlock.style.display = canEdit ? '' : 'none';
    if (nameInput && canEdit) nameInput.value = g.name || '';
    if (noticeInput && canEdit) noticeInput.value = g.notice || '';
    var avUrl = avatarSrc(g.avatar);
    if (avImg && avFb) {
      avImg.src = avUrl;
      avImg.style.display = '';
      avFb.style.display = 'none';
    }
    if (avBtn) avBtn.classList.toggle('can-edit', canEdit);
    if (avEditHint) avEditHint.style.display = canEdit ? '' : 'none';
    ensureChatOverlays();
    var modeBlock = $('chatGroupModeBlock');
    var privacySel = $('chatGroupPrivacyMode');
    var chatModeSel = $('chatGroupChatMode');
    if (modeBlock) modeBlock.style.display = (canEdit && myRole >= 3) ? '' : 'none';
    if (privacySel) privacySel.value = (g.privacy_mode === 'open' || policy.privacy_mode === 'open') ? 'open' : 'private';
    if (chatModeSel) chatModeSel.value = (g.chat_mode === 'grab' || policy.chat_mode === 'grab') ? 'grab' : 'chat';
    var leaveBtn = $('chatGroupLeaveBtn');
    if (leaveBtn) {
      // 群主不可退群；普通成员/管理员可退
      leaveBtn.style.display = (myRole > 0 && myRole < 3) ? '' : 'none';
    }
  }

  async function leaveCurrentGroup() {
    if (!state.room || (state.room.type | 0) !== 2) return;
    var gid = state.room.id | 0;
    if (gid <= 0) return;
    var meta = state.groupMeta || {};
    if ((meta.my_role | 0) >= 3) {
      if (typeof showFanshubToast === 'function') {
        showFanshubToast(chatT('chat_group_leave_owner_deny') || '群主不能退出群组，请先转让群主', 'info');
      }
      return;
    }
    var confirmText = chatT('chat_group_leave_confirm') || '退出后将清空本端群聊记录，且无法再接收该群消息。确定退出？';
    if (!window.confirm(confirmText)) return;
    var btn = $('chatGroupLeaveBtn');
    if (btn) btn.disabled = true;
    try {
      await send('group.leave', { group_id: gid });
      try { clearHistCache(2, gid); } catch (e0) {}
      var key = convKey(2, gid);
      state.list = (state.list || []).filter(function (it) {
        if ((it.conversation_type | 0) !== 2) return true;
        var iid = (it.group_id | 0) || parseInt(it.conversation_id, 10) || 0;
        return iid !== gid;
      });
      if (key && state.unread) delete state.unread[key];
      state.myGroups = (state.myGroups || []).filter(function (g) { return (g.id | 0) !== gid; });
      try { closeSubPane('chatGroupSettingsPane'); } catch (e1) {}
      try { closeSubPane('chatGroupMembersPane'); } catch (e2) {}
      if (typeof closeRoom === 'function') closeRoom();
      scheduleRenderList();
      scheduleSaveListCache();
      if (typeof renderMyGroups === 'function') renderMyGroups();
      if (typeof showFanshubToast === 'function') {
        showFanshubToast(chatT('chat_group_leave_ok') || '已退出群组', 'success');
      }
    } catch (e) {
      var msg = (e && e.message) || chatT('chat_group_leave_fail') || '退出失败';
      if (typeof showFanshubToast === 'function') showFanshubToast(msg, 'error');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  async function saveGroupModes() {
    if (!state.room || state.room.type !== 2) return;
    var meta = state.groupMeta || {};
    if ((meta.my_role | 0) < 3) return;
    var privacySel = $('chatGroupPrivacyMode');
    var chatModeSel = $('chatGroupChatMode');
    if (!privacySel || !chatModeSel) return;
    var btn = $('chatGroupModeSaveBtn');
    if (btn) btn.disabled = true;
    try {
      var packet = await send('group.update', {
        group_id: state.room.id | 0,
        privacy_mode: privacySel.value,
        chat_mode: chatModeSel.value
      });
      if (packet.data && packet.data.group) {
        state.groupMeta.group = packet.data.group;
        await refreshGroupMeta();
      }
      if (typeof showFanshubToast === 'function') showFanshubToast('群属性已保存', 'success');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '保存失败', 'error');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  async function saveGroupProfile() {
    if (!state.room || state.room.type !== 2) return;
    var meta = state.groupMeta || {};
    if ((meta.my_role | 0) < 2) return;
    var g = meta.group || {};
    var nameInput = $('chatGroupNameInput');
    var noticeInput = $('chatGroupNoticeInput');
    var name = nameInput ? String(nameInput.value || '').trim() : '';
    var notice = noticeInput ? String(noticeInput.value || '') : '';
    if (!name) {
      if (typeof showFanshubToast === 'function') showFanshubToast('请输入群名称', 'error');
      return;
    }
    var payload = { group_id: state.room.id | 0 };
    var changed = false;
    if (name !== String(g.name || '')) {
      payload.name = name;
      changed = true;
    }
    if (notice !== String(g.notice || '')) {
      payload.notice = notice;
      changed = true;
    }
    if (!changed) {
      if (typeof showFanshubToast === 'function') showFanshubToast('没有修改', 'info');
      return;
    }
    var btn = $('chatGroupSaveBtn');
    if (btn) btn.disabled = true;
    try {
      var packet = await send('group.update', payload);
      if (packet.data && packet.data.group) {
        state.groupMeta = state.groupMeta || {};
        state.groupMeta.group = packet.data.group;
        // 公告变更后重新展示置顶
        if (payload.notice != null) {
          delete state.noticeDismissed[String(state.room.id)];
        }
        applyGroupRoomHeader(state.groupMeta);
        renderGroupSettings();
      }
      if (typeof showFanshubToast === 'function') showFanshubToast('已保存', 'success');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '保存失败', 'error');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  async function uploadGroupAvatar(file) {
    if (!state.room || state.room.type !== 2) return;
    var meta = state.groupMeta || {};
    if ((meta.my_role | 0) < 2) return;
    if (!file) return;
    try {
      var uploaded = await uploadChatFile(file);
      var url = uploaded.url || uploaded.fullurl || '';
      if (!url) throw new Error('上传失败');
      var packet = await send('group.update', {
        group_id: state.room.id | 0,
        avatar: url
      });
      if (packet.data && packet.data.group) {
        state.groupMeta = state.groupMeta || {};
        state.groupMeta.group = packet.data.group;
        applyGroupRoomHeader(state.groupMeta);
        renderGroupSettings();
      }
      if (typeof showFanshubToast === 'function') showFanshubToast('群头像已更新', 'success');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '上传失败', 'error');
    }
  }

  async function openGroupSettings() {
    if (!state.room || state.room.type !== 2) return;
    try {
      await refreshGroupMeta();
      openSubPane('chatGroupSettingsPane');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '加载失败', 'error');
    }
  }

  function openPrivateRoomMore() {
    if (!state.room || state.room.type !== 1) return;
    ensureChatOverlays();
    var peer = state.room.peer | 0;
    var brief = getSenderBrief(peer);
    var name = state.room.title || brief.nickname || ('ID' + peer);
    var nameEl = $('chatProfileName');
    var idEl = $('chatProfileId');
    var avEl = $('chatProfileAvatar');
    var addBtn = $('chatProfileAddFriend');
    var chatBtn = $('chatProfilePrivateChat');
    var copyBtn = $('chatProfileCopyId');
    if (nameEl) nameEl.textContent = name;
    if (idEl) idEl.textContent = peer ? ('会员ID ' + peer) : '';
    if (avEl) {
      avEl.innerHTML = avatarImgHtml(brief.avatar);
    }
    if (addBtn) addBtn.style.display = 'none';
    if (chatBtn) chatBtn.style.display = 'none';
    if (!copyBtn && $('chatProfileClose')) {
      copyBtn = document.createElement('button');
      copyBtn.type = 'button';
      copyBtn.className = 'chat-action-item';
      copyBtn.id = 'chatProfileCopyId';
      copyBtn.textContent = chatT('chat_copy_member_id');
      $('chatProfileClose').parentNode.insertBefore(copyBtn, $('chatProfileClose'));
      copyBtn.onclick = function () {
        var id = String((state.room && state.room.peer) || '');
        if (!id) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(id).then(function () {
            if (typeof showFanshubToast === 'function') showFanshubToast('已复制会员ID', 'success');
          }).catch(function () {
            if (typeof showFanshubToast === 'function') showFanshubToast(id, 'info');
          });
        } else if (typeof showFanshubToast === 'function') {
          showFanshubToast(id, 'info');
        }
        closeUserProfile();
      };
    } else if (copyBtn) {
      copyBtn.style.display = '';
    }
    var pane = $('chatUserProfilePane');
    if (pane) {
      pane.classList.add('open');
      pane.setAttribute('aria-hidden', 'false');
    }
  }

  function openRoomMore() {
    if (!state.room) return;
    if (state.room.type === 2) {
      openGroupSettings();
      return;
    }
    openPrivateRoomMore();
  }

  function renderMemberList() {
    var box = $('chatMemberList');
    if (!box) return;
    var myRole = (state.groupMeta && state.groupMeta.my_role) | 0;
    var addBtn = $('chatAddMemberBtn');
    if (addBtn) addBtn.style.display = myRole >= 2 ? '' : 'none';
    if (!state.members.length) {
      box.innerHTML = '<div class="chat-empty">' + escapeHtml(chatT('chat_no_members')) + '</div>';
      return;
    }
    box.innerHTML = state.members.map(function (m) {
      var tags = '';
      if ((m.role | 0) === 3) tags += '<span class="chat-member-tag owner">群主</span>';
      if ((m.role | 0) === 2) tags += '<span class="chat-member-tag admin">管理员</span>';
      if (m.is_muted) tags += '<span class="chat-member-tag muted">禁言</span>';
      var av = avatarImgHtml(m.avatar);
      cacheSender(m.user_id | 0, m);
      var canMod = myRole >= 2 && (m.user_id | 0) !== state.userId && (m.role | 0) < myRole;
      var canProfile = false;
      var canTap = canMod;
      return (
        '<button type="button" class="chat-member-item" data-uid="' + (m.user_id | 0) + '" data-mod="' + (canMod ? '1' : '0') + '" data-profile="0"' + (canTap ? '' : ' disabled') + '>' +
          '<div class="chat-member-avatar">' + av + '</div>' +
          '<div class="chat-member-main">' +
            '<div class="chat-member-name">' + escapeHtml(m.nickname || ('ID' + m.user_id)) + '</div>' +
            '<div class="chat-member-sub">ID ' + (m.user_id | 0) + '</div>' +
          '</div>' +
          '<div class="chat-member-tags">' + tags + '</div>' +
        '</button>'
      );
    }).join('');
  }

  async function loadMembers(keyword) {
    if (!state.room || state.room.type !== 2) return;
    var packet = await send('group.members', {
      group_id: state.room.id | 0,
      keyword: keyword || ''
    });
    var data = packet.data || {};
    state.members = data.list || [];
    mergeGroupMeta(data);
    (state.members || []).forEach(function (m) { cacheSender(m.user_id | 0, m); });
    applySpeakState(state.groupMeta);
    renderMemberList();
    renderGroupSettings();
  }

  async function openGroupMembers() {
    if (!state.room || state.room.type !== 2) return;
    if (state.groupMeta && state.groupMeta.member_list_hidden && (state.groupMeta.my_role | 0) < 2) {
      if (typeof showFanshubToast === 'function') showFanshubToast('隐私群已隐藏成员列表', 'info');
      return;
    }
    state.memberKeyword = '';
    var search = $('chatMemberSearch');
    if (search) search.value = '';
    try {
      await loadMembers('');
      openSubPane('chatGroupMembersPane');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '加载失败', 'error');
    }
  }

  function openMemberAction(member) {
    if (!member) return;
    state.memberActionTarget = member;
    var myRole = (state.groupMeta && state.groupMeta.my_role) | 0;
    var title = $('chatMemberActionTitle');
    if (title) title.textContent = (member.nickname || ('ID' + member.user_id)) + ' · 操作';
    var muteBtn = $('chatActMute');
    var unmuteBtn = $('chatActUnmute');
    var setAdmin = $('chatActSetAdmin');
    var unsetAdmin = $('chatActUnsetAdmin');
    var kickBtn = $('chatActKick');
    if (muteBtn) muteBtn.style.display = member.is_muted ? 'none' : '';
    if (unmuteBtn) unmuteBtn.style.display = member.is_muted ? '' : 'none';
    if (setAdmin) setAdmin.style.display = (myRole === 3 && (member.role | 0) === 1) ? '' : 'none';
    if (unsetAdmin) unsetAdmin.style.display = (myRole === 3 && (member.role | 0) === 2) ? '' : 'none';
    if (kickBtn) kickBtn.style.display = (myRole >= 2 && (member.role | 0) < myRole) ? '' : 'none';
    var sheet = $('chatMemberActionSheet');
    if (sheet) {
      sheet.classList.add('open');
      sheet.setAttribute('aria-hidden', 'false');
    }
  }

  function openMuteDurationSheet() {
    var sheet = $('chatMuteDurationSheet');
    if (sheet) {
      sheet.classList.add('open');
      sheet.setAttribute('aria-hidden', 'false');
    }
  }

  async function doKickMember() {
    var target = state.memberActionTarget;
    if (!target || !state.room) return;
    if (!window.confirm('确定将该成员移出群组？')) return;
    try {
      await send('group.kick', {
        group_id: state.room.id | 0,
        user_id: target.user_id | 0
      });
      closeMemberSheets();
      if (typeof showFanshubToast === 'function') showFanshubToast('已移出', 'success');
      await loadMembers(state.memberKeyword);
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '操作失败', 'error');
    }
  }

  async function doMuteMember(seconds) {
    var target = state.memberActionTarget;
    if (!target || !state.room) return;
    try {
      await send('group.mute', {
        group_id: state.room.id | 0,
        user_id: target.user_id | 0,
        seconds: seconds | 0
      });
      closeMemberSheets();
      if (typeof showFanshubToast === 'function') {
        showFanshubToast(seconds > 0 ? '已禁言' : '已取消禁言', 'success');
      }
      await loadMembers(state.memberKeyword);
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '操作失败', 'error');
    }
  }

  async function doSetAdmin(isAdmin) {
    var target = state.memberActionTarget;
    if (!target || !state.room) return;
    try {
      await send('group.set_admin', {
        group_id: state.room.id | 0,
        user_id: target.user_id | 0,
        is_admin: !!isAdmin
      });
      closeMemberSheets();
      if (typeof showFanshubToast === 'function') {
        showFanshubToast(isAdmin ? '已设为管理员' : '已取消管理员', 'success');
      }
      await loadMembers(state.memberKeyword);
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '操作失败', 'error');
    }
  }

  async function toggleMuteAll(enabled) {
    if (!state.room || state.room.type !== 2) return;
    try {
      var packet = await send('group.mute_all', {
        group_id: state.room.id | 0,
        enabled: !!enabled
      });
      if (packet.data) {
        state.groupMeta = mergeGroupMeta(Object.assign({}, state.groupMeta || {}, packet.data));
        applySpeakState(state.groupMeta);
        updateComposerPolicy();
        renderGroupSettings();
      }
    } catch (e) {
      var sw = $('chatMuteAllSwitch');
      if (sw) sw.checked = !enabled;
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '操作失败', 'error');
    }
  }

  async function saveGroupForbidModes() {
    if (!state.room || state.room.type !== 2) return;
    var box = $('chatForbidModesList');
    if (!box) return;
    var flags = { text: 0, image: 0, emoji: 0, video: 0, rp: 0 };
    box.querySelectorAll('[data-forbid]').forEach(function (inp) {
      var k = inp.getAttribute('data-forbid');
      if (flags[k] != null) flags[k] = inp.checked ? 1 : 0;
    });
    try {
      var packet = await send('group.set_forbid', {
        group_id: state.room.id | 0,
        forbid_modes: flags
      });
      if (packet.data) {
        state.groupMeta = mergeGroupMeta(Object.assign({}, state.groupMeta || {}, packet.data));
        applySpeakState(state.groupMeta);
        updateComposerPolicy();
        renderGroupSettings();
      }
      if (typeof showFanshubToast === 'function') showFanshubToast('禁止模式已更新', 'success');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '操作失败', 'error');
      renderGroupSettings();
    }
  }

  function renderInviteList() {
    var box = $('chatInviteList');
    var btn = $('chatInviteConfirmBtn');
    if (!box) return;
    var selectedCount = Object.keys(state.inviteSelected).filter(function (k) {
      return state.inviteSelected[k];
    }).length;
    if (btn) {
      btn.disabled = selectedCount <= 0;
      btn.textContent = chatT('chat_confirm_add', { count: selectedCount });
    }
    if (!state.candidates.length) {
      box.innerHTML = '<div class="chat-empty">' + escapeHtml(chatT('chat_no_candidates')) + '</div>';
      return;
    }
    box.innerHTML = state.candidates.map(function (u) {
      var uid = u.user_id | 0;
      var checked = !!state.inviteSelected[uid];
      var av = avatarImgHtml(u.avatar);
      return (
        '<label class="chat-member-item chat-invite-item">' +
          '<input type="checkbox" class="chat-invite-check" data-uid="' + uid + '"' + (checked ? ' checked' : '') + '>' +
          '<div class="chat-member-avatar">' + av + '</div>' +
          '<div class="chat-member-main">' +
            '<div class="chat-member-name">' + escapeHtml(u.nickname || ('ID' + uid)) + '</div>' +
            '<div class="chat-member-sub">ID ' + uid + (u.mobile ? (' · ' + escapeHtml(u.mobile)) : '') + '</div>' +
          '</div>' +
        '</label>'
      );
    }).join('');
  }

  async function loadCandidates(keyword) {
    if (!state.room || state.room.type !== 2) return;
    var packet = await send('group.candidates', {
      group_id: state.room.id | 0,
      keyword: keyword || '',
      limit: 50
    });
    state.candidates = (packet.data && packet.data.list) || [];
    renderInviteList();
  }

  async function openGroupInvite() {
    if (!state.room || state.room.type !== 2) return;
    state.inviteSelected = {};
    state.inviteKeyword = '';
    var search = $('chatInviteSearch');
    if (search) search.value = '';
    try {
      await loadCandidates('');
      openSubPane('chatGroupInvitePane');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '加载失败', 'error');
    }
  }

  async function confirmInvite() {
    if (!state.room || state.room.type !== 2) return;
    var ids = Object.keys(state.inviteSelected).filter(function (k) {
      return state.inviteSelected[k];
    }).map(function (k) { return parseInt(k, 10); });
    if (!ids.length) return;
    var btn = $('chatInviteConfirmBtn');
    if (btn) btn.disabled = true;
    try {
      await send('group.add_members', {
        group_id: state.room.id | 0,
        member_ids: ids
      });
      if (typeof showFanshubToast === 'function') showFanshubToast('已添加 ' + ids.length + ' 人', 'success');
      closeSubPane('chatGroupInvitePane');
      await loadMembers(state.memberKeyword);
      await refreshGroupMeta();
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '添加失败', 'error');
      renderInviteList();
    }
  }

  async function sendPayload(payload) {
    if (!state.room) throw new Error('请先打开会话');
    closeComposerPanels();
    if (state.room.type === 1) {
      payload.to_user_id = state.room.peer;
      var packet = await send('private.send', payload);
      return packet.data && packet.data.message;
    }
    payload.group_id = state.room.id | 0;
    var gpacket = await send('group.send', payload);
    if (gpacket.data && gpacket.data.balance != null) {
      state.money = parseFloat(gpacket.data.balance);
      updateMoneyLabel();
    }
    return gpacket.data && gpacket.data.message;
  }

  function appendSentMessage(msg) {
    if (!msg) return;
    appendMessage(msg);
    upsertListFromMessage(msg);
  }

  async function uploadChatFile(file) {
    var fd = new FormData();
    fd.append('file', file);
    var headers = {};
    var t = token();
    if (t) headers.token = t;
    var res = await fetch(apiBase() + '/api/common/upload', { method: 'POST', headers: headers, body: fd });
    var data;
    try { data = await res.json(); } catch (e) { throw new Error('上传失败'); }
    if (data.code !== 1) throw new Error(data.msg || data.message || '上传失败');
    return data.data || {};
  }

  async function sendMedia(msgType, extra) {
    if (!state.room) {
      if (typeof showFanshubToast === 'function') showFanshubToast('请先打开会话', 'info');
      return;
    }
    if (state.room.type === 2) {
      var need = msgType === 5 ? 'video' : 'image';
      if (!canSendCapability(need)) {
        if (typeof showFanshubToast === 'function') {
          showFanshubToast(need === 'video' ? '本群禁止发视频' : '本群禁止发图', 'error');
        }
        return;
      }
    }
    try {
      var label = '[图片]';
      if (msgType === 5) label = '[视频]';
      if (msgType === 7) label = '[文件]' + ((extra && extra.name) || '');
      var msg = await sendPayload({
        msg_type: msgType,
        content: label,
        extra: extra
      });
      appendSentMessage(msg);
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '发送失败', 'error');
    }
  }

  async function handleGenericFile(file) {
    if (!file || !state.room) return;
    var maxSize = 30 * 1024 * 1024;
    if (file.size > maxSize) {
      if (typeof showFanshubToast === 'function') showFanshubToast('文件不能超过30MB', 'error');
      return;
    }
    if (typeof showFanshubToast === 'function') showFanshubToast('上传中…', 'info');
    try {
      var uploaded = await uploadChatFile(file);
      var url = uploaded.url || '';
      if (!url) throw new Error('上传失败');
      var name = file.name || 'file';
      var ext = '';
      var dot = name.lastIndexOf('.');
      if (dot >= 0) ext = name.slice(dot + 1).toLowerCase();
      await sendMedia(7, {
        url: url,
        fullurl: uploaded.fullurl || '',
        name: name,
        size: file.size | 0,
        ext: ext,
        mime: file.type || ''
      });
      if (typeof showFanshubToast === 'function') showFanshubToast('已发送', 'success');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '上传失败', 'error');
    }
  }

  async function handleMediaFile(file, kind) {
    if (!file || !state.room) return;
    var maxSize = kind === 'video' ? (20 * 1024 * 1024) : (8 * 1024 * 1024);
    if (file.size > maxSize) {
      if (typeof showFanshubToast === 'function') {
        showFanshubToast(kind === 'video' ? '视频不能超过20MB' : '图片不能超过8MB', 'error');
      }
      return;
    }
    if (typeof showFanshubToast === 'function') showFanshubToast('上传中…', 'info');
    try {
      var uploaded = await uploadChatFile(file);
      var url = uploaded.url || '';
      if (!url) throw new Error('上传失败');
      var extra = {
        url: url,
        fullurl: uploaded.fullurl || '',
        name: file.name || ''
      };
      if (kind === 'image' && file.type && file.type.indexOf('image/') === 0) {
        try {
          var dim = await readImageSize(file);
          extra.w = dim.w;
          extra.h = dim.h;
        } catch (e) {}
      }
      await sendMedia(kind === 'video' ? 5 : 4, extra);
      if (typeof showFanshubToast === 'function') showFanshubToast('已发送', 'success');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '上传失败', 'error');
    }
  }

  function readImageSize(file) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      var objUrl = URL.createObjectURL(file);
      img.onload = function () {
        var w = img.naturalWidth || img.width || 0;
        var h = img.naturalHeight || img.height || 0;
        URL.revokeObjectURL(objUrl);
        resolve({ w: w, h: h });
      };
      img.onerror = function () {
        URL.revokeObjectURL(objUrl);
        reject(new Error('bad image'));
      };
      img.src = objUrl;
    });
  }

  async function sendText() {
    var input = $('chatInput');
    if (!input || !state.room) return;
    if (input.disabled || ($('chatComposerWrap') && $('chatComposerWrap').classList.contains('is-muted'))) {
      if (typeof showFanshubToast === 'function') {
        showFanshubToast(input.placeholder || '当前无法发言', 'error');
      }
      return;
    }
    var text = String(input.value || '').trim();
    if (!text) return;
    input.value = '';
    try {
      var msg = await sendPayload({ content: text, msg_type: 1 });
      appendSentMessage(msg);
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '发送失败', 'error');
    }
  }

  async function grabPacket(packetId, sliderPayload) {
    packetId = packetId | 0;
    if (!packetId) return;
    if (state._grabBusy && !(sliderPayload && sliderPayload.slider_token)) {
      return;
    }
    state._grabBusy = true;
    var grabBtn = $('chatRpDetailGrabBtn');
    var prevGrabText = '';
    if (grabBtn && !(sliderPayload && sliderPayload.slider_token)) {
      prevGrabText = grabBtn.textContent || '开红包';
      grabBtn.disabled = true;
      grabBtn.textContent = '领取中…';
    }
    function restoreGrabBtn() {
      state._grabBusy = false;
      if (grabBtn) {
        grabBtn.disabled = false;
        if (prevGrabText) grabBtn.textContent = prevGrabText;
      }
    }
    function mapGrabError(msg) {
      msg = String(msg || '');
      if (msg.indexOf('balance_not_enough_for_compensate') === 0
        || msg.indexOf('余额不足赔付') === 0
        || msg.indexOf('余额不足以') === 0
        || msg.indexOf('红宝不足') === 0) {
        return chatT('chat_rp_grab_need_compensate') || '未达到赔付该包金额无法领取';
      }
      var map = {
        already_grabbed: '你已经抢过这个红包了',
        'already grabbed': '你已经抢过这个红包了',
        'packet empty': '手慢了，红包已被抢完',
        'packet expired': '红包已过期',
        'packet closed': '红包已结束',
        'packet not found': '红包不存在',
        'not in group': '你不在该群内',
        balance_below_mine_min: '红宝须大于本群最低金额限制，才能领取扫雷红包',
        mine_hash_pending: '扫雷开奖中：等待波场哈希末位匹配雷号后再抢',
        slider_required: '请完成滑块验证后再抢',
        'grab cancelled': '已取消验证'
      };
      return map[msg] || msg || '领取失败';
    }
    try {
      var data = { packet_id: packetId };
      try {
        var fp = localStorage.getItem('fans_hub_device_fp') || '';
        if (fp) data.device_fp = fp;
      } catch (e0) {}
      if (sliderPayload && typeof sliderPayload === 'object') {
        if (sliderPayload.slider_token) data.slider_token = sliderPayload.slider_token;
        if (sliderPayload.slider_x != null) data.slider_x = sliderPayload.slider_x | 0;
        if (sliderPayload.slider_duration != null) data.slider_duration = sliderPayload.slider_duration | 0;
        if (sliderPayload.slider_max != null) data.slider_max = sliderPayload.slider_max | 0;
      }
      var packet = await send('redpacket.grab', data);
      if (packet && packet.type === 'redpacket.challenge' && packet.data && packet.data.code === 'slider_required') {
        await new Promise(function (resolve, reject) {
          var opener = (typeof window.openGrabSliderCaptcha === 'function')
            ? window.openGrabSliderCaptcha
            : (typeof window.openSliderCaptcha === 'function' ? window.openSliderCaptcha : null);
          if (!opener) {
            if (typeof showFanshubToast === 'function') {
              showFanshubToast(packet.data.message || '请完成滑块验证后重试', 'error');
            }
            reject(new Error(packet.data.message || 'slider_required'));
            return;
          }
          if (grabBtn) grabBtn.textContent = '验证中…';
          if (typeof showFanshubToast === 'function' && !(sliderPayload && sliderPayload.slider_token)) {
            showFanshubToast(packet.data.message || '请完成滑块验证', 'info');
          }
          opener('', function (payload) {
            if (grabBtn) grabBtn.textContent = '领取中…';
            if (typeof showFanshubToast === 'function') {
              showFanshubToast('验证通过，正在抢包…', 'success');
            }
            state._grabBusy = false;
            grabPacket(packetId, payload || {}).then(resolve).catch(reject);
          }, function () {
            reject(new Error('grab cancelled'));
          });
        });
        return;
      }
      var amt = packet.data && packet.data.amount;
      if (packet.data && packet.data.balance != null) {
        state.money = parseFloat(packet.data.balance);
        updateMoneyLabel();
      }
      markRpCover(packetId, { grabbed: true, faded: true });
      try { refreshRpOrMessages(packetId, true); } catch (eRender) {}
      if (typeof showFanshubToast === 'function') {
        showFanshubToast(amt != null ? ('抢到 ￥' + parseFloat(amt).toFixed(2)) : '领取成功', 'success');
      }
      // 详情打开时刷新列表/按钮状态
      if ($('chatRpDetailPane') && $('chatRpDetailPane').classList.contains('open')) {
        try { openRedPacketDetail(packetId); } catch (e1) {}
      }
    } catch (e) {
      if (typeof showFanshubToast === 'function') {
        showFanshubToast(mapGrabError(e && e.message), 'error');
      }
    } finally {
      restoreGrabBtn();
    }
  }

/* === 03-rp.js === */
/* js/chat/03-rp.js — red packet send/grab */

  /** 按群允许类型显示/隐藏红宝类型 Tab；仅一种时仍显示该按钮（如扫雷群只显示埋雷） */
  function syncRpTypeTabs() {
    var tabs = $('chatRpTypeTabs');
    if (!tabs) return;
    var isPrivate = !!(state.room && state.room.type === 1);
    if (isPrivate) {
      tabs.style.display = 'none';
      tabs.hidden = true;
      return;
    }
    var enabled = enabledRpTypes();
    var btns = tabs.querySelectorAll('.chat-rp-type-btn');
    var firstVisible = null;
    btns.forEach(function (btn) {
      var t = parseInt(btn.getAttribute('data-type'), 10) || 0;
      var ok = enabled.indexOf(t) >= 0;
      btn.hidden = !ok;
      btn.style.display = ok ? '' : 'none';
      if (ok && !firstVisible) firstVisible = btn;
    });
    var active = tabs.querySelector('.chat-rp-type-btn.active');
    var activeOk = active && !active.hidden && active.style.display !== 'none';
    if (!activeOk) {
      btns.forEach(function (b) { b.classList.remove('active'); });
      if (firstVisible) firstVisible.classList.add('active');
    }
    // 至少有一种允许类型就展示 Tab（单类型也要让用户看到「埋雷」）
    var show = enabled.length > 0;
    tabs.hidden = !show;
    tabs.style.display = show ? '' : 'none';
  }

  function getRpPacketType() {
    var active = document.querySelector('#chatRpTypeTabs .chat-rp-type-btn.active');
    if (active && !active.hidden && active.style.display !== 'none') {
      return parseInt(active.getAttribute('data-type'), 10) || 2;
    }
    var enabled = enabledRpTypes();
    if (enabled.length) return enabled[0];
    return 2;
  }

  function enabledRpTypes() {
    var isGroup = !!(state.room && state.room.type === 2);
    if (!isGroup) return [1, 2, 3];
    var raw = '';
    try {
      var policy = typeof groupPolicy === 'function' ? groupPolicy() : {};
      if (policy && policy.rp_enabled_types) raw = String(policy.rp_enabled_types);
    } catch (e0) {}
    if (!raw && state.groupMeta && state.groupMeta.group) {
      raw = String(state.groupMeta.group.rp_enabled_types || '');
    }
    if (!raw) raw = '1,2,3,4';
    var list = raw.split(',').map(function (x) { return parseInt(x, 10); })
      .filter(function (n) { return n === 1 || n === 2 || n === 3 || n === 4; });
    return list.length ? list : [2];
  }

  function groupRpFixedAmount() {
    if (!(state.room && state.room.type === 2)) return 0;
    var fixed = 0;
    try {
      var policy = typeof groupPolicy === 'function' ? groupPolicy() : {};
      fixed = parseFloat(policy && policy.rp_fixed_amount) || 0;
    } catch (e1) {}
    if (!(fixed > 0) && state.groupMeta && state.groupMeta.group) {
      fixed = parseFloat(state.groupMeta.group.rp_fixed_amount) || 0;
    }
    return fixed > 0 ? Math.round(fixed * 100) / 100 : 0;
  }

  function syncRpFixedAmountField() {
    var amountInput = $('chatRpAmount');
    if (!amountInput) return;
    var fixed = groupRpFixedAmount();
    if (fixed > 0) {
      amountInput.value = fixed.toFixed(2);
      amountInput.readOnly = true;
      amountInput.setAttribute('aria-readonly', 'true');
    } else {
      amountInput.readOnly = false;
      amountInput.removeAttribute('aria-readonly');
    }
  }

  function ensureRpMineDigits() {
    var box = $('chatRpMineDigits');
    var input = $('chatRpMineDigit');
    if (!box || box._built) return;
    box._built = true;
    var cur = input ? (parseInt(input.value, 10) || 0) : 0;
    if (cur < 0 || cur > 9) cur = 0;
    if (input) input.value = String(cur);
    var html = '';
    for (var i = 0; i <= 9; i++) {
      html += '<button type="button" class="chat-rp-mine-digit-btn' + (i === cur ? ' active' : '') +
        '" data-digit="' + i + '">' + i + '</button>';
    }
    box.innerHTML = html;
    box.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.chat-rp-mine-digit-btn');
      if (!btn) return;
      var d = parseInt(btn.getAttribute('data-digit'), 10);
      if (isNaN(d) || d < 0 || d > 9) return;
      if (input) input.value = String(d);
      box.querySelectorAll('.chat-rp-mine-digit-btn').forEach(function (b) {
        b.classList.toggle('active', b === btn);
      });
      updateRpPreview();
    });
  }

  function formatMineRate(rate) {
    var n = Number(rate);
    if (!isFinite(n) || n <= 0) n = 1;
    var s = n.toFixed(4).replace(/\.?0+$/, '');
    return s || '1';
  }

  function mineCompensateRates() {
    var defaults = { 5: 1.5, 7: 1.2, 9: 1.0 };
    var src = (global.CONFIG && CONFIG.MINE_COMPENSATE_RATES) || {};
    function pick(k) {
      var v = src[k] != null ? src[k] : src[String(k)];
      var n = Number(v);
      return (isFinite(n) && n > 0) ? n : defaults[k];
    }
    return { 5: pick(5), 7: pick(7), 9: pick(9) };
  }

  function rpCountTabLabel(count, rate) {
    return String(count) + '包/' + formatMineRate(rate) + '倍';
  }

  function ensureRpCountTabs() {
    var tabs = $('chatRpCountTabs');
    if (!tabs) return;
    var rates = mineCompensateRates();
    var counts = [5, 7, 9];
    var countInput = $('chatRpCount');
    var cur = countInput ? (parseInt(countInput.value, 10) || 5) : 5;
    if (counts.indexOf(cur) < 0) cur = 5;
    var needRebuild = !tabs._rpRateBuilt;
    if (!needRebuild) {
      var btns = tabs.querySelectorAll('.chat-rp-count-btn');
      if (btns.length !== counts.length) needRebuild = true;
      else {
        for (var i = 0; i < btns.length; i++) {
          var c = parseInt(btns[i].getAttribute('data-count'), 10) || 0;
          var expect = rpCountTabLabel(c, rates[c]);
          if (String(btns[i].textContent || '').trim() !== expect) {
            needRebuild = true;
            break;
          }
        }
      }
    }
    if (!needRebuild) return;
    tabs._rpRateBuilt = true;
    tabs.innerHTML = counts.map(function (c) {
      return '<button type="button" class="chat-rp-count-btn' + (c === cur ? ' active' : '')
        + '" data-count="' + c + '">' + escapeHtml(rpCountTabLabel(c, rates[c])) + '</button>';
    }).join('');
  }

  function syncRpCountField() {
    var type = getRpPacketType();
    var isGroup = state.room && state.room.type === 2;
    var tabs = $('chatRpCountTabs');
    var inputWrap = $('chatRpCountInputWrap');
    var countInput = $('chatRpCount');
    var hint = $('chatRpCountHint');
    var mineMode = isGroup && type === 3;
    if (tabs) {
      tabs.hidden = !mineMode;
      tabs.style.display = mineMode ? '' : 'none';
    }
    if (inputWrap) {
      inputWrap.style.display = mineMode ? 'none' : '';
      if (!isGroup) inputWrap.style.display = 'none';
    }
    if (mineMode && countInput) {
      ensureRpCountTabs();
      var rates = mineCompensateRates();
      var cur = parseInt(countInput.value, 10) || 5;
      if ([5, 7, 9].indexOf(cur) < 0) cur = 5;
      countInput.value = String(cur);
      if (tabs) {
        tabs.querySelectorAll('.chat-rp-count-btn').forEach(function (b) {
          b.classList.toggle('active', (parseInt(b.getAttribute('data-count'), 10) || 0) === cur);
        });
      }
      if (hint) {
        hint.textContent = '中雷赔付：'
          + rpCountTabLabel(5, rates[5]) + ' · '
          + rpCountTabLabel(7, rates[7]) + ' · '
          + rpCountTabLabel(9, rates[9]);
      }
    } else if (hint) {
      if (!isGroup) {
        hint.textContent = '私聊固定 1 个';
      } else if (type === 1 || type === 4) {
        hint.textContent = '普通/随机红包个数按群与全局配置';
        if (countInput) {
          countInput.min = '1';
          countInput.max = '100';
        }
      } else {
        hint.textContent = '群聊 5～10 个 · 私聊固定 1 个';
        if (countInput) {
          countInput.min = '5';
          countInput.max = '10';
        }
      }
    }
  }

  function syncRpMineField() {
    var wrap = $('chatRpMineWrap');
    if (!wrap) return;
    var type = getRpPacketType();
    if (type === 3) {
      ensureRpMineDigits();
      wrap.hidden = false;
      wrap.style.display = '';
    } else {
      wrap.hidden = true;
      wrap.style.display = 'none';
    }
    syncRpCountField();
  }

  function updateRpPreview() {
    var blessEl = $('chatRpPreviewBless');
    var subEl = $('chatRpPreviewSub');
    var blessInput = $('chatRpBlessing');
    var amountInput = $('chatRpAmount');
    var countInput = $('chatRpCount');
    var mineInput = $('chatRpMineDigit');
    if (blessEl && blessInput) {
      var t = String(blessInput.value || '').trim();
      blessEl.textContent = t || chatT('chat_rp_blessing_default');
    }
    if (subEl) {
      var type = getRpPacketType();
      var count = countInput ? (parseInt(countInput.value, 10) || 1) : 1;
      var amount = amountInput ? (parseFloat(amountInput.value) || 0) : 0;
      var mineDigit = mineInput ? (parseInt(mineInput.value, 10) || 0) : 0;
      var typeLabel = type === 1 ? '普通' : (type === 3 ? '埋雷' : (type === 4 ? '随机' : '拼手气'));
      var parts = [typeLabel];
      if (type === 3) {
        parts.push('雷' + mineDigit);
        var rates = mineCompensateRates();
        if (rates[count]) parts.push(rpCountTabLabel(count, rates[count]));
        else if (count > 0) parts.push(count + '个');
      } else if (count > 0) {
        parts.push(count + '个');
      }
      if (amount > 0) parts.push('￥' + amount.toFixed(2));
      subEl.textContent = parts.join(' · ');
    }
    syncRpMineField();
    var balEl = $('chatRpBalance');
    if (balEl && state.money != null && !isNaN(state.money)) {
      balEl.textContent = '￥' + Number(state.money).toFixed(2);
    }
  }

  function openRpSendPage() {
    if (!state.room) {
      if (typeof showFanshubToast === 'function') showFanshubToast('请先打开会话', 'info');
      return;
    }
    if (state.room.type === 2 && (groupPolicy().can_send_rp === false || groupPolicy().rp_robot_only === true || (typeof canSendCapability === 'function' && !canSendCapability('rp')))) {
      var tip = groupPolicy().rp_robot_only
        ? (chatT('chat_rp_robot_only') || '本群仅自动机器人可发红包')
        : (chatT('chat_rp_admin_only') || '红宝模式下仅管理员可发红包');
      if (typeof showFanshubToast === 'function') showFanshubToast(tip, 'error');
      return;
    }
    var pane = $('chatRpSendPane');
    if (!pane) return;
    var isGroup = state.room.type === 2;
    var countWrap = $('chatRpCountWrap');
    var countInput = $('chatRpCount');
    if (countWrap) countWrap.classList.toggle('is-private', !isGroup);
    if (countInput) {
      if (!isGroup) {
        countInput.value = '1';
        countInput.min = '1';
        countInput.max = '1';
      } else {
        countInput.min = '5';
        countInput.max = '10';
        if (!countInput.value || parseInt(countInput.value, 10) < 5) {
          countInput.value = '5';
        }
      }
    }
    syncRpTypeTabs();
    syncRpFixedAmountField();
    // 私聊：隐藏类型 Tab，固定普通 1 个、无手续费玩法
    var isPrivate = state.room.type === 1;
    var typeTabs = $('chatRpTypeTabs');
    if (isPrivate) {
      if (typeTabs) {
        typeTabs.style.display = 'none';
        typeTabs.hidden = true;
        typeTabs.querySelectorAll('.chat-rp-type-btn').forEach(function (b) {
          b.classList.toggle('active', (parseInt(b.getAttribute('data-type'), 10) || 0) === 1);
        });
      }
      if (countInput) {
        countInput.value = '1';
        countInput.min = '1';
        countInput.max = '1';
      }
      var mineWrap = $('chatRpMineWrap');
      if (mineWrap) {
        mineWrap.hidden = true;
        mineWrap.style.display = 'none';
      }
      var countTabs = $('chatRpCountTabs');
      if (countTabs) {
        countTabs.hidden = true;
        countTabs.style.display = 'none';
      }
      var hint = $('chatRpCountHint');
      if (hint) hint.textContent = '私聊红包仅对方可领 · 无手续费';
    } else {
      // 群聊：切到允许类型后刷新埋雷数字/个数区
      try { syncRpMineField(); } catch (eMine) {}
    }
    var amountInput = $('chatRpAmount');
    if (amountInput && !amountInput.value && !(groupRpFixedAmount() > 0)) amountInput.value = '';
    var blessInput = $('chatRpBlessing');
    if (blessInput && !String(blessInput.value || '').trim()) {
      blessInput.value = '恭喜发财';
    }
    updateRpPreview();
    pane.classList.add('open');
    pane.setAttribute('aria-hidden', 'false');
    if (amountInput && !amountInput.readOnly) setTimeout(function () { amountInput.focus(); }, 80);
  }

  function closeRpSendPage() {
    var pane = $('chatRpSendPane');
    if (!pane) return;
    pane.classList.remove('open');
    pane.setAttribute('aria-hidden', 'true');
    var btn = $('chatRpSubmitBtn');
    if (btn) {
      btn.disabled = false;
      btn.textContent = chatT('chat_rp_submit');
    }
  }

  async function submitRedPacket() {
    if (!state.room) {
      if (typeof showFanshubToast === 'function') showFanshubToast('请先打开会话', 'info');
      return;
    }
    var amountInput = $('chatRpAmount');
    var countInput = $('chatRpCount');
    var blessInput = $('chatRpBlessing');
    var mineInput = $('chatRpMineDigit');
    var submitBtn = $('chatRpSubmitBtn');
    var totalAmount = parseFloat(amountInput && amountInput.value) || 0;
    var totalCount = state.room.type === 2
      ? (parseInt(countInput && countInput.value, 10) || 0)
      : 1;
    var packetType = getRpPacketType();
    var bless = String(blessInput && blessInput.value || '').trim() || '恭喜发财';
    var mineDigit = mineInput ? parseInt(mineInput.value, 10) : 0;
    if (isNaN(mineDigit) || mineDigit < 0 || mineDigit > 9) mineDigit = 0;
    if (totalAmount <= 0) {
      if (typeof showFanshubToast === 'function') showFanshubToast('请输入红包金额', 'error');
      return;
    }
    if (totalAmount < 10) {
      if (typeof showFanshubToast === 'function') showFanshubToast('金额最低 10 元', 'error');
      return;
    }
    if (totalCount <= 0) {
      if (typeof showFanshubToast === 'function') showFanshubToast('请输入红包个数', 'error');
      return;
    }
    if (state.room.type === 2 && (totalCount < 5 || totalCount > 10)) {
      if (typeof showFanshubToast === 'function') showFanshubToast('个数须为 5～10', 'error');
      return;
    }
    if (packetType === 3 && state.room.type === 2 && [5, 7, 9].indexOf(totalCount) < 0) {
      if (typeof showFanshubToast === 'function') showFanshubToast('扫雷红包个数仅可选 5 / 7 / 9', 'error');
      return;
    }
    if (packetType === 3 && (mineDigit < 0 || mineDigit > 9 || isNaN(mineDigit))) {
      if (typeof showFanshubToast === 'function') showFanshubToast('请选择埋雷数字 0～9', 'error');
      return;
    }
    if (state.money != null && totalAmount > state.money + 0.0001) {
      if (typeof showFanshubToast === 'function') showFanshubToast(chatT('alert_insufficient_balance') || chatT('srv_insufficient_hongbao') || '红宝不足', 'error');
      return;
    }
    var data = {
      scope_type: state.room.type === 2 ? 2 : 1,
      packet_type: packetType,
      total_amount: totalAmount,
      total_count: totalCount,
      blessing: bless
    };
    if (packetType === 3) {
      data.mine_digit = mineDigit;
    }
    if (state.room.type === 2) data.group_id = state.room.id | 0;
    else {
      data.to_user_id = state.room.peer | 0;
      // 私聊：后端也会强制；前端一并固定
      data.packet_type = 1;
      data.total_count = 1;
      delete data.mine_digit;
    }
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = chatT('chat_rp_sending');
    }
    try {
      var packet = await send('redpacket.send', data);
      if (packet.data && packet.data.balance != null) {
        state.money = parseFloat(packet.data.balance);
        updateMoneyLabel();
      }
      var msg = packet.data && packet.data.message;
      if (msg) {
        appendMessage(msg);
        upsertListFromMessage(msg);
      }
      closeRpSendPage();
      if (typeof showFanshubToast === 'function') showFanshubToast('红包已发送', 'success');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '发红包失败', 'error');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = chatT('chat_rp_submit');
      }
    }
  }

  function sendRedPacket() {
    openRpSendPage();
  }

  function updateTransferPreview() {
    var amountInput = $('chatTransferAmount');
    var amtEl = $('chatTransferPreviewAmt');
    var balEl = $('chatTransferBalance');
    var total = parseFloat(amountInput && amountInput.value) || 0;
    if (amtEl) amtEl.textContent = '￥' + total.toFixed(2);
    if (balEl && state.money != null && !isNaN(state.money)) {
      balEl.textContent = '￥' + Number(state.money).toFixed(2);
    }
  }

  function openTransferSendPage() {
    if (!state.room || state.room.type !== 1) {
      if (typeof showFanshubToast === 'function') showFanshubToast('仅私聊可转账', 'info');
      return;
    }
    var pane = $('chatTransferSendPane');
    if (!pane) return;
    var amountInput = $('chatTransferAmount');
    if (amountInput && !amountInput.value) amountInput.value = '';
    var remarkInput = $('chatTransferRemark');
    if (remarkInput && !String(remarkInput.value || '').trim()) remarkInput.value = '';
    updateTransferPreview();
    pane.classList.add('open');
    pane.setAttribute('aria-hidden', 'false');
    if (amountInput) setTimeout(function () { amountInput.focus(); }, 80);
  }

  function closeTransferSendPage() {
    var pane = $('chatTransferSendPane');
    if (!pane) return;
    pane.classList.remove('open');
    pane.setAttribute('aria-hidden', 'true');
    var btn = $('chatTransferSubmitBtn');
    if (btn) {
      btn.disabled = false;
      btn.textContent = '确认转账';
    }
  }

  async function submitTransfer() {
    if (!state.room || state.room.type !== 1) {
      if (typeof showFanshubToast === 'function') showFanshubToast('仅私聊可转账', 'info');
      return;
    }
    var amountInput = $('chatTransferAmount');
    var remarkInput = $('chatTransferRemark');
    var submitBtn = $('chatTransferSubmitBtn');
    var amount = parseFloat(amountInput && amountInput.value) || 0;
    var remark = String(remarkInput && remarkInput.value || '').trim();
    if (amount < 0.01) {
      if (typeof showFanshubToast === 'function') showFanshubToast('请输入转账金额', 'error');
      return;
    }
    if (state.money != null && amount > state.money + 0.0001) {
      if (typeof showFanshubToast === 'function') showFanshubToast(chatT('alert_insufficient_balance') || '红宝不足', 'error');
      return;
    }
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = '转账中…';
    }
    try {
      var packet = await send('transfer.send', {
        to_user_id: state.room.peer | 0,
        amount: amount,
        remark: remark
      });
      if (packet.data && packet.data.balance != null) {
        state.money = parseFloat(packet.data.balance);
        updateMoneyLabel();
      }
      var msg = packet.data && packet.data.message;
      if (msg) {
        appendMessage(msg);
        upsertListFromMessage(msg);
      }
      closeTransferSendPage();
      if (typeof showFanshubToast === 'function') showFanshubToast('转账成功', 'success');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '转账失败', 'error');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = '确认转账';
      }
    }
  }

/* === 04-net.js === */
/* js/chat/04-net.js — ws, bindUi, exports */

  function startPing() {
    stopPing();
    state.pingTimer = setInterval(function () {
      if (state.ws && state.ws.readyState === 1) {
        try { state.ws.send(JSON.stringify({ type: 'ping', data: {}, req_id: 'ping' })); } catch (e) {}
      }
    }, 25000);
  }

  function stopPing() {
    if (state.pingTimer) {
      clearInterval(state.pingTimer);
      state.pingTimer = null;
    }
  }

  function scheduleReconnect() {
    if (state.reconnectTimer) return;
    if (!token()) return;
    state.reconnectAttempt = (state.reconnectAttempt | 0) + 1;
    var n = Math.min(state.reconnectAttempt | 0, 6);
    // 2s → 4s → 8s → 16s → 30s…，避免断线风暴
    var delay = Math.min(30000, 2000 * Math.pow(2, Math.max(0, n - 1)));
    state.reconnectTimer = setTimeout(function () {
      state.reconnectTimer = null;
      connect(true);
    }, delay);
  }

  function handlePacket(packet) {
    if (resolvePending(packet)) {
      if (packet.type === 'auth.ok') return;
    }
    switch (packet.type) {
      case 'hello':
        break;
      case 'auth.ok':
        state.connected = true;
        state.reconnectAttempt = 0;
        state.userId = (packet.data && packet.data.user_id) | 0;
        try {
          if (state.userId > 0) localStorage.setItem('fans_hub_chat_cache_uid', String(state.userId));
        } catch (eUid) {}
        state.isImAdmin = !!(packet.data && packet.data.is_im_admin);
        state.canCreateGroup = !!(packet.data && packet.data.can_create_group);
        var bal = packet.data && packet.data.user && (packet.data.user.balance != null ? packet.data.user.balance : packet.data.user.money);
        state.money = bal != null ? parseFloat(bal) : state.money;
        setConnStatus(chatT('chat_conn_ok'), 'ok');
        updateMoneyLabel();
        syncBalanceFromAccount();
        hydrateListFromCache();
        var newPrivateBtn = $('chatNewPrivateBtn');
        if (newPrivateBtn) newPrivateBtn.style.display = state.isImAdmin ? '' : 'none';
        var newGroupBtn = $('chatNewGroupBtn');
        if (newGroupBtn) newGroupBtn.style.display = state.canCreateGroup ? '' : 'none';
        var addFriendBtn = $('chatAddFriendBtn');
        // 「添加好友」对所有人常显（含 IM 管理）；勿按 isImAdmin 隐藏，否则 auth.ok/重连后会反复消失
        if (addFriendBtn) addFriendBtn.style.display = '';
        refreshList(true).catch(function () {});
        break;
      case 'private.message':
      case 'group.message':
        if (packet.data && packet.data.message) onIncomingMessage(packet.data.message);
        break;
      case 'message.recalled':
        if (packet.data && packet.data.message) applyRecalledMessage(packet.data.message);
        break;
      case 'admin.notify':
        // 仅后台托管账号使用；H5 普通用户忽略
        break;
      case 'group.created':
      case 'group.invited':
        refreshList(true).catch(function () {});
        break;
      case 'group.kicked':
        if (state.room && state.room.type === 2 && String(state.room.id) === String((packet.data && packet.data.group_id) || '')) {
          if (typeof showFanshubToast === 'function') showFanshubToast('你已被移出群组', 'error');
          closeRoom();
        }
        refreshList(true).catch(function () {});
        break;
      case 'group.mute_all_changed':
        if (state.room && state.room.type === 2 && String(state.room.id) === String((packet.data && packet.data.group_id) || '')) {
          refreshGroupMeta().catch(function () {});
        }
        break;
      case 'group.forbid_changed':
        if (state.room && state.room.type === 2 && String(state.room.id) === String((packet.data && packet.data.group_id) || '')) {
          if (packet.data) {
            state.groupMeta = mergeGroupMeta(Object.assign({}, state.groupMeta || {}, packet.data));
            applySpeakState(state.groupMeta);
            updateComposerPolicy();
            renderGroupSettings();
          } else {
            refreshGroupMeta().catch(function () {});
          }
        }
        break;
      case 'group.updated':
        if (packet.data && packet.data.group) {
          var ug = packet.data.group;
          var ugid = (packet.data.group_id | 0) || (ug.id | 0);
          state.list.forEach(function (it) {
            if ((it.conversation_type | 0) === 2 && ((it.group_id | 0) === ugid || String(it.conversation_id) === String(ugid))) {
              if (ug.name != null) it.title = ug.name;
              if (ug.avatar != null) it.avatar = ug.avatar || '';
            }
          });
          renderList();
          if (state.room && state.room.type === 2 && String(state.room.id) === String(ugid)) {
            state.groupMeta = state.groupMeta || {};
            var prevNotice = String(((state.groupMeta.group || {}).notice) || '');
            state.groupMeta.group = ug;
            if (packet.data.policy) state.groupMeta.policy = packet.data.policy;
            if (String(ug.notice || '') !== prevNotice) {
              delete state.noticeDismissed[String(ugid)];
            }
            applyGroupRoomHeader(state.groupMeta);
            renderGroupSettings();
            updateComposerPolicy();
            renderMessages();
          }
        }
        break;
      case 'group.members_changed':
        if (state.room && state.room.type === 2 && String(state.room.id) === String((packet.data && packet.data.group_id) || '')) {
          if ($('chatGroupMembersPane') && $('chatGroupMembersPane').classList.contains('open')) {
            loadMembers(state.memberKeyword).catch(function () {});
          } else {
            refreshGroupMeta().catch(function () {});
          }
        }
        break;
      case 'redpacket.update':
        (function () {
          var d = packet.data || {};
          var pid = d.packet_id | 0;
          if (!pid) return;
          var byUid = d.by_user_id | 0;
          if (byUid && byUid === (state.userId | 0)) {
            markRpCover(pid, { grabbed: true, faded: true });
            try { refreshRpOrMessages(pid, true); } catch (e1) {}
          }
          var grab = d.grab || {};
          var st = grab.status | 0;
          if (st === 3 || st === 4) {
            markRpCover(pid, { expired: true, faded: true });
            try { refreshRpOrMessages(pid, true); } catch (e2) {}
          }
          // 开奖后刷新红包卡片：官方雷号 = 波场哈希末位
          if (d.tron_revealed && d.tron && state.messages && state.messages.length) {
            var tron = d.tron;
            var changed = false;
            state.messages.forEach(function (m) {
              if (!m || (m.msg_type | 0) !== 2) return;
              var ex = m.extra || {};
              if ((ex.packet_id | 0) !== pid) return;
              ex.tron_block_num = tron.tron_block_num || tron.targetBlockNum || ex.tron_block_num;
              if (tron.revealed) {
                ex.mine_pending = false;
                if (tron.mine_digit != null) ex.mine_digit = tron.mine_digit | 0;
                else if (tron.lucky_digit != null) ex.mine_digit = tron.lucky_digit | 0;
                if (tron.tron_lucky) ex.tron_lucky = tron.tron_lucky;
                if (tron.tron_block_id) ex.tron_block_id = tron.tron_block_id;
              }
              m.extra = ex;
              changed = true;
            });
            if (changed) {
              try { refreshRpOrMessages(pid, true); } catch (e3) {}
            }
          }
          // 若正打开该红包详情，自动重载（防抖，避免抢完+结算连续 update 打两次）
          var pane = $('chatRpDetailPane');
          var grabBtn = $('chatRpDetailGrabBtn');
          if (pane && pane.classList.contains('open') && grabBtn) {
            var cur = parseInt(grabBtn.getAttribute('data-packet'), 10) || 0;
            if (cur === pid && typeof openRedPacketDetail === 'function') {
              if (state._rpDetailReloadTimer) clearTimeout(state._rpDetailReloadTimer);
              state._rpDetailReloadTimer = setTimeout(function () {
                state._rpDetailReloadTimer = null;
                openRedPacketDetail(pid);
              }, 180);
            }
          }
          if (d.tron_revealed && typeof showFanshubToast === 'function') {
            var t = d.tron || {};
            var tip = '波场已开奖';
            if (t.tron_block_num) tip += ' · 区块#' + t.tron_block_num;
            if ((t.packet_type | 0) === 3 && t.mine_digit != null) tip += ' · 雷' + (t.mine_digit | 0);
            showFanshubToast(tip, 'success');
          }
        })();
        break;
      case 'error':
        if (packet.data && packet.data.message === 'auth_failed') {
          setConnStatus(chatT('chat_conn_auth_fail'), 'err');
        }
        break;
      default:
        break;
    }
  }

  function connect(force) {
    var t = token();
    if (!t) {
      setConnStatus(chatT('chat_conn_not_login'), 'err');
      return;
    }
    if (state.connecting) return;
    if (state.ws && (state.ws.readyState === 0 || state.ws.readyState === 1) && !force) return;

    if (state.ws) {
      try { state.ws.onclose = null; state.ws.close(); } catch (e) {}
      state.ws = null;
    }

    state.connecting = true;
    state.connected = false;
    setConnStatus(chatT('chat_conn_connecting'), '');
    var url = connectWsUrl();
    var ws;
    try {
      ws = new WebSocket(url);
    } catch (e) {
      state.connecting = false;
      setConnStatus(chatT('chat_conn_unreachable'), 'err');
      scheduleReconnect();
      return;
    }
    state.ws = ws;
    ws.onopen = function () {
      state.connecting = false;
      startPing();
      // 握手鉴权通常已在途；未成功则补发 auth（兼容旧 IM / 无 query 代理）
      if (state.connected) return;
      setConnStatus(chatT('chat_conn_authing'), '');
      try {
        ws.send(JSON.stringify({
          type: 'auth',
          data: { token: t, device_fp: (localStorage.getItem('fans_hub_device_fp') || '') },
          req_id: 'auth'
        }));
      } catch (e) {}
    };
    ws.onmessage = function (ev) {
      var packet;
      try { packet = JSON.parse(ev.data); } catch (e) { return; }
      handlePacket(packet);
    };
    ws.onclose = function () {
      state.connecting = false;
      state.connected = false;
      stopPing();
      setConnStatus(chatT('chat_conn_reconnecting'), 'err');
      scheduleReconnect();
    };
    ws.onerror = function () {
      setConnStatus(chatT('chat_conn_error'), 'err');
    };
  }

  /** 任意页面保活：已连或连接中则跳过 */
  function ensureConnected() {
    if (!token()) return;
    if (state.connected || state.connecting) return;
    if (state.ws && (state.ws.readyState === 0 || state.ws.readyState === 1)) return;
    connect(false);
  }

  function disconnect() {
    if (state.reconnectTimer) {
      clearTimeout(state.reconnectTimer);
      state.reconnectTimer = null;
    }
    stopPing();
    if (state.ws) {
      try { state.ws.onclose = null; state.ws.close(); } catch (e) {}
      state.ws = null;
    }
    state.connected = false;
    state.connecting = false;
    setConnStatus(chatT('chat_conn_off'), '');
  }

  function bindUi() {
    ensureChatOverlays();
    buildEmojiPanel();
    var search = $('chatConvSearch');
    if (search && !search._bound) {
      search._bound = true;
      search.addEventListener('input', function () {
        clearTimeout(state.listSearchTimer);
        state.listSearchTimer = setTimeout(function () {
          state.listKeyword = String(search.value || '').trim();
          renderList();
        }, 180);
      });
    }
    var list = $('chatConvList');
    if (list && !list._bound) {
      list._bound = true;
      var longPressTimer = null;
      var longPressBtn = null;
      var skipNextClick = false;
      var SWIPE_DEL_W = 76;
      var swipeState = null; // { row, front, startX, startY, baseX, moved, horizontal }

      function clearLongPress() {
        if (longPressTimer) {
          clearTimeout(longPressTimer);
          longPressTimer = null;
        }
        longPressBtn = null;
      }

      function closeAllSwipeRows(except) {
        var rows = list.querySelectorAll('.chat-conv-swipe.open, .chat-conv-swipe.is-dragging');
        for (var i = 0; i < rows.length; i++) {
          var row = rows[i];
          if (except && row === except) continue;
          row.classList.remove('open', 'is-dragging');
          var front = row.querySelector('.chat-conv-item');
          if (front) front.style.transform = '';
        }
      }

      function setSwipeOffset(row, front, x, withTransition) {
        if (!row || !front) return;
        var nx = Math.max(-SWIPE_DEL_W, Math.min(0, x));
        if (!withTransition) row.classList.add('is-dragging');
        else row.classList.remove('is-dragging');
        front.style.transform = nx ? ('translateX(' + nx + 'px)') : '';
        if (nx <= -SWIPE_DEL_W / 2) row.classList.add('open');
        else row.classList.remove('open');
      }

      function snapSwipe(row, front, open) {
        if (!row || !front) return;
        row.classList.remove('is-dragging');
        if (open) {
          row.classList.add('open');
          front.style.transform = 'translateX(-' + SWIPE_DEL_W + 'px)';
        } else {
          row.classList.remove('open');
          front.style.transform = '';
        }
      }

      // 手指按下即预拉历史，等 click 打开时多半已进本地缓存
      list.addEventListener('pointerdown', function (ev) {
        if (ev.target.closest('.chat-conv-swipe-del')) return;
        var btn = ev.target.closest('.chat-conv-item');
        if (!btn) return;
        prefetchHistory({
          type: parseInt(btn.getAttribute('data-type'), 10) || 1,
          id: btn.getAttribute('data-id'),
          peer: parseInt(btn.getAttribute('data-peer'), 10) || 0
        });
        clearLongPress();
        longPressBtn = btn;
        longPressTimer = setTimeout(function () {
          longPressTimer = null;
          if (!longPressBtn || (swipeState && swipeState.horizontal)) return;
          skipNextClick = true;
          closeAllSwipeRows();
          openConvActionSheet(findConvItemFromBtn(longPressBtn));
          try { if (navigator.vibrate) navigator.vibrate(12); } catch (eV) {}
        }, 480);

        var row = btn.closest('.chat-conv-swipe');
        var ctype = parseInt(btn.getAttribute('data-type'), 10) || 1;
        // 私聊 / 群聊均可左滑删除
        if (!row || (ctype !== 1 && ctype !== 2)) {
          swipeState = null;
          return;
        }
        var baseX = 0;
        if (row.classList.contains('open')) baseX = -SWIPE_DEL_W;
        var t = btn.style.transform || '';
        var m = t.match(/translateX\((-?\d+(?:\.\d+)?)px\)/);
        if (m) baseX = parseFloat(m[1]) || baseX;
        swipeState = {
          row: row,
          front: btn,
          startX: ev.clientX,
          startY: ev.clientY,
          baseX: baseX,
          moved: false,
          horizontal: false,
          pointerId: ev.pointerId
        };
        try { row.setPointerCapture(ev.pointerId); } catch (eCap) {}
      });

      list.addEventListener('pointermove', function (ev) {
        if (longPressTimer && longPressBtn) {
          if (Math.abs(ev.movementX) + Math.abs(ev.movementY) > 8) clearLongPress();
        }
        if (!swipeState || swipeState.pointerId !== ev.pointerId) return;
        var dx = ev.clientX - swipeState.startX;
        var dy = ev.clientY - swipeState.startY;
        if (!swipeState.horizontal) {
          if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
          if (Math.abs(dx) <= Math.abs(dy)) {
            // 纵向滚动：放弃左滑
            swipeState = null;
            return;
          }
          swipeState.horizontal = true;
          clearLongPress();
          closeAllSwipeRows(swipeState.row);
        }
        swipeState.moved = true;
        setSwipeOffset(swipeState.row, swipeState.front, swipeState.baseX + dx, false);
        try { ev.preventDefault(); } catch (ePrev) {}
      }, { passive: false });

      function endSwipe(ev) {
        if (!swipeState || (ev && swipeState.pointerId !== ev.pointerId)) {
          clearLongPress();
          return;
        }
        var st = swipeState;
        swipeState = null;
        clearLongPress();
        if (!st.horizontal) return;
        var t = st.front.style.transform || '';
        var m = t.match(/translateX\((-?\d+(?:\.\d+)?)px\)/);
        var cur = m ? parseFloat(m[1]) : 0;
        var open = cur <= -SWIPE_DEL_W * 0.4;
        snapSwipe(st.row, st.front, open);
        if (st.moved) skipNextClick = true;
      }

      list.addEventListener('pointerup', endSwipe);
      list.addEventListener('pointercancel', endSwipe);

      list.addEventListener('contextmenu', function (ev) {
        var btn = ev.target.closest('.chat-conv-item');
        if (!btn) return;
        ev.preventDefault();
        clearLongPress();
        skipNextClick = true;
        closeAllSwipeRows();
        openConvActionSheet(findConvItemFromBtn(btn));
      });
      list.addEventListener('click', function (ev) {
        var delBtn = ev.target.closest('.chat-conv-swipe-del');
        if (delBtn) {
          ev.preventDefault();
          ev.stopPropagation();
          var row = delBtn.closest('.chat-conv-swipe');
          var itemBtn = row && row.querySelector('.chat-conv-item');
          closeAllSwipeRows();
          if (itemBtn) deletePrivateConvFromList(findConvItemFromBtn(itemBtn));
          return;
        }
        if (skipNextClick) {
          skipNextClick = false;
          ev.preventDefault();
          ev.stopPropagation();
          return;
        }
        var btn = ev.target.closest('.chat-conv-item');
        if (!btn) {
          closeAllSwipeRows();
          return;
        }
        var swipeRow = btn.closest('.chat-conv-swipe');
        if (swipeRow && swipeRow.classList.contains('open')) {
          // 已左滑展开时，点会话先收回，不进房间
          closeAllSwipeRows();
          ev.preventDefault();
          return;
        }
        // 点其他行时收起已展开的
        if (!swipeRow || !swipeRow.classList.contains('open')) closeAllSwipeRows();
        openRoom({
          type: parseInt(btn.getAttribute('data-type'), 10) || 1,
          id: btn.getAttribute('data-id'),
          peer: parseInt(btn.getAttribute('data-peer'), 10) || 0,
          title: btn.getAttribute('data-title') || ''
        });
      });
    }
    var convSheetMask = $('chatConvActionMask');
    var convSheetCancel = $('chatConvActionCancel');
    var convActPin = $('chatConvActPin');
    var convActUnpin = $('chatConvActUnpin');
    var convActDelete = $('chatConvActDelete');
    if (convSheetMask && !convSheetMask._bound) {
      convSheetMask._bound = true;
      convSheetMask.onclick = function () { closeConvActionSheet(); };
    }
    if (convSheetCancel && !convSheetCancel._bound) {
      convSheetCancel._bound = true;
      convSheetCancel.onclick = function () { closeConvActionSheet(); };
    }
    if (convActPin && !convActPin._bound) {
      convActPin._bound = true;
      convActPin.onclick = function () { toggleConvPin(true); };
    }
    if (convActUnpin && !convActUnpin._bound) {
      convActUnpin._bound = true;
      convActUnpin.onclick = function () { toggleConvPin(false); };
    }
    if (convActDelete && !convActDelete._bound) {
      convActDelete._bound = true;
      convActDelete.onclick = function () { deletePrivateConvFromList(); };
    }
    var back = $('chatBackBtn');
    if (back && !back._bound) {
      back._bound = true;
      back.onclick = closeRoom;
    }
    var moreBtn = $('chatGroupMoreBtn');
    if (moreBtn && !moreBtn._bound) {
      moreBtn._bound = true;
      moreBtn.onclick = function () { openRoomMore(); };
    }
    var noticeClose = $('chatNoticePinClose');
    if (noticeClose && !noticeClose._bound) {
      noticeClose._bound = true;
      noticeClose.onclick = function (ev) {
        ev.stopPropagation();
        dismissNoticePin();
      };
    }
    var noticePin = $('chatNoticePin');
    if (noticePin && !noticePin._bound) {
      noticePin._bound = true;
      noticePin.addEventListener('click', function (ev) {
        if (ev.target && ev.target.id === 'chatNoticePinClose') return;
        noticePin.classList.toggle('is-expanded');
      });
    }
    var groupSaveBtn = $('chatGroupSaveBtn');
    if (groupSaveBtn && !groupSaveBtn._bound) {
      groupSaveBtn._bound = true;
      groupSaveBtn.onclick = function () { saveGroupProfile(); };
    }
    var groupAvBtn = $('chatGroupAvatarBtn');
    var groupAvInput = $('chatGroupAvatarInput');
    if (groupAvBtn && groupAvInput && !groupAvBtn._bound) {
      groupAvBtn._bound = true;
      groupAvBtn.onclick = function () {
        var meta = state.groupMeta || {};
        if ((meta.my_role | 0) < 2) return;
        groupAvInput.click();
      };
      groupAvInput.onchange = function () {
        var file = groupAvInput.files && groupAvInput.files[0];
        groupAvInput.value = '';
        if (file) uploadGroupAvatar(file);
      };
    }
    var settingsBack = $('chatGroupSettingsBack');
    if (settingsBack && !settingsBack._bound) {
      settingsBack._bound = true;
      settingsBack.onclick = function () { closeSubPane('chatGroupSettingsPane'); };
    }
    var viewMembersBtn = $('chatViewMembersBtn');
    if (viewMembersBtn && !viewMembersBtn._bound) {
      viewMembersBtn._bound = true;
      viewMembersBtn.onclick = function () { openGroupMembers(); };
    }
    var muteAllSwitch = $('chatMuteAllSwitch');
    if (muteAllSwitch && !muteAllSwitch._bound) {
      muteAllSwitch._bound = true;
      muteAllSwitch.addEventListener('change', function () {
        toggleMuteAll(!!muteAllSwitch.checked);
      });
    }
    var forbidList = $('chatForbidModesList');
    if (forbidList && !forbidList._bound) {
      forbidList._bound = true;
      forbidList.addEventListener('change', function (ev) {
        if (!ev.target || !ev.target.getAttribute('data-forbid')) return;
        if (typeof saveGroupForbidModes === 'function') saveGroupForbidModes();
      });
    }
    var leaveBtn = $('chatGroupLeaveBtn');
    if (leaveBtn && !leaveBtn._bound) {
      leaveBtn._bound = true;
      leaveBtn.onclick = function () {
        if (typeof leaveCurrentGroup === 'function') leaveCurrentGroup();
      };
    }
    var membersBack = $('chatGroupMembersBack');
    if (membersBack && !membersBack._bound) {
      membersBack._bound = true;
      membersBack.onclick = function () { closeSubPane('chatGroupMembersPane'); };
    }
    var addMemberBtn = $('chatAddMemberBtn');
    if (addMemberBtn && !addMemberBtn._bound) {
      addMemberBtn._bound = true;
      addMemberBtn.onclick = function () { openGroupInvite(); };
    }
    var settingsAddMemberBtn = $('chatSettingsAddMemberBtn');
    if (settingsAddMemberBtn && !settingsAddMemberBtn._bound) {
      settingsAddMemberBtn._bound = true;
      settingsAddMemberBtn.onclick = function () { openGroupInvite(); };
    }
    var memberSearch = $('chatMemberSearch');
    if (memberSearch && !memberSearch._bound) {
      memberSearch._bound = true;
      memberSearch.addEventListener('input', function () {
        state.memberKeyword = memberSearch.value || '';
        if (state.memberSearchTimer) clearTimeout(state.memberSearchTimer);
        state.memberSearchTimer = setTimeout(function () {
          loadMembers(state.memberKeyword).catch(function () {});
        }, 250);
      });
    }
    var memberList = $('chatMemberList');
    if (memberList && !memberList._bound) {
      memberList._bound = true;
      memberList.addEventListener('click', function (ev) {
        var item = ev.target.closest('.chat-member-item');
        if (!item || item.disabled) return;
        var uid = parseInt(item.getAttribute('data-uid'), 10) || 0;
        var member = null;
        for (var i = 0; i < state.members.length; i++) {
          if ((state.members[i].user_id | 0) === uid) {
            member = state.members[i];
            break;
          }
        }
        if (member) {
          if (item.getAttribute('data-mod') === '1') openMemberAction(member);
        }
      });
    }
    var inviteBack = $('chatGroupInviteBack');
    if (inviteBack && !inviteBack._bound) {
      inviteBack._bound = true;
      inviteBack.onclick = function () { closeSubPane('chatGroupInvitePane'); };
    }
    var inviteSearch = $('chatInviteSearch');
    if (inviteSearch && !inviteSearch._bound) {
      inviteSearch._bound = true;
      inviteSearch.addEventListener('input', function () {
        state.inviteKeyword = inviteSearch.value || '';
        if (state.inviteSearchTimer) clearTimeout(state.inviteSearchTimer);
        state.inviteSearchTimer = setTimeout(function () {
          loadCandidates(state.inviteKeyword).catch(function () {});
        }, 250);
      });
    }
    var inviteList = $('chatInviteList');
    if (inviteList && !inviteList._bound) {
      inviteList._bound = true;
      inviteList.addEventListener('change', function (ev) {
        var cb = ev.target.closest('.chat-invite-check');
        if (!cb) return;
        var uid = parseInt(cb.getAttribute('data-uid'), 10) || 0;
        if (cb.checked) state.inviteSelected[uid] = true;
        else delete state.inviteSelected[uid];
        var selectedCount = Object.keys(state.inviteSelected).filter(function (k) {
          return state.inviteSelected[k];
        }).length;
        var btn = $('chatInviteConfirmBtn');
        if (btn) {
          btn.disabled = selectedCount <= 0;
          btn.textContent = '确认添加 (' + selectedCount + ' 人)';
        }
      });
    }
    var inviteConfirm = $('chatInviteConfirmBtn');
    if (inviteConfirm && !inviteConfirm._bound) {
      inviteConfirm._bound = true;
      inviteConfirm.onclick = function () { confirmInvite(); };
    }
    var actionCancel = $('chatMemberActionCancel');
    if (actionCancel && !actionCancel._bound) {
      actionCancel._bound = true;
      actionCancel.onclick = closeMemberSheets;
    }
    var actionMask = $('chatMemberActionMask');
    if (actionMask && !actionMask._bound) {
      actionMask._bound = true;
      actionMask.onclick = closeMemberSheets;
    }
    var actionSheet = $('chatMemberActionSheet');
    if (actionSheet && !actionSheet._bound) {
      actionSheet._bound = true;
      actionSheet.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.chat-action-item[data-act]');
        if (!btn) return;
        var act = btn.getAttribute('data-act');
        if (act === 'kick') doKickMember();
        else if (act === 'mute') openMuteDurationSheet();
        else if (act === 'unmute') doMuteMember(0);
        else if (act === 'set_admin') doSetAdmin(true);
        else if (act === 'unset_admin') doSetAdmin(false);
      });
    }
    var muteDurCancel = $('chatMuteDurationCancel');
    if (muteDurCancel && !muteDurCancel._bound) {
      muteDurCancel._bound = true;
      muteDurCancel.onclick = function () {
        var sheet = $('chatMuteDurationSheet');
        if (sheet) {
          sheet.classList.remove('open');
          sheet.setAttribute('aria-hidden', 'true');
        }
      };
    }
    var muteDurMask = $('chatMuteDurationMask');
    if (muteDurMask && !muteDurMask._bound) {
      muteDurMask._bound = true;
      muteDurMask.onclick = function () {
        var sheet = $('chatMuteDurationSheet');
        if (sheet) {
          sheet.classList.remove('open');
          sheet.setAttribute('aria-hidden', 'true');
        }
      };
    }
    var muteDurSheet = $('chatMuteDurationSheet');
    if (muteDurSheet && !muteDurSheet._bound) {
      muteDurSheet._bound = true;
      muteDurSheet.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-mute-sec]');
        if (!btn) return;
        doMuteMember(parseInt(btn.getAttribute('data-mute-sec'), 10) || 0);
      });
    }
    var sendBtn = $('chatSendBtn');
    if (sendBtn && !sendBtn._bound) {
      sendBtn._bound = true;
      sendBtn.onclick = function () { sendText(); };
    }
    var emojiBtn = $('chatEmojiBtn');
    if (emojiBtn && !emojiBtn._bound) {
      emojiBtn._bound = true;
      emojiBtn.onclick = function () { toggleEmojiPanel(); };
    }
    var attachBtn = $('chatAttachBtn');
    if (attachBtn && !attachBtn._bound) {
      attachBtn._bound = true;
      attachBtn.onclick = function () { toggleAttachPanel(); };
    }
    var pickImageBtn = $('chatPickImageBtn');
    if (pickImageBtn && !pickImageBtn._bound) {
      pickImageBtn._bound = true;
      pickImageBtn.onclick = function () {
        var input = $('chatImageInput');
        if (input) input.click();
      };
    }
    var pickVideoBtn = $('chatPickVideoBtn');
    if (pickVideoBtn && !pickVideoBtn._bound) {
      pickVideoBtn._bound = true;
      pickVideoBtn.onclick = function () {
        var input = $('chatVideoInput');
        if (input) input.click();
      };
    }
    var attachRpBtn = $('chatAttachRpBtn');
    if (attachRpBtn && !attachRpBtn._bound) {
      attachRpBtn._bound = true;
      attachRpBtn.onclick = function () {
        closeComposerPanels();
        openRpSendPage();
      };
    }
    var attachTfBtn = $('chatAttachTransferBtn');
    if (attachTfBtn && !attachTfBtn._bound) {
      attachTfBtn._bound = true;
      attachTfBtn.onclick = function () {
        closeComposerPanels();
        openTransferSendPage();
      };
    }
    var tfCancel = $('chatTransferCancelBtn');
    if (tfCancel && !tfCancel._bound) {
      tfCancel._bound = true;
      tfCancel.onclick = closeTransferSendPage;
    }
    var tfSubmit = $('chatTransferSubmitBtn');
    if (tfSubmit && !tfSubmit._bound) {
      tfSubmit._bound = true;
      tfSubmit.onclick = function () { submitTransfer(); };
    }
    var tfAmount = $('chatTransferAmount');
    if (tfAmount && !tfAmount._bound) {
      tfAmount._bound = true;
      tfAmount.addEventListener('input', updateTransferPreview);
    }
    var imageInput = $('chatImageInput');
    if (imageInput && !imageInput._bound) {
      imageInput._bound = true;
      imageInput.addEventListener('change', function () {
        var file = imageInput.files && imageInput.files[0];
        imageInput.value = '';
        if (file) handleMediaFile(file, 'image');
      });
    }
    var videoInput = $('chatVideoInput');
    if (videoInput && !videoInput._bound) {
      videoInput._bound = true;
      videoInput.addEventListener('change', function () {
        var file = videoInput.files && videoInput.files[0];
        videoInput.value = '';
        if (file) handleMediaFile(file, 'video');
      });
    }
    var msgScroll = $('chatMsgScroll');
    if (msgScroll && !msgScroll._recallBound) {
      msgScroll._recallBound = true;
      msgScroll.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.chat-msg-recall');
        if (!btn) return;
        ev.preventDefault();
        var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
        if (!id) return;
        var isDelete = btn.classList.contains('chat-msg-delete');
        if (!window.confirm(isDelete ? '确定删除这条消息？' : '确定撤回这条消息？')) return;
        recallMessage(id);
      });
    }
    var stickerUploadInput = $('chatStickerUploadInput');
    if (stickerUploadInput && !stickerUploadInput._bound) {
      stickerUploadInput._bound = true;
      stickerUploadInput.addEventListener('change', function () {
        var file = stickerUploadInput.files && stickerUploadInput.files[0];
        stickerUploadInput.value = '';
        if (file) uploadCustomSticker(file);
      });
    }
    var rpCancel = $('chatRpCancelBtn');
    if (rpCancel && !rpCancel._bound) {
      rpCancel._bound = true;
      rpCancel.onclick = closeRpSendPage;
    }
    var rpSubmit = $('chatRpSubmitBtn');
    if (rpSubmit && !rpSubmit._bound) {
      rpSubmit._bound = true;
      rpSubmit.onclick = function () { submitRedPacket(); };
    }
    var rpBless = $('chatRpBlessing');
    if (rpBless && !rpBless._bound) {
      rpBless._bound = true;
      rpBless.addEventListener('input', updateRpPreview);
    }
    var rpAmount = $('chatRpAmount');
    if (rpAmount && !rpAmount._bound) {
      rpAmount._bound = true;
      rpAmount.addEventListener('input', updateRpPreview);
    }
    var rpCount = $('chatRpCount');
    if (rpCount && !rpCount._bound) {
      rpCount._bound = true;
      rpCount.addEventListener('input', updateRpPreview);
    }
    var rpTypeTabs = $('chatRpTypeTabs');
    if (rpTypeTabs && !rpTypeTabs._bound) {
      rpTypeTabs._bound = true;
      rpTypeTabs.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.chat-rp-type-btn');
        if (!btn) return;
        rpTypeTabs.querySelectorAll('.chat-rp-type-btn').forEach(function (b) {
          b.classList.toggle('active', b === btn);
        });
        updateRpPreview();
      });
    }
    var rpCountTabs = $('chatRpCountTabs');
    if (rpCountTabs && !rpCountTabs._bound) {
      rpCountTabs._bound = true;
      rpCountTabs.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.chat-rp-count-btn');
        if (!btn) return;
        var n = parseInt(btn.getAttribute('data-count'), 10) || 5;
        var countInput = $('chatRpCount');
        if (countInput) countInput.value = String(n);
        rpCountTabs.querySelectorAll('.chat-rp-count-btn').forEach(function (b) {
          b.classList.toggle('active', b === btn);
        });
        updateRpPreview();
      });
    }
    var rpMine = $('chatRpMineDigit');
    if (rpMine && !rpMine._bound) {
      rpMine._bound = true;
      rpMine.addEventListener('input', updateRpPreview);
    }
    var input = $('chatInput');
    if (input && !input._bound) {
      input._bound = true;
      input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          sendText();
        }
      });
    }
    var msgScroll = $('chatMsgScroll');
    if (msgScroll && !msgScroll._bound) {
      msgScroll._bound = true;
      msgScroll.addEventListener('click', function (ev) {
        var card = ev.target.closest('.chat-rp-card');
        if (card) {
          openRedPacketDetail(parseInt(card.getAttribute('data-packet'), 10) || 0);
          return;
        }
        var zoomBtn = ev.target.closest('.chat-media-zoom-btn');
        if (zoomBtn) {
          ev.preventDefault();
          openMediaLightbox(zoomBtn.getAttribute('data-preview') || '', zoomBtn.getAttribute('data-preview-type') || 'video');
          return;
        }
        var previewEl = ev.target.closest('[data-preview]');
        if (previewEl && (previewEl.classList.contains('chat-media-img') || previewEl.classList.contains('chat-sticker-img'))) {
          ev.preventDefault();
          openMediaLightbox(
            previewEl.getAttribute('data-preview') || previewEl.getAttribute('src') || '',
            previewEl.getAttribute('data-preview-type') || 'image'
          );
        }
      });
    }
    var lightbox = ensureMediaLightbox();
    if (lightbox && !lightbox._bound) {
      lightbox._bound = true;
      lightbox.addEventListener('click', function (ev) {
        if (ev.target === lightbox || ev.target.id === 'chatMediaLightboxClose' || ev.target.closest('#chatMediaLightboxClose')) {
          closeMediaLightbox();
        }
      });
      document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') closeMediaLightbox();
      });
    }
    var newPrivate = $('chatNewPrivateBtn');
    if (newPrivate && !newPrivate._bound) {
      newPrivate._bound = true;
      newPrivate.onclick = function () {
        if (!state.isImAdmin) {
          if (typeof showFanshubToast === 'function') showFanshubToast('请直接点击列表中的客服会话', 'info');
          return;
        }
        var peer = prompt('请输入会员ID');
        if (!peer) return;
        var pid = parseInt(String(peer).trim(), 10) || 0;
        if (pid <= 0) {
          if (typeof showFanshubToast === 'function') showFanshubToast('无效的ID', 'error');
          return;
        }
        if (pid === state.userId) {
          if (typeof showFanshubToast === 'function') showFanshubToast('不能和自己聊天', 'error');
          return;
        }
        var a = Math.min(state.userId, pid);
        var b = Math.max(state.userId, pid);
        openRoom({ type: 1, id: a + '_' + b, peer: pid, title: 'ID ' + pid });
      };
    }
    var addFriend = $('chatAddFriendBtn');
    if (addFriend && !addFriend._bound) {
      addFriend._bound = true;
      addFriend.onclick = function () {
        var menu = $('chatPlusMenu');
        if (menu) menu.hidden = true;
        if (typeof openAddFriendPane === 'function') {
          openAddFriendPane();
          return;
        }
      };
    }
    var newGroup = $('chatNewGroupBtn');
    if (newGroup && !newGroup._bound) {
      newGroup._bound = true;
      newGroup.onclick = function () {
        var menu = $('chatPlusMenu');
        if (menu) menu.hidden = true;
        openCreateGroupPane();
      };
    }
    var sharePromo = $('chatSharePromoBtn');
    if (sharePromo && !sharePromo._bound) {
      sharePromo._bound = true;
      sharePromo.onclick = function () {
        var menu = $('chatPlusMenu');
        if (menu) menu.hidden = true;
        var open = function () {
          if (global.FansHubSharePoster && typeof FansHubSharePoster.open === 'function') {
            FansHubSharePoster.open();
          }
        };
        if (global.FansHubSharePoster) {
          open();
          return;
        }
        var assets = global.FansHubAssets;
        if (assets && typeof assets.loadCss === 'function' && typeof assets.loadJs === 'function') {
          Promise.all([
            assets.loadCss('css/share-poster.css'),
            assets.loadJs('js/share-poster.js')
          ]).then(open).catch(function () {
            if (typeof showFanshubToast === 'function') showFanshubToast(chatT('chat_share_promo_load_fail') || '分享页加载失败', 'error');
          });
        } else {
          open();
        }
      };
    }
    var createGroupBack = $('chatCreateGroupBack');
    if (createGroupBack && !createGroupBack._bound) {
      createGroupBack._bound = true;
      createGroupBack.onclick = function () { closeCreateGroupPane(); };
    }
    var createGroupNext = $('chatCreateGroupNext');
    if (createGroupNext && !createGroupNext._bound) {
      createGroupNext._bound = true;
      createGroupNext.onclick = function () { submitCreateGroup(); };
    }
    var createGroupNextTop = $('chatCreateGroupNextTop');
    if (createGroupNextTop && !createGroupNextTop._bound) {
      createGroupNextTop._bound = true;
      createGroupNextTop.onclick = function () { submitCreateGroup(); };
    }
    var createGroupAvatar = $('chatCreateGroupAvatar');
    if (createGroupAvatar && !createGroupAvatar._bound) {
      createGroupAvatar._bound = true;
      createGroupAvatar.onclick = function () { cycleCreateGroupAvatar(); };
    }
    var createGroupName = $('chatCreateGroupName');
    if (createGroupName && !createGroupName._bound) {
      createGroupName._bound = true;
      createGroupName.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          submitCreateGroup();
        }
      });
    }
    var privacyCards = $('chatCgPrivacyCards');
    if (privacyCards && !privacyCards._bound) {
      privacyCards._bound = true;
      privacyCards.addEventListener('click', function (ev) {
        var card = ev.target.closest('.chat-cg-card[data-privacy]');
        if (!card) return;
        state.createGroup.privacy = card.getAttribute('data-privacy') || 'private';
        syncCreateGroupCards();
      });
    }
    var modeCards = $('chatCgModeCards');
    if (modeCards && !modeCards._bound) {
      modeCards._bound = true;
      modeCards.addEventListener('click', function (ev) {
        var card = ev.target.closest('.chat-cg-card[data-mode]');
        if (!card) return;
        state.createGroup.chatMode = card.getAttribute('data-mode') || 'chat';
        syncCreateGroupCards();
      });
    }
    var profileClose = $('chatProfileClose');
    var profileMask = $('chatUserProfileMask');
    if (profileClose && !profileClose._bound) {
      profileClose._bound = true;
      profileClose.onclick = closeUserProfile;
    }
    if (profileMask && !profileMask._bound) {
      profileMask._bound = true;
      profileMask.onclick = closeUserProfile;
    }
    var profileAdd = $('chatProfileAddFriend');
    if (profileAdd && !profileAdd._bound) {
      profileAdd._bound = true;
      // 已关闭：禁止通过资料页加好友，仅允许手机号/会员ID搜索
      profileAdd.onclick = function () {
        if (typeof showFanshubToast === 'function') {
          showFanshubToast(chatT('chat_add_friend_hint') || '请通过手机号或8位会员ID添加好友', 'info');
        }
        closeUserProfile();
        if (typeof openAddFriendPane === 'function') openAddFriendPane();
      };
    }
    var profileChat = $('chatProfilePrivateChat');
    if (profileChat && !profileChat._bound) {
      profileChat._bound = true;
      profileChat.onclick = function () {
        var t = state.profileTarget;
        if (!t) return;
        closeUserProfile();
        var a = Math.min(state.userId, t.user_id | 0);
        var b = Math.max(state.userId, t.user_id | 0);
        openRoom({ type: 1, id: a + '_' + b, peer: t.user_id | 0, title: t.nickname || ('ID' + t.user_id) });
      };
    }
    var rpDetailBack = $('chatRpDetailBack');
    if (rpDetailBack && !rpDetailBack._bound) {
      rpDetailBack._bound = true;
      rpDetailBack.onclick = function () {
        if (isRpFairViewOpen()) {
          hideRpFairVerify();
          return;
        }
        closeSubPane('chatRpDetailPane');
      };
    }
    var rpDetailList = $('chatRpDetailList');
    if (rpDetailList && !rpDetailList._bound) {
      rpDetailList._bound = true;
      // 已关闭：红包详情点击头像看资料
    }
    var rpDetailGrab = $('chatRpDetailGrabBtn');
    if (rpDetailGrab && !rpDetailGrab._bound) {
      rpDetailGrab._bound = true;
      rpDetailGrab.onclick = function () {
        var pid = parseInt(rpDetailGrab.getAttribute('data-packet'), 10) || 0;
        if (!pid) return;
        grabPacket(pid).then(function () {
          openRedPacketDetail(pid);
        }).catch(function () {});
      };
    }
    var modeSave = $('chatGroupModeSaveBtn');
    if (modeSave && !modeSave._bound) {
      modeSave._bound = true;
      modeSave.onclick = function () { saveGroupModes(); };
    }
  }

  function onTabEnter() {
    bindUi();
    syncBalanceFromAccount();
    updateMoneyLabel();
    // 进消息页立刻出缓存列表，再后台校准
    if (!hydrateListFromCache()) showListSkeleton();
    connect(false);
    if (state.connected) {
      // auth.ok 刚刷新过则跳过，避免进消息 Tab 连打两次 conversation.list
      refreshList(false).catch(function () {});
    }
    state.loadedOnce = true;
  }

  function onLocaleChange(opts) {
    opts = opts || {};
    const skipNetwork = !!opts.skipNetwork;
    updateMoneyLabel();
    refreshConnStatusLabel();
    renderList();
    var balEl = $('chatRpBalance');
    if (balEl && state.money != null && !isNaN(state.money)) {
      balEl.textContent = moneyText(state.money);
    }
    if (typeof setCommissionListMode === 'function') {
      try { setCommissionListMode(state.commissionListMode || 'recent'); } catch (e1) {}
    }
    // 切语言：有缓存则只重绘，不强制打佣金接口
    if (typeof refreshCommissionPanel === 'function') {
      try {
        if (skipNetwork && state.commissionData) {
          setCommissionListMode(state.commissionListMode || 'recent');
          if ($('chatCommissionTotal')) $('chatCommissionTotal').textContent = (typeof formatMoneyYuan === 'function' ? formatMoneyYuan(state.commissionData.total_money) : String(state.commissionData.total_money || 0));
          if ($('chatCommissionWithdrawable')) $('chatCommissionWithdrawable').textContent = (typeof formatMoneyYuan === 'function' ? formatMoneyYuan(state.commissionData.withdrawable) : String(state.commissionData.withdrawable || 0));
          if ($('chatCommissionToday')) $('chatCommissionToday').textContent = (typeof formatMoneyYuan === 'function' ? formatMoneyYuan(state.commissionData.today_money) : String(state.commissionData.today_money || 0));
          if ($('chatCommissionRebate')) $('chatCommissionRebate').textContent = (typeof formatMoneyYuan === 'function' ? formatMoneyYuan(state.commissionData.rebate_money) : String(state.commissionData.rebate_money || 0));
        } else if (!skipNetwork) {
          refreshCommissionPanel(true);
        }
      } catch (e2) {}
    }
  }

  /** 等待 WS 鉴权成功（auth.ok），用于深链回详情等场景 */
  function waitUntilConnected(timeoutMs) {
    timeoutMs = timeoutMs || 15000;
    return new Promise(function (resolve, reject) {
      if (state.connected && state.ws && state.ws.readyState === 1) {
        resolve(true);
        return;
      }
      if (!token()) {
        reject(new Error('未登录'));
        return;
      }
      try { ensureConnected(); } catch (e0) {}
      if (!state.connected && !state.connecting) {
        try { connect(true); } catch (e1) {}
      }
      var start = Date.now();
      var timer = setInterval(function () {
        if (state.connected && state.ws && state.ws.readyState === 1) {
          clearInterval(timer);
          resolve(true);
          return;
        }
        if (Date.now() - start >= timeoutMs) {
          clearInterval(timer);
          reject(new Error('未连接'));
        }
      }, 120);
    });
  }

  function onLogin() {
    // 先连 WS，再绑 UI / 拉余额（任意页都能收推送）
    connect(true);
    bindUi();
    state.stickerLoaded = false;
    state.stickerManifest = null;
    syncBalanceFromAccount();
    hydrateListFromCache();
    // 等连上再开红包详情，避免「未连接」
    setTimeout(function () { consumeOpenRpDeepLink(); }, 50);
  }

  function consumeOpenRpDeepLink() {
    var pid = 0;
    var keepPayload = null;
    try {
      var q = new URLSearchParams(location.search || '');
      pid = parseInt(q.get('open_rp') || '0', 10) || 0;
    } catch (e0) { pid = 0; }
    try {
      var raw = sessionStorage.getItem('fans_hub_rp_fair_return');
      if (raw) {
        var j = JSON.parse(raw);
        keepPayload = j;
        if (j && (j.reopen || pid > 0) && (j.packetId | 0) > 0) {
          if (!pid) pid = j.packetId | 0;
        } else if (j && !j.reopen && !(pid > 0)) {
          return;
        }
      }
    } catch (e1) {}
    if (pid <= 0) return;
    if (state._openingRpDeepLink) return;
    state._openingRpDeepLink = true;

    try {
      var u = new URL(location.href);
      if (u.searchParams.has('open_rp')) {
        u.searchParams.delete('open_rp');
        history.replaceState(null, '', u.pathname + (u.search || '') + (u.hash || ''));
      }
    } catch (e2) {}
    try {
      if (typeof switchTab === 'function') switchTab('messages');
      else if (typeof global.switchTab === 'function') global.switchTab('messages');
    } catch (e3) {}

    var room = keepPayload && keepPayload.room ? keepPayload.room : null;

    waitUntilConnected(18000).then(function () {
      // 连接成功后再清 reopen，失败可重试
      try {
        if (keepPayload) {
          keepPayload.reopen = 0;
          sessionStorage.setItem('fans_hub_rp_fair_return', JSON.stringify(keepPayload));
        }
      } catch (eClr) {}
      // 先踢开会话壳（不 await 历史），再立刻开详情，避免历史超时挡住详情
      if (room && ((room.type | 0) === 1 || (room.type | 0) === 2) && room.id) {
        openRoom({
          type: room.type | 0,
          id: room.id,
          peer: room.peer | 0,
          title: room.title || ''
        }).catch(function () {});
      }
      return openRedPacketDetail(pid);
    }).catch(function (err) {
      // 保留 reopen，下次进红宝页可再试
      try {
        if (keepPayload) {
          keepPayload.reopen = 1;
          keepPayload.packetId = pid;
          sessionStorage.setItem('fans_hub_rp_fair_return', JSON.stringify(keepPayload));
        }
      } catch (eKeep) {}
      if (typeof showFanshubToast === 'function') {
        showFanshubToast((err && err.message) || '未连接，请稍后重试', 'error');
      }
    }).then(function () {
      state._openingRpDeepLink = false;
    }, function () {
      state._openingRpDeepLink = false;
    });
  }

  function onLogout() {
    closeRoom();
    disconnect();
    state.list = [];
    state.messages = [];
    state.stickerLoaded = false;
    state.stickerManifest = null;
    state.stickerQuota = { count: 0, limit: 50, is_admin: false };
    state.userId = 0;
    state.money = null;
    state.listKeyword = '';
    var search = $('chatConvSearch');
    if (search) search.value = '';
    renderList();
    updateTabBadge();
    updateMoneyLabel();
  }

  global.FansHubChat = {
    onTabEnter: onTabEnter,
    onLogin: onLogin,
    onLogout: onLogout,
    onLocaleChange: onLocaleChange,
    connect: connect,
    ensureConnected: ensureConnected,
    disconnect: disconnect,
    closeRoom: closeRoom,
    openRedPacketDetail: openRedPacketDetail,
    consumeOpenRpDeepLink: consumeOpenRpDeepLink,
    addFriendByMemberId: null
  };

  // 从验证页 history.back 回来时 onLogin 不会再跑，靠 pageshow 重开详情
  try {
    global.addEventListener('pageshow', function () {
      try {
        var raw = sessionStorage.getItem('fans_hub_rp_fair_return');
        if (!raw) return;
        var j = JSON.parse(raw);
        if (!j || !j.reopen || !(j.packetId | 0)) return;
        try { ensureConnected(); } catch (eC) {}
        if (!state.connected && !state.connecting) {
          try { connect(true); } catch (eC2) {}
        }
        consumeOpenRpDeepLink();
      } catch (ePs) {}
    });
  } catch (eBind) {}
/* === 05-community.js === */
/**
 * Community tab + phone add-friend (loaded after 04-net.js inside FansHubChat IIFE)
 */
  var _officialOnlinePollTimer = null;

  function stopOfficialOnlinePoll() {
    if (_officialOnlinePollTimer) {
      clearInterval(_officialOnlinePollTimer);
      _officialOnlinePollTimer = null;
    }
  }

  function startOfficialOnlinePoll() {
    stopOfficialOnlinePoll();
    _officialOnlinePollTimer = setInterval(function () {
      if (state.homeTab !== 'community' || (state.communitySubTab || 'official') !== 'official') {
        stopOfficialOnlinePoll();
        return;
      }
      if (document.hidden) return;
      refreshOfficialCommunitiesQuiet().catch(function () {});
    }, 2000);
  }

  function setHomeTab(tab) {
    tab = tab === 'community' ? 'community' : 'chat';
    state.homeTab = tab;
    var chatPanel = $('chatHomePanelChat');
    var communityPanel = $('chatHomePanelCommunity');
    var tabChat = $('chatHomeTabChat');
    var tabCommunity = $('chatHomeTabCommunity');
    if (chatPanel) {
      chatPanel.style.display = tab === 'chat' ? '' : 'none';
      if (tab === 'chat') chatPanel.removeAttribute('hidden');
      else chatPanel.setAttribute('hidden', 'hidden');
    }
    if (communityPanel) {
      communityPanel.style.display = tab === 'community' ? '' : 'none';
      if (tab === 'community') communityPanel.removeAttribute('hidden');
      else communityPanel.setAttribute('hidden', 'hidden');
    }
    if (tabChat) {
      tabChat.classList.toggle('active', tab === 'chat');
      tabChat.setAttribute('aria-selected', tab === 'chat' ? 'true' : 'false');
    }
    if (tabCommunity) {
      tabCommunity.classList.toggle('active', tab === 'community');
      tabCommunity.setAttribute('aria-selected', tab === 'community' ? 'true' : 'false');
    }
    if (tab === 'community') {
      setCommunitySubTab(state.communitySubTab || 'official');
      // 官方走 API；我的群组/好友仍走 WS（在 refreshCommunity 后半段）
      refreshCommunity().catch(function () {});
    } else {
      stopOfficialOnlinePoll();
    }
  }

  function setCommunitySubTab(sub) {
    if (sub !== 'mine' && sub !== 'friends') sub = 'official';
    state.communitySubTab = sub;
    if (sub === 'official' && state.homeTab === 'community') {
      startOfficialOnlinePoll();
    } else {
      stopOfficialOnlinePoll();
    }
    var map = {
      official: 'chatCommunityPaneOfficial',
      mine: 'chatCommunityPaneMine',
      friends: 'chatCommunityPaneFriends'
    };
    var btnMap = {
      official: 'chatCommunityTabOfficial',
      mine: 'chatCommunityTabMine',
      friends: 'chatCommunityTabFriends'
    };
    Object.keys(map).forEach(function (key) {
      var pane = $(map[key]);
      var btn = $(btnMap[key]);
      var on = key === sub;
      if (pane) {
        pane.classList.toggle('active', on);
        if (on) {
          pane.removeAttribute('hidden');
          pane.style.display = '';
        } else {
          pane.setAttribute('hidden', 'hidden');
          pane.style.display = 'none';
        }
      }
      if (btn) {
        btn.classList.toggle('active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
      }
    });
  }

  function chatTx(key, fallback, extra) {
    var t = chatT(key, extra);
    if (!t || t === key) return fallback;
    return t;
  }

  function groupCardEmoji(name) {
    var n = String(name || '');
    if (/红包|福利|🧧/.test(n)) return '🧧';
    if (/保密|私密|元宝|金/.test(n)) return '🪙';
    if (/礼|赠|礼物/.test(n)) return '🎁';
    if (/开放|红宝/.test(n)) return '👑';
    return '🧧';
  }

  function renderRecommendGroups() {
    var box = $('chatRecommendGroups');
    if (!box) return;
    var list = state.recommendGroups || [];
    var kw = String(state.listKeyword || '').trim().toLowerCase();
    if (kw) {
      list = list.filter(function (g) {
        return String(g.name || '').toLowerCase().indexOf(kw) >= 0
          || String(g.id || '').indexOf(kw) >= 0;
      });
    }
    if (!list.length) {
      box.innerHTML = '<div class="chat-empty chat-empty-glass">' + escapeHtml(chatTx('chat_recommend_empty', '暂无推荐社群')) + '</div>';
      return;
    }
    box.innerHTML = list.map(function (g, idx) {
      var members = g.member_count | 0;
      var sub = members > 0
        ? chatTx('chat_group_members', '{count}人', { count: members })
        : '';
      var av = publicUrl(g.avatar || '');
      var icon = '<img src="' + escapeHtml(avatarSrc(g.avatar)) + '" alt="">';
      var showTag = !g.is_member && idx === 0;
      var tag = showTag ? ('<div class="tag">' + escapeHtml(chatTx('chat_recommend_tag', '新建社群')) + '</div>') : (g.is_member ? '' : '');
      if (!g.is_member && !showTag && g.recommend_tag) {
        tag = '<div class="tag">' + escapeHtml(String(g.recommend_tag)) + '</div>';
      }
      return '<button type="button" class="chat-group-card" data-group-id="' + (g.id | 0) + '">'
        + tag
        + '<div class="icon-box">' + icon + '</div>'
        + '<div class="title">' + escapeHtml(g.name || ('#' + g.id)) + '</div>'
        + '<div class="subtitle">' + escapeHtml(sub) + '</div>'
        + '</button>';
    }).join('');
    box.querySelectorAll('.chat-group-card').forEach(function (btn) {
      btn.onclick = function () {
        openRecommendOrMyGroup(parseInt(btn.getAttribute('data-group-id'), 10) || 0);
      };
    });
  }

  function renderMyGroups() {
    var box = $('chatMyGroupsList');
    if (!box) return;
    var list = state.myGroups || [];
    var kw = String(state.listKeyword || '').trim().toLowerCase();
    if (kw) {
      list = list.filter(function (g) {
        return String(g.name || '').toLowerCase().indexOf(kw) >= 0;
      });
    }
    var createCard = ''
      + '<button type="button" class="chat-my-group-item chat-my-group-create" id="chatMyGroupsCreateCard">'
      +   '<span class="chat-my-group-main">'
      +     '<span class="chat-my-group-avatar chat-my-group-avatar-plus" aria-hidden="true">+</span>'
      +     '<span class="chat-my-group-create-text">'
      +       '<span class="chat-my-group-name">+ 创建我的专属保密对战群</span>'
      +       '<span class="chat-my-group-sub">零门槛当群主，躺赚群内 1% 发包管理费津贴</span>'
      +     '</span>'
      +   '</span>'
      + '</button>';
    if (!list.length) {
      box.innerHTML = createCard;
    } else {
      box.innerHTML = createCard + list.map(function (g) {
        var display = g.display_member_count | 0;
        var cnt = display > 0 ? display : (g.member_count | 0);
        var name = g.name || ('#' + g.id);
        return '<button type="button" class="chat-my-group-item" data-group-id="' + (g.id | 0) + '">'
          + '<span class="chat-my-group-main">'
          + avatarImgHtml(g.avatar, 'chat-my-group-avatar')
          + '<span class="chat-my-group-name">' + escapeHtml(name) + '</span>'
          + '</span>'
          + '<span class="chat-my-group-count">' + cnt + '<small>人</small></span>'
          + '</button>';
      }).join('');
    }
    var createBtn = $('chatMyGroupsCreateCard');
    if (createBtn) {
      createBtn.onclick = function () {
        if (typeof openCreateGroupPane === 'function') {
          openCreateGroupPane({ fromCreateCard: true });
        } else if (typeof showFanshubToast === 'function') {
          showFanshubToast('建群功能暂不可用', 'error');
        }
      };
    }
    box.querySelectorAll('.chat-my-group-item[data-group-id]').forEach(function (btn) {
      btn.onclick = function () {
        var gid = parseInt(btn.getAttribute('data-group-id'), 10) || 0;
        var g = (state.myGroups || []).find(function (x) { return (x.id | 0) === gid; }) || { id: gid, name: '' };
        openRoom({ type: 2, id: gid, peer: 0, title: g.name || ('群' + gid) });
      };
    });
  }

  function renderFriendFeed() {
    var box = $('chatFriendFeedList');
    if (!box) return;
    var list = state.friends || [];
    var kw = String(state.listKeyword || '').trim().toLowerCase();
    if (kw) {
      list = list.filter(function (f) {
        return String(f.nickname || '').toLowerCase().indexOf(kw) >= 0
          || String(f.user_id || '').indexOf(kw) >= 0;
      });
    }
    if (!list.length) {
      box.innerHTML = '<div class="chat-empty chat-empty-glass">' + escapeHtml(chatTx('chat_friend_feed_empty', '暂无好友')) + '</div>';
      return;
    }
    box.innerHTML = list.map(function (f) {
      var online = !!f.online;
      var isCs = !!f.is_default_cs || !!f.pinned;
      var status = online
        ? chatTx('chat_friend_online', '刚刚在线')
        : chatTx('chat_friend_offline', '暂时离开');
      return '<button type="button" class="chat-feed-item' + (isCs ? ' is-pinned-cs' : '') + '" data-user-id="' + (f.user_id | 0) + '">'
        + '<div class="chat-feed-avatar">' + avatarImgHtml(f.avatar) + '<span class="chat-feed-online-dot' + (online ? '' : ' off') + '"></span></div>'
        + '<div class="chat-feed-body">'
        + '<div class="chat-feed-text">' + (isCs ? '<span class="chat-feed-pin" aria-hidden="true">📌</span>' : '') + escapeHtml(f.nickname || ('ID' + f.user_id)) + '</div>'
        + '<div class="chat-feed-status' + (online ? ' on' : '') + '">' + escapeHtml(status) + '</div>'
        + '</div>'
        + '</button>';
    }).join('');
    box.querySelectorAll('.chat-feed-item').forEach(function (btn) {
      btn.onclick = function () {
        var pid = parseInt(btn.getAttribute('data-user-id'), 10) || 0;
        if (!pid || !state.userId) return;
        var f = (state.friends || []).find(function (x) { return (x.user_id | 0) === pid; });
        var a = Math.min(state.userId, pid);
        var b = Math.max(state.userId, pid);
        openRoom({ type: 1, id: a + '_' + b, peer: pid, title: (f && f.nickname) || ('ID ' + pid) });
      };
    });
  }

  async function refreshCommunity() {
    // 官方社群：仅 HTTP API，完全不走 WS；先渲染再拉我的群组/好友
    state.recommendGroups = [];
    try {
      if (typeof global.apiRequest === 'function') {
        var recApi = await global.apiRequest('communityrecommend', 'GET', {});
        state.recommendGroups = (recApi && recApi.list) || [];
      }
    } catch (eApi) {
      state.recommendGroups = [];
    }
    renderRecommendGroups();

    if (state.connected) {
      try {
        var mine = await send('group.list', {});
        state.myGroups = (mine.data && mine.data.list) || [];
      } catch (e2) { state.myGroups = []; }
      try {
        var fr = await send('friend.list', {});
        state.friends = (fr.data && fr.data.list) || [];
      } catch (e3) { state.friends = []; }
    }
    try {
      var mineIds = {};
      (state.myGroups || []).forEach(function (g) { mineIds[g.id | 0] = true; });
      (state.recommendGroups || []).forEach(function (g) {
        if (mineIds[g.id | 0]) g.is_member = true;
      });
    } catch (eM) {}
    renderRecommendGroups();
    renderMyGroups();
    renderFriendFeed();
  }

  /** 仅刷新官方社群（API），不触碰 WS */
  async function refreshOfficialCommunities() {
    state.recommendGroups = [];
    try {
      if (typeof global.apiRequest === 'function') {
        var recApi = await global.apiRequest('communityrecommend', 'GET', {});
        state.recommendGroups = (recApi && recApi.list) || [];
      }
    } catch (eApi) {
      state.recommendGroups = [];
    }
    try {
      var mineIds = {};
      (state.myGroups || []).forEach(function (g) { mineIds[g.id | 0] = true; });
      (state.recommendGroups || []).forEach(function (g) {
        if (mineIds[g.id | 0]) g.is_member = true;
      });
    } catch (eM) {}
    renderRecommendGroups();
  }

  /** 静默刷新在线/人数（轮询用，不清空列表避免闪烁） */
  async function refreshOfficialCommunitiesQuiet() {
    try {
      if (typeof global.apiRequest !== 'function') return;
      var recApi = await global.apiRequest('communityrecommend', 'GET', {});
      var list = (recApi && recApi.list) || [];
      if (!list.length) return;
      var mineIds = {};
      (state.myGroups || []).forEach(function (g) { mineIds[g.id | 0] = true; });
      list.forEach(function (g) {
        if (mineIds[g.id | 0]) g.is_member = true;
      });
      state.recommendGroups = list;
      renderRecommendGroups();
    } catch (eQ) {}
  }

  async function openRecommendOrMyGroup(groupId) {
    groupId = groupId | 0;
    if (groupId <= 0) return;
    var g = (state.recommendGroups || []).find(function (x) { return (x.id | 0) === groupId; })
      || (state.myGroups || []).find(function (x) { return (x.id | 0) === groupId; });
    try {
      if (g && !g.is_member) {
        await send('group.join', { group_id: groupId });
        if (typeof showFanshubToast === 'function') showFanshubToast(chatT('chat_join_group_ok'), 'success');
        await refreshCommunity();
        g = (state.recommendGroups || []).find(function (x) { return (x.id | 0) === groupId; })
          || (state.myGroups || []).find(function (x) { return (x.id | 0) === groupId; })
          || g;
      }
      openRoom({ type: 2, id: groupId, peer: 0, title: (g && g.name) || ('群' + groupId) });
    } catch (e) {
      if (typeof showFanshubToast === 'function') {
        showFanshubToast(mapChatApiError(e && e.message, 'chat_join_group_fail'), 'error');
      }
    }
  }

  function fillAddFriendCountries() {
    var sel = $('chatAddFriendCountry');
    if (!sel) return;
    var countries = global.FANSHUB_COUNTRIES || [];
    var cur = (global.FanshubI18n && FanshubI18n.country) || 'CN';
    sel.innerHTML = countries.map(function (c) {
      var label = '+' + c.dial;
      try {
        if (global.FanshubI18n && typeof FanshubI18n.text === 'function' && c.labelKey) {
          label = '+' + c.dial + ' ' + (FanshubI18n.text(c.labelKey) || c.code);
        }
      } catch (e) {}
      return '<option value="' + escapeHtml(c.dial) + '" data-code="' + escapeHtml(c.code) + '" data-flag-iso="'
        + escapeHtml(c.flagIso || '') + '"'
        + (c.code === cur ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
    }).join('');
    if (global.FanshubI18n && typeof FanshubI18n.mountFlagSelect === 'function') {
      FanshubI18n.mountFlagSelect(sel, 'country');
    }
  }

  function openAddFriendPane() {
    closeGroupSubPanes();
    fillAddFriendCountries();
    var mobile = $('chatAddFriendMobile');
    if (mobile) mobile.value = '';
    var uid = $('chatAddFriendUserId');
    if (uid) uid.value = '';
    openSubPane('chatAddFriendPane');
    refreshFriendRequests().catch(function () {});
  }

  function closeAddFriendPane() {
    closeSubPane('chatAddFriendPane');
  }

  function parseAddFriendQuery() {
    var dialEl = $('chatAddFriendCountry');
    var mobileEl = $('chatAddFriendMobile');
    var idEl = $('chatAddFriendUserId');
    var dial = dialEl ? String(dialEl.value || '').replace(/\D+/g, '') : '';
    var mobileRaw = mobileEl ? String(mobileEl.value || '').trim() : '';
    var mobile = mobileRaw.replace(/\D+/g, '');
    // 粘贴 +8613... / 8613... 时自动剥掉区号，避免和左侧区号重复拼接
    if (mobile.length >= 10) {
      var dials = ['855', '86', '84', '63', '62', '60'];
      for (var i = 0; i < dials.length; i++) {
        var d = dials[i];
        if (mobile.indexOf(d) === 0 && mobile.length > d.length + 6) {
          if (!dial) dial = d;
          // 若输入已带国家码，优先用输入里的区号，本国号单独提交
          mobile = mobile.slice(d.length);
          dial = d;
          break;
        }
      }
    }
    var memberId = idEl ? String(idEl.value || '').replace(/\D+/g, '') : '';
    var hasId = /^\d{8}$/.test(memberId);
    var hasMobile = mobile.length >= 6 && mobile.length <= 15;
    if (hasId && hasMobile) {
      return { error: chatT('chat_add_friend_choose_one') || '请只填写手机号或会员ID其中一项' };
    }
    if (hasId) {
      return { mode: 'id', user_id: memberId };
    }
    if (hasMobile) {
      return { mode: 'mobile', mobile: mobile, country_dial: dial };
    }
    if (memberId) {
      return { error: chatT('chat_add_friend_id_invalid') || '会员ID须为8位数字' };
    }
    return { error: chatT('chat_add_friend_phone_invalid') || '请输入手机号或8位会员ID' };
  }

  function submitAddFriendByPhone() {
    var query = parseAddFriendQuery();
    if (query.error) {
      if (typeof showFanshubToast === 'function') showFanshubToast(query.error, 'error');
      return;
    }
    var btn = $('chatAddFriendSubmit');
    if (btn) btn.disabled = true;
    var lookupPayload = query.mode === 'id'
      ? { user_id: query.user_id }
      : { mobile: query.mobile, country_dial: query.country_dial };
    var requestPayload = query.mode === 'id'
      ? { user_id: query.user_id }
      : { mobile: query.mobile, country_dial: query.country_dial };
    return send('friend.lookup', lookupPayload).then(function (packet) {
      if (!packet.data || !packet.data.found) {
        if (typeof showFanshubToast === 'function') showFanshubToast(chatT('chat_add_friend_not_found'), 'error');
        return null;
      }
      var u = packet.data.user;
      var tip = chatT('chat_add_friend_confirm', { name: u.nickname || ('ID' + u.user_id) });
      if (!confirm(tip)) return null;
      // 仅允许手机号 / 8位ID，不传裸 peer_user_id
      return send('friend.request', requestPayload);
    }).then(function (packet2) {
      if (!packet2 || !packet2.data) return false;
      var data = packet2.data;
      var peer = data.peer_user_id | 0;
      var cid = data.conversation_id || '';
      closeAddFriendPane();
      refreshFriendRequests().catch(function () {});
      refreshList().catch(function () {});
      refreshCommunity().catch(function () {});
      if (data.auto_accepted || data.status === 'accepted' || data.status === 'already_friends') {
        openRoom({ type: 1, id: cid, peer: peer, title: chatT('chat_friend_title', { id: peer }) });
        if (typeof showFanshubToast === 'function') showFanshubToast(chatT('chat_add_friend_ok'), 'success');
      } else {
        if (typeof showFanshubToast === 'function') showFanshubToast(chatT('chat_friend_req_sent'), 'success');
        openFriendReqPane('outgoing');
      }
      return true;
    }).catch(function (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(mapChatApiError(e && e.message, 'chat_add_friend_fail'), 'error');
      return false;
    }).then(function (ok) {
      if (btn) btn.disabled = false;
      return !!ok;
    });
  }

  function addFriendByMemberId(memberId) {
    var id = String(memberId || '').replace(/\D+/g, '');
    if (!/^\d{8}$/.test(id)) {
      if (typeof showFanshubToast === 'function') showFanshubToast(chatT('chat_add_friend_id_invalid') || '会员ID须为8位数字', 'error');
      return Promise.resolve(false);
    }
    if ((state.userId | 0) === (id | 0)) {
      if (typeof showFanshubToast === 'function') showFanshubToast('不能添加自己为好友', 'error');
      return Promise.resolve(false);
    }
    return send('friend.lookup', { user_id: id }).then(function (packet) {
      if (!packet.data || !packet.data.found) {
        if (typeof showFanshubToast === 'function') showFanshubToast(chatT('chat_add_friend_not_found'), 'error');
        return null;
      }
      var u = packet.data.user;
      var tip = chatT('chat_add_friend_confirm', { name: u.nickname || ('ID' + u.user_id) });
      if (!confirm(tip)) return null;
      return send('friend.request', { user_id: id });
    }).then(function (packet2) {
      if (!packet2 || !packet2.data) return false;
      var data = packet2.data;
      var peer = data.peer_user_id | 0;
      var cid = data.conversation_id || '';
      closeAddFriendPane();
      if (typeof FansHubFriendQr !== 'undefined' && FansHubFriendQr.closeScanPane) FansHubFriendQr.closeScanPane();
      refreshFriendRequests().catch(function () {});
      refreshList().catch(function () {});
      refreshCommunity().catch(function () {});
      if (data.auto_accepted || data.status === 'accepted' || data.status === 'already_friends') {
        openRoom({ type: 1, id: cid, peer: peer, title: chatT('chat_friend_title', { id: peer }) });
        if (typeof showFanshubToast === 'function') showFanshubToast(chatT('chat_add_friend_ok'), 'success');
      } else {
        if (typeof showFanshubToast === 'function') showFanshubToast(chatT('chat_friend_req_sent'), 'success');
        openFriendReqPane('outgoing');
      }
      return true;
    }).catch(function (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast(mapChatApiError(e && e.message, 'chat_add_friend_fail'), 'error');
      return false;
    });
  }

  var friendReqTab = 'incoming';
  var friendReqCache = { incoming: [], outgoing: [], pending_count: 0 };

  function openFriendReqPane(tab) {
    closeGroupSubPanes();
    friendReqTab = tab === 'outgoing' ? 'outgoing' : 'incoming';
    openSubPane('chatFriendReqPane');
    syncFriendReqTabs();
    refreshFriendRequests().then(function () { renderFriendReqList(); }).catch(function () { renderFriendReqList(); });
  }

  function closeFriendReqPane() {
    closeSubPane('chatFriendReqPane');
  }

  function syncFriendReqTabs() {
    var tin = $('chatFriendReqTabIn');
    var tout = $('chatFriendReqTabOut');
    if (tin) tin.classList.toggle('active', friendReqTab === 'incoming');
    if (tout) tout.classList.toggle('active', friendReqTab === 'outgoing');
  }

  function updateFriendReqBadge(n) {
    n = n | 0;
    var text = n > 99 ? '99+' : String(n);
    ['chatFriendReqBadge', 'chatAddFriendReqBadge'].forEach(function (id) {
      var badge = $(id);
      if (!badge) return;
      if (n > 0) {
        badge.style.display = '';
        badge.textContent = text;
      } else {
        badge.style.display = 'none';
      }
    });
  }

  function refreshFriendRequests(force) {
    if (!state.connected) return Promise.resolve(friendReqCache);
    if (!force && state._friendReqAt && (Date.now() - state._friendReqAt) < 30000) {
      updateFriendReqBadge((friendReqCache && friendReqCache.pending_count) | 0);
      return Promise.resolve(friendReqCache);
    }
    return send('friend.requests', {}).then(function (packet) {
      friendReqCache = packet.data || { incoming: [], outgoing: [], pending_count: 0 };
      state._friendReqAt = Date.now();
      updateFriendReqBadge(friendReqCache.pending_count | 0);
      return friendReqCache;
    });
  }

  function friendReqStatusText(st) {
    var key = 'chat_friend_req_status_pending';
    var fallback = '待处理';
    if (st === 'accepted') { key = 'chat_friend_req_status_accepted'; fallback = '已通过'; }
    else if (st === 'rejected') { key = 'chat_friend_req_status_rejected'; fallback = '已拒绝'; }
    else if (st === 'cancelled') { key = 'chat_friend_req_status_cancelled'; fallback = '已取消'; }
    var t = chatT(key);
    return (t && t !== key) ? t : fallback;
  }

  function friendReqActionLabel(key, fallback) {
    var t = chatT(key);
    return (t && t !== key) ? t : fallback;
  }

  function renderFriendReqList() {
    var box = $('chatFriendReqList');
    if (!box) return;
    var list = friendReqTab === 'outgoing' ? (friendReqCache.outgoing || []) : (friendReqCache.incoming || []);
    if (!list.length) {
      box.innerHTML = '<div class="chat-empty chat-empty-sm">' + escapeHtml(chatT('chat_friend_req_empty')) + '</div>';
      return;
    }
    box.innerHTML = list.map(function (item) {
      var peer = item.peer || item.from_user || {};
      var name = peer.nickname || ('ID' + (item.peer_user_id || item.from_user_id || ''));
      var st = item.status || 'pending';
      var actions = '';
      if (friendReqTab === 'incoming' && st === 'pending') {
        actions =
          '<div class="chat-friend-req-actions">' +
            '<button type="button" class="chat-friend-req-btn reject" data-act="reject" data-id="' + (item.id | 0) + '">' + escapeHtml(friendReqActionLabel('chat_friend_req_reject', '拒绝')) + '</button>' +
            '<button type="button" class="chat-friend-req-btn accept" data-act="accept" data-id="' + (item.id | 0) + '">' + escapeHtml(friendReqActionLabel('chat_friend_req_accept', '通过')) + '</button>' +
          '</div>';
      } else if (friendReqTab === 'outgoing' && st === 'pending') {
        actions =
          '<div class="chat-friend-req-actions">' +
            '<button type="button" class="chat-friend-req-btn reject" data-act="cancel" data-id="' + (item.id | 0) + '">' + escapeHtml(friendReqActionLabel('chat_friend_req_cancel', '取消')) + '</button>' +
          '</div>';
      } else {
        actions = '<div class="chat-friend-req-status">' + escapeHtml(friendReqStatusText(st)) + '</div>';
      }
      var sub = item.message ? escapeHtml(item.message) : escapeHtml(friendReqStatusText(st));
      var peerAv = (item.peer && item.peer.avatar) || (item.from_user && item.from_user.avatar) || item.avatar || '';
      return (
        '<div class="chat-friend-req-item" data-id="' + (item.id | 0) + '">' +
          '<div class="chat-friend-req-avatar">' + avatarImgHtml(peerAv) + '</div>' +
          '<div class="chat-friend-req-body">' +
            '<div class="chat-friend-req-name">' + escapeHtml(name) + '</div>' +
            '<div class="chat-friend-req-sub">' + sub + '</div>' +
          '</div>' + actions +
        '</div>'
      );
    }).join('');
    box.querySelectorAll('[data-act]').forEach(function (btn) {
      btn.onclick = function () {
        var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
        var act = btn.getAttribute('data-act');
        if (!id) return;
        btn.disabled = true;
        var type = act === 'accept' ? 'friend.accept' : 'friend.reject';
        send(type, { request_id: id }).then(function () {
          if (act === 'accept' && typeof showFanshubToast === 'function') {
            showFanshubToast(chatT('chat_friend_req_accepted'), 'success');
          }
          return refreshFriendRequests();
        }).then(function () {
          renderFriendReqList();
          refreshList().catch(function () {});
          refreshCommunity().catch(function () {});
        }).catch(function (e) {
          if (typeof showFanshubToast === 'function') showFanshubToast(mapChatApiError(e && e.message, 'chat_friend_req_fail'), 'error');
        }).then(function () { btn.disabled = false; });
      };
    });
  }

  function closePlusMenu() {
    var menu = $('chatPlusMenu');
    var btn = $('chatPlusMenuBtn');
    if (menu) menu.hidden = true;
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }

  function togglePlusMenu() {
    var menu = $('chatPlusMenu');
    var btn = $('chatPlusMenuBtn');
    if (!menu) return;
    var open = !!menu.hidden;
    menu.hidden = !open;
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function openSearchBar() {
    closePlusMenu();
    var row = $('chatHomeSearchRow');
    var input = $('chatConvSearch');
    if (row) row.hidden = false;
    if (input) {
      setTimeout(function () {
        try { input.focus(); input.select(); } catch (e) {}
      }, 30);
    }
  }

  function closeSearchBar() {
    var row = $('chatHomeSearchRow');
    var input = $('chatConvSearch');
    if (row) row.hidden = true;
    if (input) {
      input.value = '';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      try { input.blur(); } catch (e) {}
    }
  }

  function bindCommunityUi() {
    var searchToggle = $('chatSearchToggleBtn');
    if (searchToggle && !searchToggle._bound) {
      searchToggle._bound = true;
      searchToggle.onclick = function (ev) {
        ev.stopPropagation();
        openSearchBar();
      };
    }
    var searchCancel = $('chatSearchCancelBtn');
    if (searchCancel && !searchCancel._bound) {
      searchCancel._bound = true;
      searchCancel.onclick = function (ev) {
        ev.stopPropagation();
        closeSearchBar();
      };
    }
    var plusBtn = $('chatPlusMenuBtn');
    if (plusBtn && !plusBtn._bound) {
      plusBtn._bound = true;
      plusBtn.onclick = function (ev) {
        ev.stopPropagation();
        togglePlusMenu();
      };
    }
    if (!document._chatPlusOutsideBound) {
      document._chatPlusOutsideBound = true;
      document.addEventListener('click', function (ev) {
        var wrap = $('chatPlusMenuWrap');
        if (!wrap) return;
        if (!wrap.contains(ev.target)) closePlusMenu();
      });
    }

    var addFriend = $('chatAddFriendBtn');
    if (addFriend && !addFriend._communityBound) {
      addFriend._communityBound = true;
      addFriend.onclick = function () {
        closePlusMenu();
        openAddFriendPane();
      };
    }
    var addFriendBack = $('chatAddFriendBack');
    if (addFriendBack && !addFriendBack._bound) {
      addFriendBack._bound = true;
      addFriendBack.onclick = function () { closeAddFriendPane(); };
    }
    var addFriendSubmit = $('chatAddFriendSubmit');
    if (addFriendSubmit && !addFriendSubmit._bound) {
      addFriendSubmit._bound = true;
      addFriendSubmit.onclick = function () { submitAddFriendByPhone(); };
    }
    var addFriendMobile = $('chatAddFriendMobile');
    if (addFriendMobile && !addFriendMobile._boundEnter) {
      addFriendMobile._boundEnter = true;
      addFriendMobile.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          submitAddFriendByPhone();
        }
      });
    }
    var addFriendUserId = $('chatAddFriendUserId');
    if (addFriendUserId && !addFriendUserId._boundEnter) {
      addFriendUserId._boundEnter = true;
      addFriendUserId.addEventListener('input', function () {
        addFriendUserId.value = String(addFriendUserId.value || '').replace(/\D+/g, '').slice(0, 8);
      });
      addFriendUserId.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          submitAddFriendByPhone();
        }
      });
    }
    var reqEntry = $('chatFriendReqEntryBtn');
    if (reqEntry && !reqEntry._bound) {
      reqEntry._bound = true;
      reqEntry.onclick = function () {
        closePlusMenu();
        openFriendReqPane('incoming');
      };
    }
    var addFriendReqLink = $('chatAddFriendReqLink');
    if (addFriendReqLink && !addFriendReqLink._bound) {
      addFriendReqLink._bound = true;
      addFriendReqLink.onclick = function () {
        openFriendReqPane('incoming');
      };
    }
    var reqBack = $('chatFriendReqBack');
    if (reqBack && !reqBack._bound) {
      reqBack._bound = true;
      reqBack.onclick = function () { closeFriendReqPane(); };
    }
    var reqTabIn = $('chatFriendReqTabIn');
    if (reqTabIn && !reqTabIn._bound) {
      reqTabIn._bound = true;
      reqTabIn.onclick = function () {
        friendReqTab = 'incoming';
        syncFriendReqTabs();
        renderFriendReqList();
      };
    }
    var reqTabOut = $('chatFriendReqTabOut');
    if (reqTabOut && !reqTabOut._bound) {
      reqTabOut._bound = true;
      reqTabOut.onclick = function () {
        friendReqTab = 'outgoing';
        syncFriendReqTabs();
        renderFriendReqList();
      };
    }
    var tabChat = $('chatHomeTabChat');
    if (tabChat && !tabChat._bound) {
      tabChat._bound = true;
      tabChat.onclick = function () { setHomeTab('chat'); };
    }
    var tabCommunity = $('chatHomeTabCommunity');
    if (tabCommunity && !tabCommunity._bound) {
      tabCommunity._bound = true;
      tabCommunity.onclick = function () { setHomeTab('community'); };
    }
    var communitySeg = document.querySelector('#chatHomePanelCommunity .chat-community-seg');
    if (communitySeg && !communitySeg._bound) {
      communitySeg._bound = true;
      communitySeg.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-community-tab]');
        if (!btn) return;
        var sub = btn.getAttribute('data-community-tab') || 'official';
        setCommunitySubTab(sub);
        if (sub === 'official') {
          refreshOfficialCommunities().catch(function () {});
        } else {
          refreshCommunity().catch(function () {});
        }
      });
    }
    var homeCreate = $('chatHomeCreateGroupBtn');
    if (homeCreate && !homeCreate._bound) {
      homeCreate._bound = true;
      homeCreate.onclick = function () {
        if (typeof openCreateGroupPane === 'function') openCreateGroupPane();
      };
    }
    var search = $('chatConvSearch');
    if (search && !search._communitySearchBound) {
      search._communitySearchBound = true;
      search.addEventListener('input', function () {
        setTimeout(function () {
          if (state.homeTab === 'community') {
            renderRecommendGroups();
            renderMyGroups();
            renderFriendFeed();
          }
        }, 200);
      });
    }
  }

  // Hook existing lifecycle
  var _origOnTabEnter = onTabEnter;
  onTabEnter = function () {
    _origOnTabEnter();
    bindCommunityUi();
    if (state.homeTab === 'community' && state.connected) {
      refreshCommunity().catch(function () {});
    }
    if (state.connected) refreshFriendRequests(false).catch(function () {});
  };

  var _origOnLocaleChange = onLocaleChange;
  onLocaleChange = function (opts) {
    _origOnLocaleChange(opts);
    fillAddFriendCountries();
    // 仅刷新已在内存中的列表 DOM，避免切语言时重渲染不可见大列表
    try {
      if (state.homeTab === 'community') {
        renderRecommendGroups();
        renderMyGroups();
        renderFriendFeed();
      }
      if ($('chatFriendReqPane') && $('chatFriendReqPane').classList.contains('open')) {
        renderFriendReqList();
      }
    } catch (e) {}
  };

  var _origOnLogin = onLogin;
  onLogin = function () {
    _origOnLogin();
    bindCommunityUi();
  };

  var _origHandlePacket = handlePacket;
  handlePacket = function (packet) {
    _origHandlePacket(packet);
    if (!packet || !packet.type) return;
    if (packet.type === 'auth.ok') {
      refreshFriendRequests(true).catch(function () {});
    } else if (packet.type === 'friend.request') {
      refreshFriendRequests(true).then(function () {
        if ($('chatFriendReqPane') && $('chatFriendReqPane').classList.contains('open')) {
          renderFriendReqList();
        }
        if (typeof showFanshubToast === 'function') {
          var fu = (packet.data && packet.data.from_user) || {};
          showFanshubToast(chatT('chat_friend_req_incoming_toast', { name: fu.nickname || ('ID' + ((packet.data && packet.data.from_user_id) || '')) }), 'info');
        }
      }).catch(function () {});
    } else if (packet.type === 'friend.accepted' || packet.type === 'friend.rejected') {
      refreshFriendRequests(true).then(function () {
        if ($('chatFriendReqPane') && $('chatFriendReqPane').classList.contains('open')) {
          renderFriendReqList();
        }
      }).catch(function () {});
      refreshList().catch(function () {});
      refreshCommunity().catch(function () {});
    }
  };

  // After auth.ok in handlePacket — enhance create-group visibility via bindCommunityUi polling
  var _origBindUi = bindUi;
  bindUi = function () {
    _origBindUi();
    bindCommunityUi();
    var homeCreate = $('chatHomeCreateGroupBtn');
    if (homeCreate) homeCreate.style.display = state.canCreateGroup ? '' : 'none';
    var addFriendBtn = $('chatAddFriendBtn');
    if (addFriendBtn) addFriendBtn.style.display = '';
  };

  if (global.FansHubChat) {
    global.FansHubChat.onTabEnter = onTabEnter;
    global.FansHubChat.onLocaleChange = onLocaleChange;
    global.FansHubChat.onLogin = onLogin;
    global.FansHubChat.addFriendByMemberId = addFriendByMemberId;
  }

/* === 06-notice.js === */
/**
 * Notice feed (Moments-style) + commission panel
 * Loaded after 05-community.js inside FansHubChat IIFE
 */

  function noticeHomeTabs() {
    return ['chat', 'community', 'notice', 'commission'];
  }

  function setHomeTab(tab) {
    if (noticeHomeTabs().indexOf(tab) < 0) tab = 'chat';
    state.homeTab = tab;
    var panels = {
      chat: 'chatHomePanelChat',
      community: 'chatHomePanelCommunity',
      notice: 'chatHomePanelNotice',
      commission: 'chatHomePanelCommission'
    };
    var tabs = {
      chat: 'chatHomeTabChat',
      community: 'chatHomeTabCommunity',
      notice: 'chatHomeTabNotice',
      commission: 'chatHomeTabCommission'
    };
    Object.keys(panels).forEach(function (key) {
      var panel = $(panels[key]);
      var on = key === tab;
      if (!panel) return;
      panel.style.display = on ? '' : 'none';
      if (on) panel.removeAttribute('hidden');
      else panel.setAttribute('hidden', 'hidden');
    });
    Object.keys(tabs).forEach(function (key) {
      var btn = $(tabs[key]);
      if (!btn) return;
      var on = key === tab;
      btn.classList.toggle('active', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    if (tab === 'community') {
      setCommunitySubTab(state.communitySubTab || 'official');
      refreshCommunity().catch(function () {});
    } else if (tab === 'notice') {
      syncPromoteEarnPanel();
      refreshNoticeFeed().catch(function () {});
    } else if (tab === 'commission') {
      refreshCommissionPanel().catch(function () {});
    }
  }

  function noticeEscape(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function noticeCategoryIcon(cat) {
    if (cat === 'rules' || cat === '规则' || cat === '玩法' || cat === '游戏规则') return '📋';
    if (cat === 'promote' || cat === '推广' || cat === '推广赚钱') return '💼';
    if (cat === 'ads' || cat === '广告' || cat === '广告发布') return '📣';
    if (cat === 'latest' || cat === '最新发布') return '🆕';
    return '📢';
  }

  function noticeCategoryLabel(item) {
    return String((item && (item.category_label || item.category)) || '');
  }

  var noticeActiveCat = 'latest';
  var promoteEarnRows = null;
  var promoteEarnTimer = null;
  var promoteEarnOffset = 0;

  function promoteEarnMaskUid(uid) {
    uid = String(uid == null ? '' : uid).replace(/\D/g, '');
    if (uid.length <= 4) return '****';
    if (uid.length <= 6) return uid.slice(0, 1) + '****' + uid.slice(-1);
    // 8 位等：隐藏中间 4 位，如 74282747 → 74****47
    var head = Math.floor((uid.length - 4) / 2);
    var tail = uid.length - 4 - head;
    return uid.slice(0, head) + '****' + uid.slice(uid.length - tail);
  }

  function promoteEarnSym() {
    return (global.FanshubI18n && typeof global.FanshubI18n.currencySymbol === 'function')
      ? global.FanshubI18n.currencySymbol()
      : '¥';
  }

  function promoteEarnTypeLabel(key) {
    if (key === 'group') return chatT('promote_earn_type_group') || '群主红包返佣';
    return chatT('promote_earn_type_share') || '分享推广';
  }

  function promoteEarnDetailLabel(key, n) {
    if (key === 'promote_earn_detail_group_fee') {
      return chatT('promote_earn_detail_group_fee') || '红包抽成返佣';
    }
    if (key === 'promote_earn_detail_groups_n') {
      return chatT('promote_earn_detail_groups_n', { n: n }) || ('自建' + n + '群红包返利');
    }
    if (key === 'promote_earn_detail_multi') {
      return chatT('promote_earn_detail_multi') || '多群互动返现';
    }
    if (key === 'promote_earn_detail_exposure') {
      return chatT('promote_earn_detail_exposure') || '推广曝光成交收益';
    }
    return chatT('promote_earn_detail_share_n', { n: n }) || ('分享链接引流' + n + '人');
  }

  function buildPromoteEarnMockRows(count) {
    count = Math.max(12, Math.min(40, count || 24));
    var shareDetails = [
      'promote_earn_detail_share_n',
      'promote_earn_detail_multi',
      'promote_earn_detail_exposure'
    ];
    var rows = [];
    for (var i = 0; i < count; i++) {
      var r = Math.random();
      var typeKey = r < 0.55 ? 'share' : 'group';
      var detailKey = typeKey === 'group'
        ? 'promote_earn_detail_group_fee'
        : shareDetails[Math.floor(Math.random() * shareDetails.length)];
      var n = 3 + Math.floor(Math.random() * 40);
      // 8 位会员 ID：10000000–99999999，避免总落在 10xxxxxx
      var uidNum = 10000000 + Math.floor(Math.random() * 90000000);
      var amt = 18 + Math.random() * 160 + (i % 7) * 3.17;
      amt = Math.round(amt * 100) / 100;
      rows.push({
        uid: String(uidNum),
        typeKey: typeKey,
        detailKey: detailKey,
        detailN: n,
        amount: amt
      });
    }
    return rows;
  }

  function stopPromoteEarnScroll() {
    if (promoteEarnTimer) {
      clearInterval(promoteEarnTimer);
      promoteEarnTimer = null;
    }
    promoteEarnOffset = 0;
    var track = $('chatPromoteEarnTrack');
    if (track) {
      track.style.transition = 'none';
      track.style.transform = 'translateY(0)';
      track.classList.remove('is-scrolling');
    }
  }

  function startPromoteEarnScroll() {
    stopPromoteEarnScroll();
    var track = $('chatPromoteEarnTrack');
    if (!track || !track.querySelector('.chat-promote-earn-row')) return;
    promoteEarnTimer = setInterval(function () {
      var row = track.querySelector('.chat-promote-earn-row');
      if (!row) return;
      var h = row.offsetHeight || 36;
      var half = Math.floor(track.scrollHeight / 2);
      if (half < h) return;
      promoteEarnOffset += h;
      track.style.transition = 'transform 0.45s ease';
      track.style.transform = 'translateY(-' + promoteEarnOffset + 'px)';
      if (promoteEarnOffset >= half - 1) {
        setTimeout(function () {
          if (!$('chatPromoteEarnTrack')) return;
          track.style.transition = 'none';
          promoteEarnOffset = 0;
          track.style.transform = 'translateY(0)';
        }, 480);
      }
    }, 3000);
  }

  function renderPromoteEarnTrack(rows) {
    var track = $('chatPromoteEarnTrack');
    if (!track) return;
    stopPromoteEarnScroll();
    var sym = promoteEarnSym();
    function amtFixed(n) {
      n = Number(n) || 0;
      return n.toFixed(2);
    }
    function rowHtml(row) {
      return ''
        + '<div class="chat-promote-earn-row" role="row">'
        +   '<div class="chat-promote-earn-td" role="cell">' + noticeEscape(promoteEarnMaskUid(row.uid)) + '</div>'
        +   '<div class="chat-promote-earn-td" role="cell">' + noticeEscape(promoteEarnTypeLabel(row.typeKey)) + '</div>'
        +   '<div class="chat-promote-earn-td is-detail" role="cell">' + noticeEscape(promoteEarnDetailLabel(row.detailKey, row.detailN)) + '</div>'
        +   '<div class="chat-promote-earn-td is-amt" role="cell">' + noticeEscape(sym + amtFixed(row.amount)) + '</div>'
        + '</div>';
    }
    var html = (rows || []).map(rowHtml).join('');
    track.innerHTML = html + html;
    if (noticeActiveCat === 'promote') startPromoteEarnScroll();
  }

  function syncPromoteEarnPanel() {
    var wrap = $('chatPromoteEarnWrap');
    var feed = $('chatNoticeFeed');
    var isPromote = noticeActiveCat === 'promote';
    if (wrap) {
      if (isPromote) {
        wrap.removeAttribute('hidden');
        if (!promoteEarnRows || !promoteEarnRows.length) {
          promoteEarnRows = buildPromoteEarnMockRows(24);
        }
        renderPromoteEarnTrack(promoteEarnRows);
      } else {
        wrap.setAttribute('hidden', 'hidden');
        stopPromoteEarnScroll();
      }
    }
    if (feed) {
      feed.style.display = '';
    }
  }

  function refreshPromoteEarnMock() {
    promoteEarnRows = buildPromoteEarnMockRows(24);
    if (noticeActiveCat === 'promote') renderPromoteEarnTrack(promoteEarnRows);
    if (typeof showFanshubToast === 'function') {
      showFanshubToast(chatT('promote_earn_refreshed') || '已刷新收益数据', 'success');
    }
  }

  function setNoticeCategory(cat, forceReload) {
    var allowed = ['latest', 'promote', 'ads', 'rules'];
    if (allowed.indexOf(cat) < 0) cat = 'latest';
    noticeActiveCat = cat;
    var wrap = $('chatNoticeCats');
    if (wrap) {
      Array.prototype.forEach.call(wrap.querySelectorAll('[data-notice-cat]'), function (btn) {
        var on = btn.getAttribute('data-notice-cat') === cat;
        btn.classList.toggle('active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
      });
    }
    syncPromoteEarnPanel();
    var box = $('chatNoticeFeed');
    if (box) {
      box._loaded = false;
      box._loadedCat = '';
    }
    refreshNoticeFeed(true).catch(function () {});
  }

  function noticeRelativeDay(ts) {
    ts = Number(ts) || 0;
    if (!ts) return '';
    var d = new Date(ts * 1000);
    var now = new Date();
    var startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    var startThat = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    var diff = Math.round((startToday - startThat) / 86400000);
    if (diff === 0) return '今天';
    if (diff === 1) return '昨天';
    if (diff > 1 && diff < 7) return diff + '天前';
    var m = d.getMonth() + 1;
    var day = d.getDate();
    return m + '月' + day + '日';
  }

  function noticePad2(n) {
    n = String(n);
    return n.length < 2 ? ('0' + n) : n;
  }

  function noticeClock(ts) {
    ts = Number(ts) || 0;
    if (!ts) return '';
    var d = new Date(ts * 1000);
    return noticePad2(d.getHours()) + ':' + noticePad2(d.getMinutes());
  }

  function renderNoticeMedia(item) {
    var html = '';
    var video = item.video || '';
    var imgs = Array.isArray(item.images) ? item.images.filter(Boolean) : [];
    if (video) {
      html += '<div class="chat-notice-media"><video class="chat-notice-video" src="' + noticeEscape(video) + '" controls playsinline preload="metadata"></video></div>';
    }
    if (imgs.length) {
      var n = Math.min(9, imgs.length);
      var cls = 'chat-notice-imgs imgs-' + n;
      html += '<div class="chat-notice-media"><div class="' + cls + '">';
      for (var i = 0; i < n; i++) {
        html += '<img class="chat-notice-img" src="' + noticeEscape(imgs[i]) + '" alt="" loading="lazy">';
      }
      html += '</div></div>';
    }
    return html;
  }

  function renderNoticeActions(item) {
    var type = String(item.action_type || '');
    var html = '';
    if (type === 'buttons' && Array.isArray(item.action_buttons) && item.action_buttons.length) {
      html += '<div class="chat-notice-actions">';
      item.action_buttons.forEach(function (btn) {
        html += '<button type="button" class="chat-notice-action-btn soft" data-notice-action="link" data-url="' + noticeEscape(btn.url || '') + '" data-label="' + noticeEscape(btn.label || '') + '">' + noticeEscape(btn.label || '') + '</button>';
      });
      html += '</div>';
      return html;
    }
    var label = String(item.action_label || '').trim();
    if (!label) return '';
    var isShare = type === 'share';
    var cls = isShare ? 'chat-notice-action-btn primary' : 'chat-notice-action-btn wide-soft';
    var act = isShare ? 'share' : 'link';
    html += '<div class="chat-notice-actions">';
    html += '<button type="button" class="' + cls + '" data-notice-action="' + act + '" data-url="' + noticeEscape(item.action_url || '') + '" data-label="' + noticeEscape(label) + '">' + noticeEscape(label) + '</button>';
    html += '</div>';
    return html;
  }

  function renderNoticeCard(item) {
    var cat = String(item.category || '');
    var catLabel = noticeCategoryLabel(item);
    var avatar = item.author_avatar ? avatarSrc(item.author_avatar) : '';
    var avatarHtml = avatar
      ? '<img class="chat-notice-avatar" src="' + noticeEscape(avatar) + '" alt="">'
      : '<div class="chat-notice-avatar chat-notice-avatar-fallback" aria-hidden="true">' + noticeCategoryIcon(cat) + '</div>';
    var sharePayload = encodeURIComponent(JSON.stringify({
      id: item.id,
      content: item.content || '',
      category: catLabel || cat
    }));
    return ''
      + '<article class="chat-notice-card" data-notice-id="' + noticeEscape(item.id) + '">'
      +   '<div class="chat-notice-hd">'
      +     avatarHtml
      +     '<div class="chat-notice-meta">'
      +       '<div class="chat-notice-name-row">'
      +         '<span class="chat-notice-name">' + noticeEscape(item.author_name || '红宝官方公告') + '</span>'
      +         '<span class="chat-notice-day">' + noticeEscape(noticeRelativeDay(item.publishtime)) + '</span>'
      +         (catLabel ? '<span class="chat-notice-tag">【' + noticeEscape(catLabel) + '】</span>' : '')
      +       '</div>'
      +     '</div>'
      +     '<div class="chat-notice-time">' + noticeEscape(noticeClock(item.publishtime)) + '</div>'
      +   '</div>'
      +   '<div class="chat-notice-body">' + noticeEscape(item.content || '') + '</div>'
      +   renderNoticeMedia(item)
      +   renderNoticeActions(item)
      +   '<div class="chat-notice-ft">'
      +     '<button type="button" class="chat-notice-share-btn" data-notice-share="' + sharePayload + '">分享到社群</button>'
      +   '</div>'
      + '</article>';
  }

  async function refreshNoticeFeed(force) {
    var box = $('chatNoticeFeed');
    if (!box) return;
    if (!force && box._loaded && box.querySelector('.chat-notice-card') && box._loadedCat === noticeActiveCat) return;
    box.innerHTML = '<div class="chat-empty chat-empty-glass">' + noticeEscape(chatT('chat_notice_loading')) + '</div>';
    try {
      if (typeof global.apiRequest !== 'function') throw new Error('api unavailable');
      var data = await global.apiRequest('notices', 'GET', { page: 1, limit: 30, category: noticeActiveCat });
      if (data && Array.isArray(data.categories) && data.categories.length) {
        syncNoticeCatLabels(data.categories);
      }
      var list = (data && data.list) || [];
      if (!list.length) {
        box.innerHTML = '<div class="chat-empty chat-empty-glass">' + noticeEscape(chatT('chat_notice_empty_retry')) + '</div>';
        box._loaded = true;
        box._loadedCat = noticeActiveCat;
        return;
      }
      box.innerHTML = list.map(renderNoticeCard).join('');
      box._loaded = true;
      box._loadedCat = noticeActiveCat;
      bindNoticeFeedEvents(box);
    } catch (e) {
      box.innerHTML = '<div class="chat-empty chat-empty-glass">' + noticeEscape(e.message || '加载失败') + '</div>';
    }
  }

  function syncNoticeCatLabels(categories) {
    var wrap = $('chatNoticeCats');
    if (!wrap || !categories || !categories.length) return;
    // 只更新文案，绝不重建/清空分类栏（避免切换后分类消失）
    categories.forEach(function (c) {
      if (!c || !c.code) return;
      var btn = wrap.querySelector('[data-notice-cat="' + String(c.code).replace(/"/g, '') + '"]');
      if (!btn) return;
      var label = String(c.label || '').trim();
      if (!label) return;
      btn.textContent = label;
      btn.setAttribute('title', label);
    });
  }

  function bindNoticeFeedEvents(box) {
    if (!box || box._noticeBound) return;
    box._noticeBound = true;
    box.addEventListener('click', function (ev) {
      var shareBtn = ev.target.closest('[data-notice-share]');
      if (shareBtn) {
        var raw = shareBtn.getAttribute('data-notice-share') || '';
        var payload = {};
        try { payload = JSON.parse(decodeURIComponent(raw)); } catch (e) {}
        shareNoticeToCommunity(payload).catch(function () {});
        return;
      }
      var actBtn = ev.target.closest('[data-notice-action]');
      if (actBtn) {
        handleNoticeAction(actBtn.getAttribute('data-notice-action'), actBtn.getAttribute('data-url'), actBtn.getAttribute('data-label'));
      }
    });
  }

  function handleNoticeAction(action, url, label) {
    action = String(action || '');
    url = String(url || '').trim();
    label = String(label || '');
    if (action === 'share' || /邀请|推广|佣金/.test(label)) {
      setHomeTab('commission');
      return;
    }
    if (/收益|佣金/.test(label)) {
      setHomeTab('commission');
      return;
    }
    if (/红包|接力/.test(label)) {
      setHomeTab('community');
      return;
    }
    if (url) {
      if (/^https?:\/\//i.test(url)) {
        window.open(url, '_blank');
      } else if (url.charAt(0) === '#') {
        location.hash = url;
      } else {
        location.href = url;
      }
    }
  }

  async function shareNoticeToCommunity(payload) {
    var text = String((payload && payload.content) || '').trim();
    var cat = (payload && payload.category) ? ('【' + payload.category + '】') : '';
    var shareText = cat + (text ? text.slice(0, 120) : '红宝官方公告');
    try {
      if (typeof global.apiRequest === 'function' && token()) {
        var share = await global.apiRequest('share', 'POST', { copy_only: 1 });
        if (share && share.share_text) {
          shareText = shareText + '\n' + share.share_text;
        } else if (share && share.share_link) {
          shareText = shareText + '\n' + share.share_link;
        }
      }
    } catch (e) {}
    try {
      if (navigator.share) {
        await navigator.share({ title: '红宝公告', text: shareText });
        if (typeof showFanshubToast === 'function') showFanshubToast('已唤起分享', 'success');
        return;
      }
    } catch (e) {}
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(shareText);
      } else {
        var ta = document.createElement('textarea');
        ta.value = shareText;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }
      if (typeof showFanshubToast === 'function') showFanshubToast('已复制，可粘贴到社群', 'success');
    } catch (e) {
      if (typeof showFanshubToast === 'function') showFanshubToast('分享失败', 'error');
    }
  }

  function formatMoneyYuan(n) {
    var v = Number(n);
    if (!isFinite(v)) v = 0;
    var abs = Math.abs(v).toFixed(2);
    var parts = abs.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return '¥ ' + (v < 0 ? '-' : '') + parts.join('.');
  }

  function formatLedgerAmt(row) {
    var h = Number(row.hongbao_change || 0);
    var b = Number(row.balance_change || 0);
    var r = Number(row.rights_change || 0);
    // 旧流水 balance_change 视为红宝
    var money = h || b || r;
    if (!money) return '¥ 0.00';
    var abs = Math.abs(money).toFixed(2);
    var prefix = money > 0 ? '+¥ ' : '-¥ ';
    return prefix + abs;
  }

  function commissionRowTitle(row) {
    row = row || {};
    if (row.type_label) return String(row.type_label);
    var rt = String(row.revenue_type || '');
    if (rt === 'dual') return '🔥 群主+推荐双重返佣';
    if (rt === 'invite') return '🔗 推荐发包返佣';
    if (rt === 'owner') return '👥 群聊管理津贴';
    var t = String(row.type || '');
    if (t === 'red_packet_dual_rebate_in') return '🔥 群主+推荐双重返佣';
    if (t === 'red_packet_invite_rebate_in' || t === 'red_packet_rebate') return '🔗 推荐发包返佣';
    if (t === 'red_packet_agent_rebate_in') return '👥 群聊管理津贴';
    return t || '结算';
  }

  function renderCommissionRows(list, emptyText) {
    var listEl = $('chatCommissionList');
    if (!listEl) return;
    if (!list || !list.length) {
      listEl.innerHTML = '<div class="chat-empty chat-empty-glass">' + noticeEscape(emptyText || '暂无记录') + '</div>';
      return;
    }
    listEl.innerHTML = list.map(function (row) {
      var amt = formatLedgerAmt(row);
      var out = amt.indexOf('-') === 0;
      var title = commissionRowTitle(row);
      var dual = String(row.revenue_type || '') === 'dual' || String(row.type || '') === 'red_packet_dual_rebate_in';
      return ''
        + '<div class="chat-commission-row' + (dual ? ' is-dual-rebate' : '') + '">'
        +   '<div class="chat-commission-row-ico" aria-hidden="true">'
        +     '<svg viewBox="0 0 24 24" width="14" height="14"><path fill="currentColor" d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>'
        +   '</div>'
        +   '<div class="chat-commission-row-main">'
        +     '<div class="chat-commission-row-title">' + noticeEscape(title) + '</div>'
        +     '<div class="chat-commission-row-time">' + noticeEscape(noticeRelativeDay(row.createtime) + ' ' + noticeClock(row.createtime)) + '</div>'
        +   '</div>'
        +   '<div class="chat-commission-row-amt' + (out ? ' is-out' : '') + '">' + noticeEscape(amt) + '</div>'
        + '</div>';
    }).join('');
  }

  function setCommissionListMode(mode) {
    state.commissionListMode = mode || 'recent';
    var data = state.commissionData || {};
    var title = $('chatCommissionListTitle');
    if (mode === 'promo') {
      if (title) title.textContent = chatT('chat_commission_nav_promo');
      renderCommissionRows(data.promo_recent, chatT('chat_commission_empty_promo'));
    } else if (mode === 'rebate') {
      if (title) title.textContent = chatT('chat_commission_nav_rebate');
      renderCommissionRows(data.rebate_recent, chatT('chat_commission_empty_rebate'));
    } else if (mode === 'withdraw_list') {
      if (title) title.textContent = chatT('chat_commission_nav_withdraw');
      renderCommissionRows(data.withdraw_recent, chatT('chat_commission_empty_withdraw'));
    } else if (mode === 'ledger' || mode === 'recent') {
      if (title) {
        title.textContent = mode === 'ledger'
          ? chatT('chat_commission_nav_ledger')
          : chatT('chat_commission_recent');
      }
      renderCommissionRows(data.recent, chatT('chat_commission_empty_recent'));
    } else {
      if (title) title.textContent = chatT('chat_commission_recent');
      renderCommissionRows(data.recent, chatT('chat_commission_empty_recent'));
    }
  }

  function openCommissionWallet(which) {
    if (typeof global.openProfileSubPage === 'function') {
      global.openProfileSubPage(which);
      return;
    }
    if (window.FansHubAssets && typeof FansHubAssets.ensureWallet === 'function') {
      FansHubAssets.ensureWallet().then(function () {
        if (typeof global.openProfileWalletPage === 'function') global.openProfileWalletPage(which);
      }).catch(function () {
        if (typeof showFanshubToast === 'function') showFanshubToast(chatT('wallet_module_fail'), 'error');
      });
      return;
    }
    if (typeof global.openProfileWalletPage === 'function') {
      global.openProfileWalletPage(which);
      return;
    }
    if (typeof showFanshubToast === 'function') showFanshubToast(chatT('wallet_open_profile_hint'), 'info');
  }

  async function refreshCommissionPanel(force) {
    var listEl = $('chatCommissionList');
    if (!listEl) return;
    if (!token()) {
      if ($('chatCommissionTotal')) $('chatCommissionTotal').textContent = '¥ 0.00';
      if ($('chatCommissionWithdrawable')) $('chatCommissionWithdrawable').textContent = '¥ 0.00';
      if ($('chatCommissionToday')) $('chatCommissionToday').textContent = '¥ 0.00';
      if ($('chatCommissionRebate')) $('chatCommissionRebate').textContent = '¥ 0.00';
      listEl.innerHTML = '<div class="chat-empty chat-empty-glass">' + noticeEscape(chatT('chat_commission_login_hint')) + '</div>';
      return;
    }
    if (!force && listEl._loaded && listEl._loadedAt && (Date.now() - listEl._loadedAt) < 45000) {
      setCommissionListMode(state.commissionListMode || 'recent');
      return;
    }
    if (!force && listEl._loaded) return;
    listEl.innerHTML = '<div class="chat-empty chat-empty-glass">' + noticeEscape(chatT('chat_notice_loading')) + '</div>';
    try {
      var data = await global.apiRequest('commission', 'GET', {});
      state.commissionData = data || {};
      if ($('chatCommissionTotal')) $('chatCommissionTotal').textContent = formatMoneyYuan(data.total_money);
      if ($('chatCommissionWithdrawable')) $('chatCommissionWithdrawable').textContent = formatMoneyYuan(data.withdrawable);
      if ($('chatCommissionToday')) $('chatCommissionToday').textContent = formatMoneyYuan(data.today_money);
      if ($('chatCommissionRebate')) $('chatCommissionRebate').textContent = formatMoneyYuan(data.rebate_money);
      setCommissionListMode(state.commissionListMode || 'recent');
      listEl._loaded = true;
      listEl._loadedAt = Date.now();
    } catch (e) {
      listEl.innerHTML = '<div class="chat-empty chat-empty-glass">' + noticeEscape(e.message || '加载失败') + '</div>';
    }
  }

  function bindNoticeCommissionUi() {
    var tabNotice = $('chatHomeTabNotice');
    if (tabNotice && !tabNotice._bound) {
      tabNotice._bound = true;
      tabNotice.onclick = function () { setHomeTab('notice'); };
    }
    var cats = $('chatNoticeCats');
    if (cats && !cats._bound) {
      cats._bound = true;
      cats.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-notice-cat]');
        if (!btn) return;
        setNoticeCategory(btn.getAttribute('data-notice-cat') || 'latest', true);
      });
    }
    var liveBtn = $('chatPromoteEarnLiveBtn');
    if (liveBtn && !liveBtn._bound) {
      liveBtn._bound = true;
      liveBtn.onclick = function () { refreshPromoteEarnMock(); };
    }
    var tabCommission = $('chatHomeTabCommission');
    if (tabCommission && !tabCommission._bound) {
      tabCommission._bound = true;
      tabCommission.onclick = function () { setHomeTab('commission'); };
    }
    var withdrawBtn = $('chatCommissionWithdrawBtn');
    if (withdrawBtn && !withdrawBtn._bound) {
      withdrawBtn._bound = true;
      withdrawBtn.onclick = function () { openCommissionWallet('withdraw'); };
    }
    var nav = $('chatCommissionNav');
    if (nav && !nav._bound) {
      nav._bound = true;
      nav.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-commission-nav]');
        if (!btn) return;
        var kind = btn.getAttribute('data-commission-nav') || '';
        // 四宫格全部留在佣金页切换下方列表，避免打开钱包全屏页把底栏藏掉
        if (kind === 'promo' || kind === 'rebate' || kind === 'withdraw_list' || kind === 'ledger') {
          setCommissionListMode(kind === 'ledger' ? 'ledger' : kind);
          nav.querySelectorAll('[data-commission-nav]').forEach(function (el) {
            el.classList.toggle('is-active', el === btn);
          });
        }
      });
    }
  }

  var _origOnTabEnterNotice = onTabEnter;
  onTabEnter = function () {
    _origOnTabEnterNotice();
    bindNoticeCommissionUi();
    if (state.homeTab === 'notice') {
      syncPromoteEarnPanel();
      refreshNoticeFeed().catch(function () {});
    }
    if (state.homeTab === 'commission') {
      var listEl = $('chatCommissionList');
      var stale = !listEl || !listEl._loadedAt || (Date.now() - listEl._loadedAt) > 45000;
      refreshCommissionPanel(stale).catch(function () {});
    }
  };

  var _origOnLocaleNotice = onLocaleChange;
  onLocaleChange = function (opts) {
    _origOnLocaleNotice(opts);
    if (noticeActiveCat === 'promote') {
      if (promoteEarnRows && promoteEarnRows.length) {
        renderPromoteEarnTrack(promoteEarnRows);
      } else {
        syncPromoteEarnPanel();
      }
    }
  };

  var _origOnLoginNotice = onLogin;
  onLogin = function () {
    _origOnLoginNotice();
    bindNoticeCommissionUi();
    if (state.homeTab === 'commission') {
      var listEl = $('chatCommissionList');
      if (listEl) listEl._loaded = false;
      refreshCommissionPanel(true).catch(function () {});
    }
  };

  var _origBindUiNotice = bindUi;
  bindUi = function () {
    _origBindUiNotice();
    bindNoticeCommissionUi();
  };

  bindNoticeCommissionUi();
  if (global.FansHubChat) {
    global.FansHubChat.onTabEnter = onTabEnter;
    global.FansHubChat.onLogin = onLogin;
    global.FansHubChat.onLocaleChange = onLocaleChange;
  }

})(window);
