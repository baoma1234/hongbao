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
        if (packet.data && packet.data.user && packet.data.user.hongbao_frozen != null) {
          state.hongbaoFrozen = parseFloat(packet.data.user.hongbao_frozen);
        }
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
    bindAudioUnlock();
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
        // 鼠标/笔：不走左滑与长按拦截，避免桌面轻微抖动导致点不进会话
        if (ev.pointerType && ev.pointerType !== 'touch') {
          clearLongPress();
          swipeState = null;
          return;
        }
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
          title: btn.getAttribute('data-title') || '',
          peer_nickname: btn.getAttribute('data-nickname') || '',
          remark: btn.getAttribute('data-remark') || ''
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
        if (ev.target && (ev.target.id === 'chatNoticePinClose' || (ev.target.closest && ev.target.closest('#chatNoticePinClose')))) return;
        var imgBtn = ev.target && ev.target.closest ? ev.target.closest('.chat-notice-pin-img') : null;
        if (imgBtn) {
          var src = imgBtn.getAttribute('data-src') || (imgBtn.querySelector('img') && imgBtn.querySelector('img').src);
          if (src && typeof openMediaLightbox === 'function') openMediaLightbox(src, 'image');
          return;
        }
        noticePin.classList.toggle('is-expanded');
      });
    }
    var settingsPopups = $('chatGroupSettingsPopups');
    if (settingsPopups && !settingsPopups._bound) {
      settingsPopups._bound = true;
      settingsPopups.addEventListener('click', function (ev) {
        var popBtn = ev.target && ev.target.closest ? ev.target.closest('.chat-setting-popup-item') : null;
        if (!popBtn) return;
        var pid = parseInt(popBtn.getAttribute('data-popup-id'), 10) || 0;
        if (pid > 0 && typeof openPinnedGroupPopup === 'function') openPinnedGroupPopup(pid);
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
      // 勾选后不再自动保存，需点「保存禁止设置」（含禁言提示文案）
    }
    var forbidSaveBtn = $('chatForbidModesSaveBtn');
    if (forbidSaveBtn && !forbidSaveBtn._bound) {
      forbidSaveBtn._bound = true;
      forbidSaveBtn.onclick = function () {
        if (typeof saveGroupForbidModes === 'function') saveGroupForbidModes();
      };
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
        if (pickImageBtn.disabled || pickImageBtn.classList.contains('is-forbid')
          || (state.room && state.room.type === 2 && typeof canSendCapability === 'function' && !canSendCapability('image'))) {
          if (typeof showFanshubToast === 'function') showFanshubToast('本群禁止发图', 'error');
          return;
        }
        var input = $('chatImageInput');
        if (input) input.click();
      };
    }
    var pickVideoBtn = $('chatPickVideoBtn');
    if (pickVideoBtn && !pickVideoBtn._bound) {
      pickVideoBtn._bound = true;
      pickVideoBtn.onclick = function () {
        if (pickVideoBtn.disabled || pickVideoBtn.classList.contains('is-forbid')
          || (state.room && state.room.type === 2 && typeof canSendCapability === 'function' && !canSendCapability('video'))) {
          if (typeof showFanshubToast === 'function') showFanshubToast('本群禁止发视频', 'error');
          return;
        }
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
        try { syncRpMineField(); } catch (eType) {}
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