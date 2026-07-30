(function (global) {
  'use strict';

  var walletState = {
    recharge: [],
    withdraw: [],
    rechargePartitions: [],
    withdrawPartitions: [],
    binds: {},
    selectedRecharge: 0,
    selectedWithdraw: 0,
    info: null,
    rechargeExpanded: {},
    withdrawExpanded: {},
    rechargePartitionKey: '',
    withdrawPartitionKey: '',
    rebinding: false
  };

  /** 钱包分区优先展示顺序（其余收入「更多」） */
  var WALLET_PIN_ORDER = ['no', '234', '808', '988', 'k豆', 'jd', 'c币', 'ok', 'to', 'go', '万币'];
  var WALLET_PIN_ALIAS = {
    nopay: 'no',
    kdou: 'k豆',
    cbi: 'c币',
    jdpay: 'jd',
    okpay: 'ok',
    topay: 'to',
    gopay: 'go',
    wanbi: '万币',
    ersansi: '234',
    balingba: '808',
    jiubaba: '988',
    '988pay': '988',
    '808pay': '808',
    '234pay': '234'
  };

  function walletChannelKey(name, paymentChannel) {
    var code = String(paymentChannel || '').replace(/\s+/g, '');
    var codeQuick = /_quick$/i.test(code);
    if (code) {
      code = code.replace(/_quick$/i, '');
      var codeKey = code.toLowerCase();
      if (WALLET_PIN_ALIAS[codeKey]) codeKey = WALLET_PIN_ALIAS[codeKey];
      if (/^\d+$/.test(codeKey) || WALLET_PIN_ORDER.indexOf(codeKey) >= 0) {
        return { key: codeKey, quick: codeQuick };
      }
      var stripped = codeKey.replace(/pay$/i, '');
      if (WALLET_PIN_ALIAS[stripped]) stripped = WALLET_PIN_ALIAS[stripped];
      if (WALLET_PIN_ORDER.indexOf(stripped) >= 0) {
        return { key: stripped, quick: codeQuick };
      }
    }
    var s = String(name || '').replace(/\s+/g, '');
    var quick = codeQuick || /快捷|_quick/i.test(s);
    s = s.replace(/快捷(支付)?/g, '').replace(/钱包/g, '').replace(/支付$/g, '').replace(/pay$/i, '');
    var key = s.toLowerCase();
    if (WALLET_PIN_ALIAS[key]) key = WALLET_PIN_ALIAS[key];
    return { key: key, quick: quick };
  }

  function walletPinIndex(ch) {
    var info = walletChannelKey(ch && ch.name, ch && ch.payment_channel);
    if (info.quick) return -1;
    // BS USDT 代付固定到第2位（仅次于第一优先项）
    var handler = String((ch && ch.handler) || '').toLowerCase();
    var mixed = String((ch && ch.name) || '') + ' ' + String((ch && ch.payment_channel) || '') + ' ' + String((ch && ch.wallet_type) || '');
    if (handler === 'bs' && /usdt/i.test(mixed)) return 0.5;
    return WALLET_PIN_ORDER.indexOf(info.key);
  }

  function organizeWalletChannels(list) {
    var pinned = [];
    var more = [];
    (list || []).forEach(function (ch, idx) {
      var pin = walletPinIndex(ch);
      if (pin >= 0) pinned.push({ ch: ch, pin: pin, idx: idx });
      else more.push({ ch: ch, idx: idx });
    });
    pinned.sort(function (a, b) {
      if (a.pin !== b.pin) return a.pin - b.pin;
      return a.idx - b.idx;
    });
    more.sort(function (a, b) { return a.idx - b.idx; });
    return {
      pinned: pinned.map(function (x) { return x.ch; }),
      more: more.map(function (x) { return x.ch; })
    };
  }

  /** 有「更多钱包」时默认选置顶通道，避免选中隐藏项导致列表被强制展开 */
  function pickDefaultChannel(list, useMore) {
    var arr = list || [];
    if (!arr.length) return null;
    if (!useMore) return arr[0];
    var groups = organizeWalletChannels(arr);
    if (groups.pinned.length) return groups.pinned[0];
    return groups.more[0] || arr[0];
  }

  function findChannel(list, id) {
    id = id | 0;
    for (var i = 0; i < (list || []).length; i++) {
      if ((list[i].id | 0) === id) return list[i];
    }
    return null;
  }

  function formatLimitHint(ch) {
    if (!ch) return '';
    var min = Number(ch.min_amount) || 0;
    var max = Number(ch.max_amount) || 0;
    if (min > 0 && max > 0) {
      return wt('wallet_limit_range', '限额：{min} ～ {max}', { min: money(min), max: money(max) });
    }
    if (min > 0) return wt('wallet_limit_min', '最低：{min}', { min: money(min) });
    if (max > 0) return wt('wallet_limit_max', '最高：{max}', { max: money(max) });
    return wt('wallet_limit_none', '金额不限');
  }

  function applyAmountLimits(inputId, ch) {
    var el = document.getElementById(inputId);
    if (!el) return;
    var min = Number(ch && ch.min_amount) || 0;
    var max = Number(ch && ch.max_amount) || 0;
    if (min > 0) el.min = String(min);
    else el.removeAttribute('min');
    if (max > 0) el.max = String(max);
    else el.removeAttribute('max');
    el.placeholder = min > 0
      ? wt('wallet_amount_ph_min', '请输入金额，最低 {min}', { min: money(min) })
      : wt('profile_amount_ph', '请输入金额');
  }

  function syncWithdrawBindUI(ch) {
    var bindPanel = document.getElementById('profileWithdrawWalletBind');
    var convPanel = document.getElementById('profileWithdrawConventional');
    var submitBtn = document.getElementById('profileWithdrawSubmit');
    if (!ch) {
      if (bindPanel) { bindPanel.hidden = true; }
      if (convPanel) { convPanel.style.display = ''; }
      return;
    }
    var mode = String(ch.bind_mode || '');
    var isWallet = mode === 'wallet';
    if (bindPanel) bindPanel.hidden = !isWallet;
    if (convPanel) convPanel.style.display = isWallet ? 'none' : '';
    if (!isWallet) {
      if (submitBtn) submitBtn.style.display = '';
      walletState.rebinding = false;
      return;
    }
    var wtype = String(ch.wallet_type || ch.payment_channel || '');
    var bind = (walletState.binds && walletState.binds[wtype]) || null;
    var cur = document.getElementById('profileWithdrawBindCurrent');
    var form = document.getElementById('profileWithdrawBindForm');
    var addr = document.getElementById('profileWithdrawBoundAddr');
    var needBind = !bind;
    if (cur) cur.hidden = !bind;
    if (form) form.style.display = needBind ? '' : 'none';
    if (addr && bind) addr.textContent = bind.account_no || '-';
    if (submitBtn) submitBtn.style.display = needBind ? 'none' : '';
  }

  function showAmountPanel(type, channelId) {
    var isRecharge = type === 'recharge';
    var list = isRecharge ? walletState.recharge : walletState.withdraw;
    var ch = findChannel(list, channelId);
    var panel = document.getElementById(isRecharge ? 'profileRechargeForm' : 'profileWithdrawForm');
    var hint = document.getElementById(isRecharge ? 'profileRechargeLimitHint' : 'profileWithdrawLimitHint');
    if (!panel) return;
    if (!ch) {
      panel.setAttribute('hidden', 'hidden');
      panel.style.display = 'none';
      panel.classList.remove('is-open');
      return;
    }
    panel.removeAttribute('hidden');
    panel.style.display = 'block';
    panel.classList.add('is-open');
    if (hint) hint.textContent = formatLimitHint(ch);
    applyAmountLimits(isRecharge ? 'profileRechargeAmount' : 'profileWithdrawAmount', ch);
    if (!isRecharge) {
      syncWithdrawBindUI(ch);
      var isBsUsdt = ch && String(ch.handler || '').toLowerCase() === 'bs';
      var nameWrap = document.getElementById('profileWithdrawName');
      var nameField = nameWrap && nameWrap.closest ? nameWrap.closest('.profile-field') : null;
      var bankWrap = document.getElementById('profileWithdrawBankWrap');
      var branchWrap = document.getElementById('profileWithdrawBranchWrap');
      var regionWrap = document.getElementById('profileWithdrawRegionWrap');
      var accountInput = document.getElementById('profileWithdrawAccount');
      if (String(ch.bind_mode || '') !== 'wallet') {
        if (isBsUsdt) {
          if (nameField) nameField.style.display = 'none';
          if (bankWrap) bankWrap.style.display = 'none';
          if (branchWrap) branchWrap.style.display = 'none';
          if (regionWrap) regionWrap.style.display = 'none';
          if (accountInput) accountInput.placeholder = 'USDT 收款地址（TRC20）';
        } else {
          if (nameField) nameField.style.display = '';
          if (bankWrap) bankWrap.style.display = '';
          if (branchWrap) branchWrap.style.display = 'none';
          if (regionWrap) regionWrap.style.display = 'none';
          if (accountInput) accountInput.placeholder = wt('profile_withdraw_account_ph', '钱包地址 / 银行卡号 / 支付宝账号');
        }
        var bank = document.getElementById('profileWithdrawBank');
        if (bank && !(bank.value || '').trim() && ch.name && !isBsUsdt) {
          bank.value = String(ch.name).replace(/(充值|代付|提现)$/, '') || ch.name;
        }
      }
    }
    try {
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) {
      try { panel.scrollIntoView(true); } catch (e2) {}
    }
  }

  function validateChannelAmount(ch, amount) {
    if (!ch) return wt('wallet_need_channel', '请先选择通道');
    if (!(amount > 0)) return wt('wallet_need_amount', '请填写金额');
    var min = Number(ch.min_amount) || 0;
    var max = Number(ch.max_amount) || 0;
    if (min > 0 && amount < min) {
      return wt('wallet_amount_too_low', '金额不能低于 {min}', { min: money(min) });
    }
    if (max > 0 && amount > max) {
      return wt('wallet_amount_too_high', '金额不能高于 {max}', { max: money(max) });
    }
    return '';
  }

  function channelButtonHtml(ch, type, sel) {
    var active = sel === (ch.id | 0) ? ' active' : '';
    var name = ch.name || wt('wallet_channel_fallback', '通道{id}', { id: ch.id });
    var icon = (ch.icon || '').trim();
    var iconHtml = icon
      ? '<img class="wallet-channel-icon" src="' + escapeHtml(icon) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">'
      : '<span class="wallet-channel-icon wallet-channel-icon--placeholder">' + escapeHtml((name || '?').charAt(0)) + '</span>';
    var tip = (ch.tip || '').trim();
    if (/^wanhuipay\b/i.test(tip)) tip = '';
    var lim = formatLimitHint(ch);
    return (
      '<button type="button" class="wallet-channel-item' + active + '" data-id="' + ch.id + '" data-type="' + type + '">' +
        iconHtml +
        '<span class="wallet-channel-meta">' +
          '<span class="wallet-channel-name">' + escapeHtml(name) + '</span>' +
          (lim ? '<small>' + escapeHtml(lim) + '</small>' : (tip ? '<small>' + escapeHtml(tip) + '</small>' : '')) +
        '</span>' +
      '</button>'
    );
  }

  function moreButtonHtml(type, partKey, expanded) {
    return (
      '<button type="button" class="wallet-channel-item wallet-channel-more-btn' + (expanded ? ' is-open' : '') + '" data-more-type="' + type + '" data-more-part="' + escapeHtml(partKey) + '" aria-expanded="' + (expanded ? 'true' : 'false') + '">' +
        '<span class="wallet-channel-icon wallet-channel-icon--placeholder" aria-hidden="true">' + (expanded ? '−' : '+') + '</span>' +
        '<span class="wallet-channel-meta">' +
          '<span class="wallet-channel-name">' +
            escapeHtml(expanded ? wt('wallet_channel_less', '收起') : wt('wallet_channel_more', '更多钱包')) +
          '</span>' +
          '<small>' + escapeHtml(expanded
            ? wt('wallet_channel_less_hint', '点击收起其余通道')
            : wt('wallet_channel_more_hint', '点击查看全部通道')) + '</small>' +
        '</span>' +
      '</button>'
    );
  }

  function renderChannelGroupHtml(list, type, sel, partKey, useMore) {
    if (!useMore) {
      return (list || []).map(function (ch) { return channelButtonHtml(ch, type, sel); }).join('');
    }
    var groups = organizeWalletChannels(list);
    var html = '';
    var expandedMap = type === 'recharge' ? walletState.rechargeExpanded : walletState.withdrawExpanded;
    if (!groups.pinned.length) {
      return groups.more.map(function (ch) { return channelButtonHtml(ch, type, sel); }).join('');
    }
    html = groups.pinned.map(function (ch) { return channelButtonHtml(ch, type, sel); }).join('');
    if (groups.more.length) {
      var selInMore = groups.more.some(function (ch) { return (ch.id | 0) === sel; });
      if (selInMore) expandedMap[partKey] = true;
      var expanded = !!expandedMap[partKey];
      html += moreButtonHtml(type, partKey, expanded);
      html += '<div class="wallet-channel-more-list"' + (expanded ? '' : ' hidden') + '>' +
        groups.more.map(function (ch) { return channelButtonHtml(ch, type, sel); }).join('') +
        '</div>';
    }
    return html;
  }

  function partitionStateKey(type) {
    return type === 'recharge' ? 'rechargePartitionKey' : 'withdrawPartitionKey';
  }

  function partitionTabBoxId(type) {
    return type === 'recharge' ? 'profileRechargePartitionTabs' : 'profileWithdrawPartitionTabs';
  }

  function getActivePartition(partitions, type) {
    if (!partitions || !partitions.length) return null;
    var key = walletState[partitionStateKey(type)] || '';
    for (var i = 0; i < partitions.length; i++) {
      if (partitions[i].code === key) return partitions[i];
    }
    walletState[partitionStateKey(type)] = partitions[0].code;
    return partitions[0];
  }

  function renderPartitionTabs(partitions, type) {
    var box = document.getElementById(partitionTabBoxId(type));
    if (!box) return;
    if (!partitions || partitions.length < 2) {
      box.innerHTML = '';
      box.hidden = true;
      return;
    }
    box.hidden = false;
    var activeKey = walletState[partitionStateKey(type)] || partitions[0].code;
    var hasActive = partitions.some(function (part) {
      return String(part.code || '') === String(activeKey);
    });
    if (!hasActive) {
      activeKey = partitions[0].code;
      walletState[partitionStateKey(type)] = activeKey;
    }
    box.innerHTML = partitions.map(function (part) {
      var key = String(part.code || part.id || 'part');
      var active = key === String(activeKey) ? ' active' : '';
      var label = part.name || key;
      return '<button type="button" class="wallet-partition-tab' + active + '" data-part-key="' + escapeHtml(key) + '" data-type="' + type + '">' + escapeHtml(label) + '</button>';
    }).join('');
    box.onclick = function (ev) {
      var t = ev.target;
      if (!t) return;
      var btn = t.closest ? t.closest('.wallet-partition-tab') : null;
      if (!btn && t.classList && t.classList.contains('wallet-partition-tab')) btn = t;
      if (!btn) return;
      ev.preventDefault();
      var key = btn.getAttribute('data-part-key') || '';
      if (!key || walletState[partitionStateKey(type)] === key) return;
      walletState[partitionStateKey(type)] = key;
      if (type === 'recharge') walletState.selectedRecharge = 0;
      else walletState.selectedWithdraw = 0;
      renderChannels(type === 'recharge' ? 'profileRechargeChannels' : 'profileWithdrawChannels', type === 'recharge' ? walletState.recharge : walletState.withdraw, type);
    };
  }

  function bindChannelListClicks(box, type) {
    box.onclick = function (ev) {
      var t = ev.target;
      if (!t) return;
      var moreBtn = t.closest ? t.closest('.wallet-channel-more-btn') : null;
      if (!moreBtn && t.classList && t.classList.contains('wallet-channel-more-btn')) moreBtn = t;
      if (moreBtn) {
        ev.preventDefault();
        var partKey = moreBtn.getAttribute('data-more-part') || 'wallet';
        var expandedMap = type === 'recharge' ? walletState.rechargeExpanded : walletState.withdrawExpanded;
        var willExpand = !expandedMap[partKey];
        expandedMap[partKey] = willExpand;
        // 收起时若当前选中在「更多」里，改选置顶通道，否则 render 会因选中项强制再次展开
        if (!willExpand) {
          var partitions = type === 'recharge' ? walletState.rechargePartitions : walletState.withdrawPartitions;
          var activePart = getActivePartition(partitions, type);
          var chs = activePart ? (activePart.channels || []) : (type === 'recharge' ? walletState.recharge : walletState.withdraw);
          var groups = organizeWalletChannels(chs);
          var sel = (type === 'recharge' ? walletState.selectedRecharge : walletState.selectedWithdraw) | 0;
          var inMore = groups.more.some(function (ch) { return (ch.id | 0) === sel; });
          if (inMore && groups.pinned[0]) {
            if (type === 'recharge') walletState.selectedRecharge = groups.pinned[0].id | 0;
            else walletState.selectedWithdraw = groups.pinned[0].id | 0;
          }
        }
        renderChannels(box.id, type === 'recharge' ? walletState.recharge : walletState.withdraw, type);
        return;
      }
      var btn = t.closest ? t.closest('.wallet-channel-item') : null;
      if (!btn && t.classList && t.classList.contains('wallet-channel-item')) btn = t;
      if (!btn) {
        var node = t;
        while (node && node !== box) {
          if (node.classList && node.classList.contains('wallet-channel-item')) {
            btn = node;
            break;
          }
          node = node.parentNode;
        }
      }
      if (!btn || btn.classList.contains('wallet-channel-more-btn')) return;
      ev.preventDefault();
      var items = box.querySelectorAll('.wallet-channel-item');
      for (var i = 0; i < items.length; i++) items[i].classList.remove('active');
      btn.classList.add('active');
      var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
      if (type === 'recharge') walletState.selectedRecharge = id;
      else walletState.selectedWithdraw = id;
      showAmountPanel(type, id);
    };
  }

  function renderChannels(boxId, list, type) {
    var box = document.getElementById(boxId);
    if (!box) return;
    var partitions = type === 'recharge' ? walletState.rechargePartitions : walletState.withdrawPartitions;
    var sel = (type === 'recharge' ? walletState.selectedRecharge : walletState.selectedWithdraw) | 0;
    var flat = list || [];
    if (!flat.length && !(partitions && partitions.length)) {
      renderPartitionTabs([], type);
      box.innerHTML = '<div class="wallet-channel-empty">' + escapeHtml(wt('wallet_channel_empty', '暂无可用通道，请联系客服')) + '</div>';
      showAmountPanel(type, 0);
      return;
    }
    if (!partitions || !partitions.length) {
      partitions = [{ id: 0, code: 'all', name: '', bind_mode: 'none', channels: flat }];
    }
    renderPartitionTabs(partitions, type);
    var activePart = getActivePartition(partitions, type);
    var chs = activePart ? (activePart.channels || []) : flat;
    var partKey = activePart ? String(activePart.code || activePart.id || 'part') : 'all';
    var useMore = !!(activePart && (activePart.code === 'wallet' || activePart.bind_mode === 'wallet'));

    if (!sel) {
      var def = pickDefaultChannel(chs, useMore);
      if (def) {
        if (type === 'recharge') walletState.selectedRecharge = def.id | 0;
        else walletState.selectedWithdraw = def.id | 0;
        sel = def.id | 0;
      }
    } else if (!findChannel(chs, sel)) {
      var fallback = pickDefaultChannel(chs, useMore);
      if (fallback) {
        if (type === 'recharge') walletState.selectedRecharge = fallback.id | 0;
        else walletState.selectedWithdraw = fallback.id | 0;
        sel = fallback.id | 0;
      } else {
        if (type === 'recharge') walletState.selectedRecharge = 0;
        else walletState.selectedWithdraw = 0;
        sel = 0;
      }
    }

    var html = '';
    if (!chs.length) {
      html = '<div class="wallet-channel-empty wallet-channel-empty--inline">' + escapeHtml(wt('wallet_partition_empty', '当前分区暂无可用通道')) + '</div>';
    } else {
      html = renderChannelGroupHtml(chs, type, sel, partKey, useMore);
    }
    box.innerHTML = html;
    bindChannelListClicks(box, type);
    showAmountPanel(type, sel);
  }
  var ledgerState = { page: 1, loading: false, hasMore: false, list: [] };

  function wt(key, fallback, extra) {
    var tpl = fallback || key;
    var defaults = global.FANSHUB_COPY_DEFAULTS || {};
    if (global.FanshubI18n && typeof global.FanshubI18n.text === 'function') {
      var t = global.FanshubI18n.text(key, defaults);
      if (t) tpl = t;
    } else if (defaults[key]) {
      tpl = defaults[key];
    }
    if (extra && typeof extra === 'object') {
      Object.keys(extra).forEach(function (k) {
        tpl = String(tpl).split('{' + k + '}').join(String(extra[k]));
      });
    }
    return tpl;
  }
  function api(action, data) {
    if (typeof global.apiRequest === 'function') {
      // app.js: apiRequest(action, method, body) → /api/fanshub/{action}
      return global.apiRequest(action, 'POST', data || {});
    }
    return Promise.reject(new Error(wt('wallet_not_login', '未登录')));
  }

  function money(n) {
    n = parseFloat(n);
    var sym = (global.FanshubI18n && typeof global.FanshubI18n.currencySymbol === 'function')
      ? global.FanshubI18n.currencySymbol()
      : '￥';
    if (isNaN(n)) return sym + '0.00';
    return sym + n.toFixed(2);
  }

  function fmtSignedMoney(n) {
    n = parseFloat(n) || 0;
    var sym = (global.FanshubI18n && typeof global.FanshubI18n.currencySymbol === 'function')
      ? global.FanshubI18n.currencySymbol()
      : '￥';
    var abs = Math.abs(n).toFixed(2);
    if (n > 0) return '+' + sym + abs;
    if (n < 0) return '-' + sym + abs;
    return sym + '0.00';
  }

  function fmtSignedRights(n) {
    n = parseFloat(n) || 0;
    var abs = Math.abs(n).toFixed(2);
    if (n > 0) return '+' + abs + wt('wallet_unit_share', '股');
    if (n < 0) return '-' + abs + wt('wallet_unit_share', '股');
    return '';
  }

  function fmtTime(ts) {
    ts = ts | 0;
    if (!ts) return '';
    var d = new Date(ts * 1000);
    var p = function (n) { return n < 10 ? '0' + n : '' + n; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  function toast(msg, type) {
    if (typeof global.showFanshubToast === 'function') {
      global.showFanshubToast(msg, type || 'info');
    } else {
      alert(msg);
    }
  }

  function fmtSignedHongbao(n) {
    n = parseFloat(n) || 0;
    var abs = Math.abs(n).toFixed(2);
    if (n > 0) return '+' + abs + wt('wallet_unit_hongbao', '红宝');
    if (n < 0) return '-' + abs + wt('wallet_unit_hongbao', '红宝');
    return '';
  }

  function ledgerTypeLabel(type) {
    type = String(type || '');
    if (!type) return wt('wallet_ledger_other', '其他');
    var key = 'wallet_ledger_type_' + type;
    var translated = wt(key, '');
    if (translated && translated !== key) return translated;
    return wt('wallet_ledger_other', '其他');
  }

  function renderLedgerList() {
    var box = document.getElementById('profileLedgerList');
    var moreBtn = document.getElementById('profileLedgerMoreBtn');
    if (!box) return;
    if (!ledgerState.list.length) {
      box.innerHTML = '<div class="wallet-ledger-empty">' + escapeHtml(wt('wallet_ledger_empty', '暂无资金流水')) + '</div>';
      if (moreBtn) moreBtn.style.display = 'none';
      return;
    }
    box.innerHTML = ledgerState.list.map(function (item) {
      var bal = parseFloat(item.balance_change) || 0;
      var rights = parseFloat(item.rights_change) || 0;
      var hb = parseFloat(item.hongbao_change) || 0;
      var amountCls = '';
      var amountText = '';
      if (hb !== 0) {
        amountText = fmtSignedHongbao(hb);
        amountCls = hb > 0 ? ' plus' : ' minus';
      } else if (bal !== 0) {
        amountText = fmtSignedMoney(bal);
        amountCls = bal > 0 ? ' plus' : ' minus';
      } else if (rights !== 0) {
        amountText = (rights > 0 ? '+' : '') + rights.toFixed(2) + wt('wallet_unit_share', '股');
        amountCls = rights > 0 ? ' plus' : ' minus';
      } else {
        amountText = '0.00';
      }
      var subParts = [];
      if (hb !== 0 && (bal !== 0 || rights !== 0)) {
        if (bal !== 0) subParts.push(fmtSignedMoney(bal));
        if (rights !== 0) subParts.push(fmtSignedRights(rights));
      } else if (bal !== 0 && rights !== 0) {
        subParts.push(fmtSignedRights(rights));
      }
      if (item.remark) subParts.push(item.remark);
      if (item.hongbao_after != null && hb !== 0) subParts.push(wt('wallet_unit_hongbao', '红宝') + ' ' + money(item.hongbao_after));
      else if (item.balance_after != null && bal !== 0) subParts.push(wt('wallet_unit_balance', '红利') + ' ' + money(item.balance_after));
      return (
        '<div class="wallet-ledger-item">' +
          '<div class="wallet-ledger-main">' +
            '<div class="wallet-ledger-title">' + escapeHtml(ledgerTypeLabel(item.type) || item.type_label || wt('wallet_ledger_other', '其他')) + '</div>' +
            '<div class="wallet-ledger-sub">' + escapeHtml(subParts.join(' · ') || fmtTime(item.createtime)) + '</div>' +
            '<div class="wallet-ledger-time">' + escapeHtml(fmtTime(item.createtime)) + '</div>' +
          '</div>' +
          '<div class="wallet-ledger-amount' + amountCls + '">' + escapeHtml(amountText) + '</div>' +
        '</div>'
      );
    }).join('');
    if (moreBtn) moreBtn.style.display = ledgerState.hasMore ? '' : 'none';
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fetchLedger(page, append) {
    if (ledgerState.loading) return Promise.resolve();
    ledgerState.loading = true;
    var moreBtn = document.getElementById('profileLedgerMoreBtn');
    if (moreBtn && page > 1) moreBtn.disabled = true;
    return api('walletledger', { page: page, limit: 20 }).then(function (data) {
      var list = (data && data.list) || [];
      ledgerState.page = (data && data.page) || page;
      ledgerState.hasMore = !!(data && data.has_more);
      if (append) ledgerState.list = ledgerState.list.concat(list);
      else ledgerState.list = list;
      renderLedgerList();
    }).catch(function (e) {
      if (!append) {
        var box = document.getElementById('profileLedgerList');
        if (box) box.innerHTML = '<div class="wallet-ledger-empty">' + escapeHtml((e && e.message) || wt('wallet_load_fail', '加载失败')) + '</div>';
      } else {
        toast((e && e.message) || wt('wallet_load_fail', '加载失败'), 'error');
      }
    }).then(function () {
      ledgerState.loading = false;
      if (moreBtn) moreBtn.disabled = false;
    });
  }

  function loadWalletData() {
    return Promise.all([
      api('walletinfo', {}).catch(function () { return {}; }),
      api('rechargechannels', {}).catch(function () { return { list: [], partitions: [] }; }),
      api('withdrawchannels', {}).catch(function () { return { list: [], partitions: [], binds: {} }; })
    ]).then(function (res) {
      walletState.info = res[0] || {};
      walletState.recharge = (res[1] && res[1].list) || [];
      walletState.rechargePartitions = (res[1] && res[1].partitions) || [];
      walletState.withdraw = (res[2] && res[2].list) || [];
      walletState.withdrawPartitions = (res[2] && res[2].partitions) || [];
      walletState.binds = (res[2] && res[2].binds) || {};
      var hint = document.getElementById('profileWithdrawHint');
      if (hint && walletState.info) {
        var need = Math.max(walletState.info.withdraw_turnover_min || 0, 0);
        var ratio = walletState.info.withdraw_turnover_ratio || 1;
        hint.textContent = wt('wallet_turnover_need', '流水需≥{need}', { need: money(need) }) + (ratio > 0 ? wt('wallet_turnover_ratio_suffix', '，且不少于提现额×{ratio}', { ratio: ratio }) : '');
      }
      var bal = document.getElementById('profileWithdrawBalance');
      if (bal && walletState.info) {
        var hb = walletState.info.hongbao != null ? walletState.info.hongbao : walletState.info.balance;
        bal.textContent = money(hb);
      }
      var line = document.getElementById('profileTurnoverLine');
      if (line && walletState.info) line.textContent = wt('wallet_turnover_line', '累计流水：{amount}', { amount: money(walletState.info.turnover) });
    });
  }

  function openPayResult(payInfo) {
    if (!payInfo) return;
    if (payInfo.action === 'usdt' && payInfo.booking_address) {
      var lines = [
        payInfo.message || '请完成 USDT 转账',
        '地址：' + payInfo.booking_address,
        payInfo.pay_coin_amount ? ('数量：' + payInfo.pay_coin_amount + ' ' + (payInfo.coin_type || 'USDT')) : '',
        payInfo.order_expire_date ? ('有效期至：' + payInfo.order_expire_date) : ''
      ].filter(Boolean);
      alert(lines.join('\n'));
      return;
    }
    if (payInfo.action === 'form' && payInfo.url && payInfo.params) {
      var form = document.createElement('form');
      form.method = (payInfo.method || 'POST').toUpperCase();
      form.action = payInfo.url;
      form.target = '_blank';
      form.style.display = 'none';
      Object.keys(payInfo.params).forEach(function (k) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = k;
        inp.value = payInfo.params[k];
        form.appendChild(inp);
      });
      document.body.appendChild(form);
      form.submit();
      setTimeout(function () {
        try { document.body.removeChild(form); } catch (e) {}
      }, 1000);
      return;
    }
    if (payInfo.url) {
      window.open(payInfo.url, '_blank');
    }
  }

  global.openProfileWalletPage = function (which) {
    if (typeof global.closeProfileSubPage === 'function') global.closeProfileSubPage();
    var map = { recharge: 'profileRechargePane', withdraw: 'profileWithdrawPane', ledger: 'profileLedgerPane' };
    var id = map[which];
    var pane = document.getElementById(id);
    if (!pane) return;
    if (which === 'ledger') {
      ledgerState = { page: 1, loading: false, hasMore: false, list: [] };
      var box = document.getElementById('profileLedgerList');
      if (box) box.innerHTML = '<div class="wallet-ledger-empty">' + escapeHtml(wt('wallet_loading', '加载中…')) + '</div>';
      var moreBtn = document.getElementById('profileLedgerMoreBtn');
      if (moreBtn) moreBtn.style.display = 'none';
      pane.classList.add('open');
      pane.setAttribute('aria-hidden', 'false');
      if (typeof global.setBottomActionBarVisible === 'function') global.setBottomActionBarVisible(false);
      fetchLedger(1, false);
      return;
    }
    // 每次打开重置选中，先选通道再填金额
    if (which === 'recharge') {
      walletState.selectedRecharge = 0;
      walletState.rechargePartitionKey = '';
      walletState.rechargeExpanded = {};
    }
    if (which === 'withdraw') {
      walletState.selectedWithdraw = 0;
      walletState.withdrawPartitionKey = '';
      walletState.withdrawExpanded = {};
    }
    loadWalletData().then(function () {
      if (which === 'recharge') {
        renderChannels('profileRechargeChannels', walletState.recharge, 'recharge');
      } else {
        renderChannels('profileWithdrawChannels', walletState.withdraw, 'withdraw');
      }
      pane.classList.add('open');
      pane.setAttribute('aria-hidden', 'false');
      if (typeof global.setBottomActionBarVisible === 'function') global.setBottomActionBarVisible(false);
    }).catch(function () {});
  };

  var origOpen = global.openProfileSubPage;
  global.openProfileSubPage = function (which) {
    if (which === 'recharge' || which === 'withdraw' || which === 'ledger') {
      if (global.FansHubAssets && typeof global.FansHubAssets.ensureWallet === 'function') {
        global.FansHubAssets.ensureWallet().then(function () {
          global.openProfileWalletPage(which);
        }).catch(function (e) {
          toast((e && e.message) || wt('wallet_module_fail', '钱包模块加载失败'), 'error');
        });
        return;
      }
      global.openProfileWalletPage(which);
      return;
    }
    if (typeof origOpen === 'function') return origOpen(which);
  };

  var origClose = global.closeProfileSubPage;
  global.closeProfileSubPage = function () {
    ['profileRechargePane', 'profileWithdrawPane', 'profileLedgerPane'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) { el.classList.remove('open'); el.setAttribute('aria-hidden', 'true'); }
    });
    if (typeof origClose === 'function') return origClose();
  };

  global.loadProfileLedgerMore = function () {
    if (!ledgerState.hasMore || ledgerState.loading) return;
    fetchLedger((ledgerState.page | 0) + 1, true);
  };

  global.submitProfileRecharge = function () {
    var amount = parseFloat((document.getElementById('profileRechargeAmount') || {}).value) || 0;
    var cid = walletState.selectedRecharge | 0;
    var ch = findChannel(walletState.recharge, cid);
    var err = validateChannelAmount(ch, amount);
    if (err) {
      toast(err, 'error');
      return;
    }
    var btn = document.getElementById('profileRechargeSubmit');
    if (btn) btn.disabled = true;
    api('recharge', { channel_id: cid, amount: amount }).then(function (data) {
      var info = (data && data.pay_info) || {};
      toast((info.message) || wt('wallet_recharge_ok', '充值申请已提交'), 'success');
      openPayResult(info);
      if (typeof global.refreshProfile === 'function') global.refreshProfile();
    }).catch(function (e) {
      toast((e && e.message) || wt('wallet_fail', '失败'), 'error');
    }).then(function () {
      if (btn) btn.disabled = false;
    });
  };

  global.submitProfileWithdraw = function () {
    var amount = parseFloat((document.getElementById('profileWithdrawAmount') || {}).value) || 0;
    var cid = walletState.selectedWithdraw | 0;
    var ch = findChannel(walletState.withdraw, cid);
    var err = validateChannelAmount(ch, amount);
    if (err) {
      toast(err, 'error');
      return;
    }
    var isWalletBind = ch && String(ch.bind_mode || '') === 'wallet';
    var wtype = ch ? String(ch.wallet_type || ch.payment_channel || '') : '';
    var bind = isWalletBind ? ((walletState.binds && walletState.binds[wtype]) || null) : null;
    if (isWalletBind && !bind) {
      toast(wt('wallet_need_bind', '请先绑定该钱包地址'), 'error');
      syncWithdrawBindUI(ch);
      return;
    }

    var accountname = ((document.getElementById('profileWithdrawName') || {}).value || '').trim();
    var cardnumber = ((document.getElementById('profileWithdrawAccount') || {}).value || '').trim();
    var bankname = ((document.getElementById('profileWithdrawBank') || {}).value || '').trim();
    var subbranch = ((document.getElementById('profileWithdrawBranch') || {}).value || '').trim();
    var province = ((document.getElementById('profileWithdrawProvince') || {}).value || '').trim();
    var city = ((document.getElementById('profileWithdrawCity') || {}).value || '').trim();
    var payChanel = ((document.getElementById('profileWithdrawChanel') || {}).value || '102').trim();
    var accountInfo;
    if (isWalletBind) {
      accountInfo = {
        bind_id: bind.id,
        wallet_type: wtype,
        accountname: bind.account_name || '钱包用户',
        cardnumber: bind.account_no,
        account: bind.account_no,
        account_or_address: bind.account_no,
        bankname: bind.bank_name || wtype
      };
    } else {
      var isBsUsdt = ch && String(ch.handler || '').toLowerCase() === 'bs';
      if (isBsUsdt) {
        if (!cardnumber) {
          toast(wt('wallet_need_usdt_address', '请填写 USDT 收款地址'), 'error');
          return;
        }
        if (!accountname) accountname = 'USDT';
      } else if (!accountname || !cardnumber) {
        toast(wt('wallet_need_payee', '请填写收款人姓名与账号'), 'error');
        return;
      }
      if (!bankname) {
        bankname = (ch && ch.name) ? String(ch.name).replace(/(充值|代付|提现)$/, '') : '钱包';
      }
      accountInfo = {
        accountname: accountname,
        cardnumber: cardnumber,
        account: cardnumber,
        account_or_address: cardnumber,
        bankname: bankname,
        subbranch: subbranch,
        province: province,
        city: city,
        pay_chanel: payChanel
      };
    }
    var btn = document.getElementById('profileWithdrawSubmit');
    if (btn) btn.disabled = true;
    api('withdraw', {
      channel_id: cid,
      amount: amount,
      account_info: accountInfo
    }).then(function (data) {
      toast((data && data.message) || wt('wallet_withdraw_ok', '提现申请已提交'), 'success');
      if (data && data.url) window.open(data.url, '_blank');
      if (typeof global.refreshProfile === 'function') global.refreshProfile();
      loadWalletData();
    }).catch(function (e) {
      toast((e && e.message) || wt('wallet_fail', '失败'), 'error');
    }).then(function () {
      if (btn) btn.disabled = false;
    });
  };

  global.submitProfileWalletBind = function () {
    var cid = walletState.selectedWithdraw | 0;
    var ch = findChannel(walletState.withdraw, cid);
    if (!ch || String(ch.bind_mode || '') !== 'wallet') {
      toast(wt('wallet_need_channel', '请先选择通道'), 'error');
      return;
    }
    var wtype = String(ch.wallet_type || ch.payment_channel || '');
    var accountNo = ((document.getElementById('profileWithdrawBindAddress') || {}).value || '').trim();
    var accountName = ((document.getElementById('profileWithdrawBindName') || {}).value || '').trim();
    if (!accountNo) {
      toast(wt('wallet_bind_address_required', '请填写钱包地址'), 'error');
      return;
    }
    var btn = document.getElementById('profileWithdrawBindSubmit');
    if (btn) btn.disabled = true;
    api('bindwallet', {
      wallet_type: wtype,
      account_info: {
        account_no: accountNo,
        account_name: accountName || String(ch.name || '').replace(/(充值|代付|提现)$/, ''),
        bank_name: wtype,
        bind_mode: 'wallet'
      }
    }).then(function (data) {
      walletState.binds = (data && data.binds) || walletState.binds || {};
      toast(wt('wallet_bind_ok', '钱包地址已绑定'), 'success');
      var addrInput = document.getElementById('profileWithdrawBindAddress');
      if (addrInput) addrInput.value = '';
      syncWithdrawBindUI(ch);
    }).catch(function (e) {
      toast((e && e.message) || wt('wallet_fail', '失败'), 'error');
    }).then(function () {
      if (btn) btn.disabled = false;
    });
  };

  global.refreshProfileLedgerCopy = function () {
    if (ledgerState.list && ledgerState.list.length) {
      renderLedgerList();
    }
  };

  global.FansHubWallet = { loaded: true, reload: loadWalletData, refreshLedgerCopy: global.refreshProfileLedgerCopy };
})(window);
