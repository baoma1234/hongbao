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
    if (nick) cacheSender(uid, { user_id: uid, nickname: nick, avatar: av || '' });
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
      var nick = String(found.nickname || found.username || '').trim() || '群友';
      var brief = { user_id: userId, nickname: nick, avatar: found.avatar || '' };
      cacheSender(userId, brief);
      return brief;
    }
    return { user_id: userId, nickname: '群友', avatar: '' };
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
        escapeHtml(brief.nickname || '群友') + '</div>';
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
          var nick = r.nickname || '群友';
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
      return groupMessageWrap(mine, msg.from_user_id, renderRpCardHtml(extra, msg, formatTimeSec(msg.createtime)), actions, msg.id | 0);
    }
    if (type === 8) {
      var tfExtra = parseExtra(msg);
      return groupMessageWrap(mine, msg.from_user_id, renderTransferCardHtml(tfExtra, msg, time), actions, msg.id | 0);
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
    // 文字：内容 + 空格 + 时间（时间贴在最后一行末尾，略小）；纯表情仍单独一行时间
    var bubbleCls = 'chat-bubble' + (emojiOnly ? ' emoji-only' : ' text-msg');
    var timeHtml = emojiOnly
      ? ('<span class="meta">' + time + '</span>')
      : (' <span class="meta">' + time + '</span>');
    return groupMessageWrap(mine, msg.from_user_id,
      '<div class="' + bubbleCls + '">' + escapeHtml(text) + timeHtml + '</div>', actions, msg.id | 0);
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
    if (canRpSend) {
      parts.push('发红包');
      parts.push('抢红包');
    }
    if (canSendCapability('image')) parts.push('发图片');
    if (canSendCapability('video')) parts.push('发视频');
    if (canSendCapability('emoji')) parts.push('发表情');
    if (!parts.length) return '本群禁止发言，仅管理员可发言';
    return '仅可进行' + parts.join('、') + '操作';
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
