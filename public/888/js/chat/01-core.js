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
    senderCache: {},
    profileTarget: null,
    createGroup: {
      privacy: 'private',
      chatMode: 'chat',
      avatarEmoji: '🐵',
      submitting: false
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
    var host = location.hostname || '127.0.0.1';
    var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    return proto + '//' + host + ':7272';
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
    if (!t || t.length > 8) return false;
    return !/[\u0000-\u007F]/.test(t);
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

  function previewText(msg) {
    if (!msg) return '暂无消息';
    if ((msg.status | 0) === 2) return '[已撤回]';
    var type = msg.msg_type | 0;
    if (type === 2) return '[红包] ' + (msg.content || '').replace(/^\[红包\]/, '');
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
      var el = document.getElementById('myUserBalance');
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

  function send(type, data) {
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
      }, 15000);
    });
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
    renderList();
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
    renderList();
  }
