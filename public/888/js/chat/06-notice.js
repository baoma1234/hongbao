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
