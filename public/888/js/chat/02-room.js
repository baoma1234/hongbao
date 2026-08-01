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
    if (!sheet) return;
    var title = item.title || (item.conversation_type === 2 ? ('群 ' + (item.group_id || item.conversation_id)) : '会话');
    if (titleEl) titleEl.textContent = title;
    if (pinBtn) pinBtn.style.display = item.pinned ? 'none' : '';
    if (unpinBtn) unpinBtn.style.display = item.pinned ? '' : 'none';
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
    return (
      '<button type="button" class="chat-conv-item' + (item.is_im_admin ? ' is-admin' : '') + (item.pinned ? ' is-pinned' : '') + '" data-type="' + type + '" data-id="' + escapeHtml(String(id)) + '" data-peer="' + (item.peer_user_id | 0) + '" data-title="' + title + '" data-pinned="' + (item.pinned ? '1' : '0') + '">' +
        '<div class="chat-avatar' + (type === 2 ? ' group' : '') + (item.is_im_admin ? ' admin' : '') + '">' + avatarHtml + '</div>' +
        '<div class="chat-conv-body">' +
          '<div class="chat-conv-title"><span>' + pinMark + title + adminBadge + '</span><span class="chat-conv-time">' + time + '</span></div>' +
          '<div class="chat-conv-preview">' + prev + '</div>' +
        '</div>' +
        (unread ? '<span class="chat-badge">' + (unread > 99 ? '99+' : unread) + '</span>' : '') +
      '</button>'
    );
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
    if (!canRecallMessage(msg)) return '';
    var isPrivate = ((state.room && state.room.type) | 0) === 1
      || (msg.conversation_type | 0) === 1;
    if (isPrivate) {
      return '<button type="button" class="chat-msg-recall chat-msg-delete" data-id="' + (msg.id | 0) + '">删除</button>';
    }
    return '<button type="button" class="chat-msg-recall" data-id="' + (msg.id | 0) + '">撤回</button>';
  }

  function groupPolicy() {
    return (state.groupMeta && state.groupMeta.policy) || {};
  }

  function mergeGroupMeta(data) {
    data = data || {};
    var prev = state.groupMeta || {};
    state.groupMeta = {
      group: data.group || prev.group || null,
      my_role: (data.my_role != null ? data.my_role : prev.my_role) | 0,
      mute_all: data.mute_all != null ? !!data.mute_all : !!prev.mute_all,
      member_count: (data.member_count != null ? data.member_count : prev.member_count) | 0,
      member_list_hidden: data.member_list_hidden != null ? !!data.member_list_hidden : !!prev.member_list_hidden,
      can_speak: data.can_speak !== false,
      policy: data.policy || prev.policy || {}
    };
    if (state.groupMeta.policy && state.groupMeta.policy.member_list_hidden != null) {
      state.groupMeta.member_list_hidden = !!state.groupMeta.policy.member_list_hidden;
    }
    return state.groupMeta;
  }

  function cacheSender(userId, info) {
    userId = userId | 0;
    if (userId > 0 && info) state.senderCache[userId] = info;
  }

  function getSenderBrief(userId) {
    userId = userId | 0;
    if (state.senderCache[userId]) return state.senderCache[userId];
    var found = null;
    (state.members || []).some(function (m) {
      if ((m.user_id | 0) === userId) { found = m; return true; }
      return false;
    });
    if (found) {
      var brief = { user_id: userId, nickname: found.nickname, avatar: found.avatar };
      cacheSender(userId, brief);
      return brief;
    }
    return { user_id: userId, nickname: 'ID' + userId, avatar: '' };
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
            '<div class="chat-hero-title">红包详情</div><span class="chat-hero-spacer"></span></div>' +
          '<div class="chat-sub-main">' +
            '<div class="chat-rp-detail-head" id="chatRpDetailHead"></div>' +
            '<div class="chat-rp-detail-list" id="chatRpDetailList"></div>' +
            '<button type="button" class="chat-rp-detail-grab-btn" id="chatRpDetailGrabBtn" style="display:none">开红包</button>' +
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

  var RP_ICON_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4V6h16v12z"/><circle cx="12" cy="12" r="3.5"/><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zm0 8c-1.65 0-3-1.35-3-3s1.35-3 3-3 3 1.35 3 3-1.35 3-3 3z"/></svg>';
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
    var desc = '';
    if (!isNaN(amt) && amt > 0) {
      desc = amt.toFixed(2) + ' 元' + (cnt > 0 ? (' / ' + cnt + '个包') : '');
    } else {
      desc = '红包';
    }
    var sendTime = time || formatTimeSec((msg && msg.createtime) || 0);
    var timeHtml = sendTime
      ? ('<div class="rp-time">' + escapeHtml(sendTime) + '</div>')
      : '';
    var bottom = '红包福利';
    if (ptype === 3) bottom = pending ? '埋雷红包 · 匹配证明中' : '埋雷红包';
    else if (ptype === 2) bottom = '拼手气红包';
    else if (ptype === 1) bottom = '人均红包';
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
    return (
      '<button type="button" class="' + cls + '" data-packet="' + (pid | 0) + '">' +
        '<div class="rp-top">' +
          '<div class="rp-icon-box">' + RP_ICON_SVG + '</div>' +
          '<div class="rp-info">' +
            '<div class="rp-title">' + bless + '</div>' +
            '<div class="rp-desc">' + escapeHtml(desc) + '</div>' +
            timeHtml +
          '</div>' +
          mineBadge +
        '</div>' +
        '<div class="rp-bottom">' + bottom + '</div>' +
      '</button>'
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

  function groupMessageWrap(mine, fromUserId, innerHtml, actions, msgId) {
    msgId = msgId | 0;
    var midAttr = msgId ? (' data-mid="' + msgId + '"') : '';
    var isGroup = state.room && state.room.type === 2;
    var uid = mine ? (state.userId | 0) : (fromUserId | 0);
    // 已关闭：点击头像/昵称查看资料
    var clickable = false;
    var nickHtml = '';
    if (isGroup && !mine) {
      var brief = getSenderBrief(fromUserId);
      nickHtml = '<div class="chat-msg-nick locked" data-uid="' + (fromUserId | 0) + '">' +
        escapeHtml(brief.nickname || ('ID' + fromUserId)) + '</div>';
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

  function applyRedPacketDetailData(packetId, data) {
    data = data || {};
    var head = $('chatRpDetailHead');
    var list = $('chatRpDetailList');
    var grabBtn = $('chatRpDetailGrabBtn');
    var p = data.packet || {};
    var bless = p.blessing || '恭喜发财';
    var locked = data.rp_detail_locked === true || data.profile_clickable === false;
    var privacyMode = (data.privacy_mode || (data.policy && data.policy.privacy_mode) || '').toString();
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
        var pno = encodeURIComponent(p.packet_no || '');
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
        fairBits += '<a class="chat-rp-fair-link" href="fair-verify.html?packet_no=' + pno + '" target="_blank" rel="noopener">本站验证详情</a>';
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
    }
    if (list) {
      list.classList.toggle('is-private', locked);
      list.classList.toggle('is-open', !locked);
      var rows = data.records || [];
      var ptype2 = (p.packet_type | 0);
      var settled = (p.status | 0) === 5;
      if (ptype2 === 3 && settled && head) {
        var hitN = rows.filter(function (r) { return (r.is_mine_hit | 0) === 1; }).length;
        var sumCls = hitN > 0 ? 'chat-rp-mine-summary' : 'chat-rp-mine-summary is-safe';
        var sumText = hitN > 0 ? ('本局中雷 ' + hitN + ' 人') : '本局无人中雷';
        head.insertAdjacentHTML('beforeend', '<div class="' + sumCls + '">' + sumText + '</div>');
      }
      if (!rows.length) {
        list.innerHTML = '<div class="chat-empty">' + escapeHtml(chatT('chat_no_claims')) + '</div>';
      } else {
        list.innerHTML = rows.map(function (r) {
          var uid = r.user_id | 0;
          var isSelf = uid === (state.userId | 0);
          var gray = locked && !isSelf;
          var nick = r.nickname || ('ID' + uid);
          var avInner;
          if (!gray && r.avatar) {
            avInner = '<img src="' + escapeHtml(encodeUriPath(r.avatar)) + '" alt="">';
          } else if (!gray) {
            avInner = avatarImgHtml('');
          } else {
            avInner = escapeHtml((nick || 'U').charAt(0));
          }
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
          try { renderMessages(true); } catch (eCover) {}
        } else if ((p.packet_type | 0) === 3 && p.mine_digit != null) {
          markRpCover(packetId, { mine_digit: p.mine_digit | 0 });
          try { renderMessages(true); } catch (eMine) {}
        }
      }, 0);
      grabBtn.style.display = (!grabbed && !finished && !expired) ? '' : 'none';
      grabBtn.setAttribute('data-packet', String(packetId));
    }
  }

  async function openRedPacketDetail(packetId) {
    packetId = packetId | 0;
    if (!packetId) return;
    ensureChatOverlays();
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
    try {
      var packet = await send('redpacket.detail', { packet_id: packetId });
      if (state._rpDetailPacketId !== packetId) return;
      applyRedPacketDetailData(packetId, packet.data || {});
    } catch (e) {
      if (state._rpDetailPacketId !== packetId) return;
      if (head) {
        head.innerHTML = '<div class="chat-rp-detail-bless">红包</div>' +
          '<div class="chat-rp-detail-meta">加载失败</div>';
      }
      if (list) {
        list.innerHTML = '<div class="chat-empty">' + escapeHtml((e && e.message) || '加载失败') + '</div>';
      }
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '加载失败', 'error');
    }
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
    var mine = (msg.from_user_id | 0) === state.userId;
    var time = formatTime(msg.createtime);
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
      return groupMessageWrap(mine, msg.from_user_id, renderRpCardHtml(extra, msg, formatTimeSec(msg.createtime)), actions, msg.id | 0);
    }
    if (type === 4) {
      var imgExtra = parseExtra(msg);
      var imgUrl = mediaUrl(imgExtra);
      var imgHtml = imgUrl
        ? '<img class="chat-media-img" src="' + escapeHtml(imgUrl) + '" alt="图片" data-preview="' + escapeHtml(imgUrl) + '" data-preview-type="image">'
        : escapeHtml(msg.content || '[图片]');
      return groupMessageWrap(mine, msg.from_user_id,
        '<div class="chat-bubble media">' + imgHtml + '<span class="meta">' + time + '</span></div>', actions, msg.id | 0);
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
        '<div class="chat-bubble media">' + vidHtml + '<span class="meta">' + time + '</span></div>', actions, msg.id | 0);
    }
    if (type === 6) {
      var stExtra = parseExtra(msg);
      var stUrl = mediaUrl(stExtra);
      var stCode = stExtra.code || '';
      var stHtml = stUrl
        ? '<img class="chat-sticker-img" src="' + escapeHtml(stUrl) + '" alt="' + escapeHtml(stCode || '表情') + '" data-preview="' + escapeHtml(stUrl) + '" data-preview-type="image">'
        : escapeHtml(msg.content || '[表情]');
      return groupMessageWrap(mine, msg.from_user_id,
        '<div class="chat-bubble sticker">' + stHtml + '<span class="meta">' + time + '</span></div>', actions, msg.id | 0);
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
        '<div class="chat-bubble media">' + fileHtml + '<span class="meta">' + time + '</span></div>', actions, msg.id | 0);
    }
    if (type === 1) {
      var recovered = resolveStickerFromContent(msg.content || '');
      if (recovered) {
        var rUrl = mediaUrl(recovered);
        var rHtml = rUrl
          ? '<img class="chat-sticker-img" src="' + escapeHtml(rUrl) + '" alt="' + escapeHtml(recovered.code || '表情') + '" data-preview="' + escapeHtml(rUrl) + '" data-preview-type="image">'
          : escapeHtml(msg.content || '');
        return groupMessageWrap(mine, msg.from_user_id,
          '<div class="chat-bubble sticker">' + rHtml + '<span class="meta">' + time + '</span></div>', actions, msg.id | 0);
      }
    }
    var text = msg.content || '';
    var emojiOnly = isEmojiOnlyText(text);
    return groupMessageWrap(mine, msg.from_user_id,
      '<div class="chat-bubble' + (emojiOnly ? ' emoji-only' : '') + '">' + escapeHtml(text) +
        '<span class="meta">' + time + '</span></div>', actions, msg.id | 0);
  }

  function renderMessages(skipScroll) {
    var box = $('chatMsgScroll');
    if (!box) return;
    if (!state.messages.length) {
      box.innerHTML = '<div class="chat-empty">' + escapeHtml(chatT('chat_no_messages')) + '</div>';
      return;
    }
    var lastTs = 0;
    box.innerHTML = state.messages.map(function (msg) {
      var time = formatTime(msg.createtime);
      var ts = msg.createtime | 0;
      var timeSep = '';
      if (!lastTs || Math.abs(ts - lastTs) >= 300) {
        timeSep = sysTimeHtml(time);
      }
      lastTs = ts || lastTs;
      return timeSep + buildMessageRowHtml(msg);
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
        var prev = state.messages[state.messages.length - 2];
        var html = '';
        var time = formatTime(msg.createtime);
        var ts = msg.createtime | 0;
        var lastTs = prev ? (prev.createtime | 0) : 0;
        if (!lastTs || Math.abs(ts - lastTs) >= 300) {
          html += sysTimeHtml(time);
        }
        html += buildMessageRowHtml(msg);
        box.insertAdjacentHTML('beforeend', html);
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
    var isRp = (msg.msg_type | 0) === 2;
    var prev = '';
    try { prev = previewText(msg) || ''; } catch (e0) { prev = msg.content || ''; }
    var tip = isRp ? ('🧧 ' + (prev || '收到红包')) : ('💬 ' + (prev || '新消息'));
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

  async function openRoom(opts) {
    closeComposerPanels();
    closeGroupSubPanes();
    ensureStickersLoaded(false);
    state.room = {
      type: opts.type | 0,
      id: opts.id,
      peer: opts.peer | 0,
      title: opts.title || ''
    };
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
    // 先用本地历史秒开对话框，再拉最新
    var cachedHist = loadHistCache(state.room.type, state.room.id);
    var cacheAge = (cachedHist && cachedHist.at) ? (Date.now() - (cachedHist.at | 0)) : 1e15;
    if (cachedHist && cachedHist.messages && cachedHist.messages.length) {
      state.messages = cachedHist.messages;
      if (state.room.type === 2 && cachedHist.groupMeta) {
        state.groupMeta = cachedHist.groupMeta;
        applySpeakState(state.groupMeta);
        applyGroupRoomHeader(state.groupMeta);
        updateComposerPolicy();
      }
      renderMessages(true);
      scrollRoomOnOpen(openLastRead, openUnread);
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
    var applyHistoryPacket = function (packet) {
      if (!state.room || state.room.type !== (opts.type | 0) || String(state.room.id) !== String(opts.id)) {
        return;
      }
      state.messages = (packet.data && packet.data.list) || [];
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
      if (typeof showFanshubToast === 'function' && !(cachedHist && cachedHist.messages && cachedHist.messages.length)) {
        showFanshubToast(e.message || '加载失败', 'error');
      }
    }
  }

  function closeRoom() {
    closeRpSendPage();
    closeComposerPanels();
    closeMediaLightbox();
    closeGroupSubPanes();
    closeMemberSheets();
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
        ? (placeholder || '全员禁言中，仅管理员可发言')
        : '输入消息…';
    }
    if (sendBtn) sendBtn.disabled = !!muted;
  }

  function applySpeakState(meta) {
    if (!state.room || state.room.type !== 2) {
      setComposerMuted(false, '');
      return;
    }
    var canSpeak = !(meta && meta.can_speak === false);
    if (!canSpeak) {
      var tip = (meta && meta.mute_all) ? '全员禁言中，仅管理员可发言' : '你已被禁言，暂时无法发言';
      setComposerMuted(true, tip);
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
    if (rpBtn && state.room && state.room.type === 2) {
      rpBtn.style.display = policy.can_send_rp === false ? 'none' : '';
    }
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
    el.classList.remove('open');
    el.setAttribute('aria-hidden', 'true');
  }

  function openCreateGroupPane() {
    var pane = $('chatCreateGroupPane');
    if (!pane) return;
    state.createGroup.privacy = 'private';
    state.createGroup.chatMode = 'chat';
    state.createGroup.submitting = false;
    var nameInput = $('chatCreateGroupName');
    if (nameInput) nameInput.value = '';
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
        chat_mode: state.createGroup.chatMode || 'chat'
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
    if (noticeEl) {
      noticeEl.textContent = canEdit ? '' : (g.notice ? ('群公告：' + g.notice) : '暂无群公告');
      noticeEl.style.display = canEdit ? 'none' : '';
    }
    if (muteRow) muteRow.style.display = canEdit ? '' : 'none';
    if (muteSwitch) {
      muteSwitch.checked = !!meta.mute_all;
      muteSwitch.disabled = !canEdit;
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
        state.groupMeta = state.groupMeta || {};
        state.groupMeta.group = packet.data.group || state.groupMeta.group;
        state.groupMeta.mute_all = !!packet.data.mute_all;
        state.groupMeta.can_speak = packet.data.can_speak !== false;
        applySpeakState(state.groupMeta);
        renderGroupSettings();
      }
    } catch (e) {
      var sw = $('chatMuteAllSwitch');
      if (sw) sw.checked = !enabled;
      if (typeof showFanshubToast === 'function') showFanshubToast(e.message || '操作失败', 'error');
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
        || msg.indexOf('余额不足以') === 0) {
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
        balance_below_mine_min: '余额须大于本群最低金额限制，才能领取扫雷红包',
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
      try { renderMessages(); } catch (eRender) {}
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
