/* js/chat/03-rp.js — red packet send/grab */

  function getRpPacketType() {
    var active = document.querySelector('#chatRpTypeTabs .chat-rp-type-btn.active');
    return active ? (parseInt(active.getAttribute('data-type'), 10) || 2) : 2;
  }

  function syncRpMineField() {
    var wrap = $('chatRpMineWrap');
    if (!wrap) return;
    var type = getRpPacketType();
    if (type === 3) {
      wrap.hidden = false;
      wrap.style.display = '';
    } else {
      wrap.hidden = true;
      wrap.style.display = 'none';
    }
  }

  function updateRpPreview() {
    var blessEl = $('chatRpPreviewBless');
    var subEl = $('chatRpPreviewSub');
    var blessInput = $('chatRpBlessing');
    var amountInput = $('chatRpAmount');
    var countInput = $('chatRpCount');
    if (blessEl && blessInput) {
      var t = String(blessInput.value || '').trim();
      blessEl.textContent = t || chatT('chat_rp_blessing_default');
    }
    if (subEl) {
      var type = getRpPacketType();
      var count = countInput ? (parseInt(countInput.value, 10) || 1) : 1;
      var amount = amountInput ? (parseFloat(amountInput.value) || 0) : 0;
      var typeLabel = type === 1 ? '人均' : (type === 3 ? '埋雷' : '拼手气');
      var parts = [typeLabel];
      if (type === 3) parts.push('波场定雷');
      if (count > 0) parts.push(count + '个');
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
    if (state.room.type === 2 && groupPolicy().can_send_rp === false) {
      if (typeof showFanshubToast === 'function') showFanshubToast('红宝模式下仅管理员可发红包', 'error');
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
    var amountInput = $('chatRpAmount');
    if (amountInput && !amountInput.value) amountInput.value = '';
    var blessInput = $('chatRpBlessing');
    if (blessInput && !String(blessInput.value || '').trim()) {
      blessInput.value = '恭喜发财';
    }
    updateRpPreview();
    pane.classList.add('open');
    pane.setAttribute('aria-hidden', 'false');
    if (amountInput) setTimeout(function () { amountInput.focus(); }, 80);
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
    if (isNaN(mineDigit)) mineDigit = 0;
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
    if (state.money != null && totalAmount > state.money + 0.0001) {
      if (typeof showFanshubToast === 'function') showFanshubToast('红利余额不足', 'error');
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
      // 官方雷号由波场哈希开奖决定，发包不再手填
      data.mine_digit = 0;
    }
    if (state.room.type === 2) data.group_id = state.room.id | 0;
    else data.to_user_id = state.room.peer | 0;
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
