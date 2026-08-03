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

  /** 本群红包个数区间（后台最少/最多；相等=固定） */
  function groupRpCountRange() {
    var min = 0;
    var max = 0;
    try {
      var policy = typeof groupPolicy === 'function' ? groupPolicy() : {};
      min = parseInt(policy && policy.rp_min_count, 10) || 0;
      max = parseInt(policy && policy.rp_max_count, 10) || 0;
    } catch (e2) {}
    if (!(min > 0) && state.groupMeta && state.groupMeta.group) {
      min = parseInt(state.groupMeta.group.rp_min_count, 10) || 0;
    }
    if (!(max > 0) && state.groupMeta && state.groupMeta.group) {
      max = parseInt(state.groupMeta.group.rp_max_count, 10) || 0;
    }
    if (min <= 0) min = 5;
    if (max <= 0) max = 10;
    if (max < min) max = min;
    return { min: min, max: max, fixed: min === max };
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
    var range = isGroup ? groupRpCountRange() : { min: 1, max: 1, fixed: true };
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
      var allowed = [5, 7, 9].filter(function (n) {
        return n >= range.min && n <= range.max;
      });
      if (!allowed.length) allowed = [5, 7, 9];
      var cur = parseInt(countInput.value, 10) || allowed[0];
      if (allowed.indexOf(cur) < 0) cur = allowed[0];
      countInput.value = String(cur);
      countInput.readOnly = allowed.length === 1;
      if (allowed.length === 1) countInput.setAttribute('aria-readonly', 'true');
      else countInput.removeAttribute('aria-readonly');
      if (tabs) {
        tabs.querySelectorAll('.chat-rp-count-btn').forEach(function (b) {
          var c = parseInt(b.getAttribute('data-count'), 10) || 0;
          var ok = allowed.indexOf(c) >= 0;
          b.hidden = !ok;
          b.style.display = ok ? '' : 'none';
          b.classList.toggle('active', c === cur);
        });
      }
      if (hint) {
        if (allowed.length === 1) {
          hint.textContent = '本群扫雷固定 ' + allowed[0] + ' 个 · 中雷赔付 '
            + rpCountTabLabel(allowed[0], rates[allowed[0]]);
        } else {
          hint.textContent = '中雷赔付：' + allowed.map(function (n) {
            return rpCountTabLabel(n, rates[n]);
          }).join(' · ');
        }
      }
    } else if (hint) {
      if (!isGroup) {
        hint.textContent = '私聊固定 1 个';
        if (countInput) {
          countInput.readOnly = true;
          countInput.value = '1';
        }
      } else {
        if (countInput) {
          countInput.min = String(range.min);
          countInput.max = String(range.max);
          var curN = parseInt(countInput.value, 10) || range.min;
          if (curN < range.min || curN > range.max) curN = range.min;
          countInput.value = String(curN);
          if (range.fixed) {
            countInput.readOnly = true;
            countInput.setAttribute('aria-readonly', 'true');
            hint.textContent = '本群固定 ' + range.min + ' 个';
          } else {
            countInput.readOnly = false;
            countInput.removeAttribute('aria-readonly');
            hint.textContent = '本群可发 ' + range.min + '～' + range.max + ' 个';
          }
        } else {
          hint.textContent = range.fixed
            ? ('本群固定 ' + range.min + ' 个')
            : ('本群可发 ' + range.min + '～' + range.max + ' 个');
        }
      }
    }
  }

  function syncRpTypeDesc() {
    var el = $('chatRpTypeDesc');
    if (!el) return;
    var type = getRpPacketType();
    var isGroup = !!(state.room && state.room.type === 2);
    // 仅群聊 + 拼手气时展示简介（多语言文案，后台 H5 文案可改）
    if (isGroup && type === 2) {
      el.hidden = false;
      el.style.display = '';
      el.textContent = chatT('chat_rp_type_lucky_desc') || '拼手气红包：金额随机分配，手气越好领得越多。';
    } else {
      el.hidden = true;
      el.style.display = 'none';
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
    syncRpTypeDesc();
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
        countInput.readOnly = true;
      } else {
        var range = groupRpCountRange();
        countInput.min = String(range.min);
        countInput.max = String(range.max);
        var cur = parseInt(countInput.value, 10) || range.min;
        if (cur < range.min || cur > range.max) cur = range.min;
        countInput.value = String(cur);
        countInput.readOnly = !!range.fixed;
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
        countInput.readOnly = true;
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
      try { syncRpTypeDesc(); } catch (eDesc) {}
    } else {
      // 群聊：按本群最少/最多刷新个数区
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
    if (state.room.type === 2) {
      var range = groupRpCountRange();
      if (packetType === 3) {
        var mineOk = [5, 7, 9].filter(function (n) {
          return n >= range.min && n <= range.max;
        });
        if (!mineOk.length) mineOk = [5, 7, 9];
        if (mineOk.indexOf(totalCount) < 0) {
          if (typeof showFanshubToast === 'function') {
            showFanshubToast(
              mineOk.length === 1
                ? ('本群扫雷红包个数固定为 ' + mineOk[0] + ' 个')
                : ('扫雷红包个数仅可选 ' + mineOk.join(' / ')),
              'error'
            );
          }
          return;
        }
      } else if (totalCount < range.min || totalCount > range.max) {
        if (typeof showFanshubToast === 'function') {
          showFanshubToast(
            range.fixed
              ? ('本群红包个数固定为 ' + range.min + ' 个')
              : ('个数须为 ' + range.min + '～' + range.max),
            'error'
          );
        }
        return;
      }
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
