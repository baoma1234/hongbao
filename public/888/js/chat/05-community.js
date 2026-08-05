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

  /* 完整四 Tab（聊天/社群/公告/佣金）由 06-notice.js 的 setHomeTab 负责 */

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
        openRoom({
          type: 1,
          id: a + '_' + b,
          peer: pid,
          title: (f && f.nickname) || ('ID ' + pid),
          peer_nickname: (f && f.peer_nickname) || '',
          remark: (f && f.remark) || ''
        });
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
      var u = packet.data.user || {};
      var peerNick = String(u.nickname || '').trim() || ('ID' + (u.user_id | 0));
      var tip = chatT('chat_add_friend_confirm', { name: peerNick });
      if (!confirm(tip)) return null;
      // 仅允许手机号 / 8位ID，不传裸 peer_user_id
      return send('friend.request', requestPayload).then(function (packet2) {
        if (packet2 && packet2.data) {
          packet2.data._peer_nickname = peerNick;
          packet2.data._peer_avatar = u.avatar || '';
        }
        return packet2;
      });
    }).then(function (packet2) {
      if (!packet2 || !packet2.data) return false;
      var data = packet2.data;
      var peer = data.peer_user_id | 0;
      var cid = data.conversation_id || '';
      var nick = String(data._peer_nickname || (data.peer && data.peer.nickname) || (data.to_user && data.to_user.nickname) || '').trim()
        || ('ID' + peer);
      closeAddFriendPane();
      refreshFriendRequests().catch(function () {});
      refreshList().catch(function () {});
      refreshCommunity().catch(function () {});
      if (data.auto_accepted || data.status === 'accepted' || data.status === 'already_friends') {
        openRoom({ type: 1, id: cid, peer: peer, title: nick, peer_nickname: nick });
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
      var u = packet.data.user || {};
      var peerNick = String(u.nickname || '').trim() || ('ID' + (u.user_id | 0));
      var tip = chatT('chat_add_friend_confirm', { name: peerNick });
      if (!confirm(tip)) return null;
      return send('friend.request', { user_id: id }).then(function (packet2) {
        if (packet2 && packet2.data) {
          packet2.data._peer_nickname = peerNick;
          packet2.data._peer_avatar = u.avatar || '';
        }
        return packet2;
      });
    }).then(function (packet2) {
      if (!packet2 || !packet2.data) return false;
      var data = packet2.data;
      var peer = data.peer_user_id | 0;
      var cid = data.conversation_id || '';
      var nick = String(data._peer_nickname || (data.peer && data.peer.nickname) || (data.to_user && data.to_user.nickname) || '').trim()
        || ('ID' + peer);
      closeAddFriendPane();
      if (typeof FansHubFriendQr !== 'undefined' && FansHubFriendQr.closeScanPane) FansHubFriendQr.closeScanPane();
      refreshFriendRequests().catch(function () {});
      refreshList().catch(function () {});
      refreshCommunity().catch(function () {});
      if (data.auto_accepted || data.status === 'accepted' || data.status === 'already_friends') {
        openRoom({ type: 1, id: cid, peer: peer, title: nick, peer_nickname: nick });
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
    } else if (packet.type === 'friend.accepted' || packet.type === 'friend.rejected' || packet.type === 'friend.cancelled') {
      refreshFriendRequests(true).then(function () {
        if ($('chatFriendReqPane') && $('chatFriendReqPane').classList.contains('open')) {
          renderFriendReqList();
        }
        if (packet.type === 'friend.cancelled' && typeof showFanshubToast === 'function') {
          showFanshubToast(chatT('chat_friend_req_status_cancelled') || '好友申请已取消', 'info');
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
