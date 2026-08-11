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
    rebinding: false,
    hasPayPassword: false
  };

  function syncHasPayPasswordFromProfile(profile) {
    if (profile && profile.has_pay_password != null) {
      walletState.hasPayPassword = !!profile.has_pay_password;
    }
  }

  function hasPayPassword() {
    if (walletState.hasPayPassword) return true;
    try {
      if (global.lastProfile && global.lastProfile.has_pay_password) return true;
    } catch (e) {}
    return false;
  }

  function setHasPayPasswordFlag(v) {
    walletState.hasPayPassword = !!v;
    try {
      if (global.lastProfile) global.lastProfile.has_pay_password = !!v;
    } catch (e2) {}
  }

  /** 弹层输入支付密码；首次设置时带确认框 */
  function promptPayPassword(opts) {
    opts = opts || {};
    var isSet = !!opts.forceSet || !hasPayPassword();
    return new Promise(function (resolve, reject) {
      var modal = document.getElementById('walletPayPwdModal');
      var title = document.getElementById('walletPayPwdTitle');
      var desc = document.getElementById('walletPayPwdDesc');
      var input = document.getElementById('walletPayPwdInput');
      var confirmWrap = document.getElementById('walletPayPwdConfirmWrap');
      var confirm = document.getElementById('walletPayPwdConfirm');
      var ok = document.getElementById('walletPayPwdOk');
      var cancel = document.getElementById('walletPayPwdCancel');
      if (!modal || !input || !ok) {
        reject(new Error(wt('api_pay_password_required', '请输入支付密码')));
        return;
      }
      if (title) title.textContent = isSet
        ? wt('profile_pay_password_set_title', '设置支付密码')
        : wt('profile_pay_password_enter_title', '请输入支付密码');
      if (desc) {
        desc.textContent = isSet
          ? wt('profile_pay_password_set_hint', '首次设置支付密码，用于提现与绑定地址')
          : '';
      }
      var inputLabel = document.getElementById('walletPayPwdInputLabel');
      if (inputLabel) inputLabel.textContent = wt('profile_pay_password_label', '支付密码');
      input.placeholder = isSet
        ? wt('profile_pay_password_ph', '6-32位支付密码')
        : wt('api_pay_password_required', '请输入支付密码');
      if (confirm) {
        confirm.placeholder = wt('wallet_paypwd_confirm_ph', '再次输入');
        var confLab = confirmWrap ? confirmWrap.querySelector('label') : null;
        if (confLab) confLab.textContent = wt('profile_pay_password_confirm_label', '确认支付密码');
      }
      if (ok) ok.textContent = wt('wallet_paypwd_ok', '确认');
      if (cancel) cancel.textContent = wt('wallet_paypwd_cancel', '取消');
      if (confirmWrap) confirmWrap.hidden = !isSet;
      input.value = '';
      if (confirm) confirm.value = '';
      modal.hidden = false;
      try { input.focus(); } catch (e0) {}

      function cleanup() {
        modal.hidden = true;
        ok.onclick = null;
        cancel.onclick = null;
      }
      cancel.onclick = function () {
        cleanup();
        reject(new Error('cancelled'));
      };
      ok.onclick = function () {
        var pwd = String(input.value || '');
        if (pwd.length < 6) {
          toast(wt('alert_password_short', '密码至少6位'), 'error');
          return;
        }
        if (isSet) {
          var c = String((confirm && confirm.value) || '');
          if (pwd !== c) {
            toast(wt('alert_password_mismatch', '两次密码不一致'), 'error');
            return;
          }
        }
        cleanup();
        resolve(pwd);
      };
    });
  }

  /** 确保已有支付密码并拿到本次输入，再执行业务 */
  function withPayPassword(run) {
    var needSet = !hasPayPassword();
    return promptPayPassword({ forceSet: needSet }).then(function (pwd) {
      if (needSet) {
        return api('setpaypassword', {
          pay_password: pwd,
          confirm_password: pwd
        }).then(function (data) {
          setHasPayPasswordFlag(true);
          if (data && data.profile && typeof global.applyProfile === 'function') {
            try { global.applyProfile(data.profile); } catch (e) {}
          }
          return run(pwd);
        });
      }
      return run(pwd);
    });
  }

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
    bobi: '波币',
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

  /** 有通道时默认选第一个（含钱包分区网格） */
  function pickDefaultChannel(list, useSelect, type) {
    var arr = flattenChannelList(list, type, useSelect);
    return arr.length ? arr[0] : null;
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

  function formatLimitPlaceholder(ch) {
    if (!ch) return wt('profile_amount_ph', '请输入金额');
    var min = Number(ch.min_amount) || 0;
    var max = Number(ch.max_amount) || 0;
    if (min > 0 && max > 0) {
      return wt('wallet_amount_ph_range', '限额 {min} ～ {max}', { min: money(min), max: money(max) });
    }
    if (min > 0) return wt('wallet_amount_ph_min', '请输入金额，最低 {min}', { min: money(min) });
    if (max > 0) return wt('wallet_amount_ph_max', '请输入金额，最高 {max}', { max: money(max) });
    return wt('profile_amount_ph', '请输入金额');
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
    el.placeholder = formatLimitPlaceholder(ch);
  }

  var RECHARGE_QUICK_AMOUNTS = [50, 100, 500, 1000, 5000, 10000, 50000, 100000];

  function formatQuickAmtLabel(n) {
    n = Number(n) || 0;
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function resolveRechargeQuickAmounts(ch) {
    var raw = ch && (ch.quick_amounts || ch.amounts || ch.fixed_amounts);
    var list = [];
    if (Array.isArray(raw) && raw.length) {
      list = raw.map(Number).filter(function (n) { return n > 0; }).slice(0, 16);
    } else if (typeof raw === 'string' && raw.trim()) {
      list = raw.split(/[,，\s]+/).map(Number).filter(function (n) { return n > 0; }).slice(0, 16);
    }
    return list.length ? list : RECHARGE_QUICK_AMOUNTS.slice();
  }

  function renderRechargeQuickAmounts(ch) {
    var box = document.getElementById('profileRechargeQuickAmounts');
    if (!box) return;
    var min = Number(ch && ch.min_amount) || 0;
    var max = Number(ch && ch.max_amount) || 0;
    var cur = parseFloat((document.getElementById('profileRechargeAmount') || {}).value) || 0;
    var amounts = resolveRechargeQuickAmounts(ch);
    box.innerHTML = amounts.map(function (a) {
      var disabled = (min > 0 && a < min - 0.00001) || (max > 0 && a > max + 0.00001);
      var active = !disabled && Math.abs(cur - a) < 0.00001 ? ' active' : '';
      return (
        '<button type="button" class="wallet-quick-amt' + active + (disabled ? ' is-disabled' : '') +
          '" data-amt="' + a + '"' + (disabled ? ' disabled' : '') + '>' +
          formatQuickAmtLabel(a) +
        '</button>'
      );
    }).join('');
    if (!box._quickBound) {
      box._quickBound = true;
      box.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest('.wallet-quick-amt') : null;
        if (!btn || btn.disabled) return;
        var amt = parseFloat(btn.getAttribute('data-amt')) || 0;
        var input = document.getElementById('profileRechargeAmount');
        if (input) {
          input.value = String(amt);
          try { input.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
        }
        [].forEach.call(box.querySelectorAll('.wallet-quick-amt'), function (b) {
          b.classList.toggle('active', b === btn);
        });
      });
    }
  }

  function isOnlineCoopChannel(ch) {
    return !!(ch && (String(ch.withdraw_mode || '') === 'online_coop' || String(ch.partition_code || '') === 'online_coop'));
  }

  function isOnlineCoopPartition(part) {
    return !!(part && String(part.code || '') === 'online_coop');
  }

  function channelExchangeRate(ch) {
    var r = ch ? Number(ch.exchange_rate) : 0;
    return r > 0 ? r : 0;
  }

  /** USDT 充值：界面按 U 输入并原样提交；人民币入账看网关回调 */
  function isUsdtRechargeChannel(ch) {
    if (!ch || !(channelExchangeRate(ch) > 0)) return false;
    if (String(ch.handler || '').toLowerCase() === 'bs') return true;
    var mix = String(ch.name || '') + ' ' + String(ch.payment_channel || '') + ' ' + String(ch.wallet_type || '') + ' ' + String(ch.coin_type || '');
    return /usdt/i.test(mix);
  }

  function syncRechargeAmountLabel(ch) {
    var lab = document.getElementById('profileRechargeAmountLabel');
    if (!lab) return;
    if (isUsdtRechargeChannel(ch)) {
      lab.textContent = wt('profile_recharge_amount_label_usdt', '充值红宝金额（U）');
    } else {
      lab.textContent = wt('profile_recharge_amount_label', '充值红宝金额（元）');
    }
  }

  function updateFxHint(type, ch, amount) {
    var el = document.getElementById(type === 'recharge' ? 'profileRechargeFxHint' : 'profileWithdrawFxHint');
    if (!el) return;
    var rate = channelExchangeRate(ch);
    if (!(rate > 0)) {
      el.hidden = true;
      el.textContent = '';
      return;
    }
    amount = Number(amount) || 0;
    el.hidden = false;
    if (type === 'recharge' && isUsdtRechargeChannel(ch)) {
      // 输入 U：rate CNY = 1 USDT，约合 amount*rate 人民币
      var cny = amount > 0 ? (amount * rate) : 0;
      el.textContent = amount > 0
        ? wt('wallet_fx_usdt_recharge', '{rate} CNY = 1 USDT，约合 {cny}人民币', {
            rate: String(rate),
            cny: cny.toFixed(2)
          })
        : wt('wallet_fx_usdt_recharge_idle', '{rate} CNY = 1 USDT（输入 U 数量后显示约合人民币）', {
            rate: String(rate)
          });
      return;
    }
    // 提现等：金额按红宝/人民币，换算应付 USDT
    var usdt = amount > 0 ? (amount / rate) : 0;
    el.textContent = amount > 0
      ? ('汇率 1 USDT = ' + rate + ' CNY，约合 ' + usdt.toFixed(4) + ' USDT')
      : ('汇率 1 USDT = ' + rate + ' CNY（输入金额后自动换算）');
  }

  function bindFxAmountInputs() {
    var rc = document.getElementById('profileRechargeAmount');
    if (rc && !rc._fxBound) {
      rc._fxBound = true;
      rc.addEventListener('input', function () {
        var ch = findChannel(walletState.recharge, walletState.selectedRecharge | 0);
        updateFxHint('recharge', ch, parseFloat(rc.value) || 0);
      });
    }
    var wd = document.getElementById('profileWithdrawAmount');
    if (wd && !wd._fxBound) {
      wd._fxBound = true;
      wd.addEventListener('input', function () {
        var ch = findChannel(walletState.withdraw, walletState.selectedWithdraw | 0);
        updateFxHint('withdraw', ch, parseFloat(wd.value) || 0);
      });
    }
  }

  function getApprovedMainUid() {
    var acc = (global.lastProfile) || global.account || {};
    if (!acc || typeof acc !== 'object') acc = {};
    var uid = String(acc.main_uid || '').trim();
    var audit = String(acc.main_uid_audit || '').trim();
    if (uid && audit === 'approved') return uid;
    return '';
  }

  function withdrawPayeeReady(ch) {
    if (!ch) return false;
    if (isOnlineCoopChannel(ch)) {
      return !!getApprovedMainUid();
    }
    if (String(ch.bind_mode || '') === 'wallet') {
      var wtype = String(ch.wallet_type || ch.payment_channel || '');
      return !!(walletState.binds && walletState.binds[wtype]);
    }
    var account = ((document.getElementById('profileWithdrawAccount') || {}).value || '').trim();
    var name = ((document.getElementById('profileWithdrawName') || {}).value || '').trim();
    var isBsUsdt = String(ch.handler || '').toLowerCase() === 'bs';
    if (isBsUsdt) return !!account;
    return !!(account && name);
  }

  function setWithdrawAmountGate(open) {
    var gate = document.getElementById('profileWithdrawAmountGate');
    if (!gate) return;
    if (open) {
      gate.removeAttribute('hidden');
      gate.style.display = '';
    } else {
      gate.setAttribute('hidden', 'hidden');
      gate.style.display = 'none';
    }
  }

  function syncWithdrawVerifyAddr(ch, bind) {
    var wrap = document.getElementById('profileWithdrawVerifyAddrWrap');
    var label = document.getElementById('profileWithdrawVerifyAddrLabel');
    var addrEl = document.getElementById('profileWithdrawVerifyAddr');
    if (!wrap || !addrEl) return;
    var addr = '';
    if (bind && bind.account_no) {
      addr = String(bind.account_no || '').trim();
    } else if (ch && String(ch.bind_mode || '') !== 'wallet') {
      addr = ((document.getElementById('profileWithdrawAccount') || {}).value || '').trim();
    }
    if (!addr) {
      wrap.hidden = true;
      addrEl.textContent = '-';
      return;
    }
    wrap.hidden = false;
    if (label) {
      label.textContent = walletAddressLabel(shortWalletName(ch));
    }
    addrEl.textContent = addr;
  }

  function syncWithdrawBindUI(ch) {
    var bindPanel = document.getElementById('profileWithdrawWalletBind');
    var convPanel = document.getElementById('profileWithdrawConventional');
    var coopPanel = document.getElementById('profileWithdrawOnlineCoop');
    var submitBtn = document.getElementById('profileWithdrawSubmit');
    if (!ch) {
      if (bindPanel) { bindPanel.hidden = true; }
      if (convPanel) { convPanel.style.display = 'none'; }
      if (coopPanel) { coopPanel.hidden = true; }
      setWithdrawAmountGate(false);
      syncWithdrawVerifyAddr(null, null);
      updateFxHint('withdraw', null, 0);
      return;
    }
    var isCoop = isOnlineCoopChannel(ch);
    var mode = String(ch.bind_mode || '');
    var isWallet = !isCoop && mode === 'wallet';
    if (bindPanel) bindPanel.hidden = !isWallet;
    if (convPanel) convPanel.style.display = (isWallet || isCoop) ? 'none' : '';
    if (coopPanel) coopPanel.hidden = !isCoop;

    if (isCoop) {
      var mainUid = getApprovedMainUid();
      var mainInput = document.getElementById('profileWithdrawMainUid');
      if (mainInput) mainInput.value = mainUid || '';
      var platSel = document.getElementById('profileWithdrawPlatform');
      if (platSel) {
        var plats = Array.isArray(ch.platforms) && ch.platforms.length ? ch.platforms : ['555'];
        var cur = platSel.value || '555';
        platSel.innerHTML = plats.map(function (p) {
          p = String(p);
          return '<option value="' + escapeHtml(p) + '"' + (p === cur || (cur === '' && p === '555') ? ' selected' : '') + '>' + escapeHtml(p) + '</option>';
        }).join('');
        if (!platSel.value) platSel.value = '555';
      }
      var readyCoop = !!mainUid;
      setWithdrawAmountGate(readyCoop);
      if (submitBtn) submitBtn.style.display = readyCoop ? '' : 'none';
      syncWithdrawVerifyAddr(null, null);
      updateFxHint('withdraw', ch, parseFloat((document.getElementById('profileWithdrawAmount') || {}).value) || 0);
      if (!readyCoop) {
        toast(wt('profile_withdraw_need_main_uid', '请先绑定并通过主站账号审核'), 'info');
      }
      return;
    }

    if (isWallet) {
      var wtype = String(ch.wallet_type || ch.payment_channel || '');
      // 严格按当前通道 wallet_type 取绑定，禁止回落到其它钱包地址
      var bind = (walletState.binds && walletState.binds[wtype]) || null;
      var cur = document.getElementById('profileWithdrawBindCurrent');
      var form = document.getElementById('profileWithdrawBindForm');
      var addr = document.getElementById('profileWithdrawBoundAddr');
      var boundLabel = document.getElementById('profileWithdrawBoundLabel');
      var hint = document.getElementById('profileWithdrawBindHint');
      var needBind = !bind;
      if (boundLabel) {
        boundLabel.textContent = walletAddressLabel(shortWalletName(ch));
      }
      if (cur) cur.hidden = !bind;
      if (addr) addr.textContent = bind ? (bind.account_no || '-') : '-';
      if (needBind) {
        if (hint) {
          hint.innerHTML = escapeHtml(wt(
            'wallet_bind_unbound_hint',
            '请先为该钱包绑定收款地址，每种钱包类型独立绑定，地址不可重复使用。'
          )) +
            ' <button type="button" class="wallet-go-payee-btn" id="profileWithdrawGoPayee">' +
            escapeHtml(wt('profile_menu_payee', '钱包地址')) + '</button>';
        }
        if (form) form.style.display = 'none';
        setWithdrawAmountGate(false);
        if (submitBtn) submitBtn.style.display = 'none';
        syncWithdrawVerifyAddr(ch, null);
        var goBtn = document.getElementById('profileWithdrawGoPayee');
        if (goBtn && !goBtn._bound) {
          goBtn._bound = true;
          goBtn.addEventListener('click', function (e) {
            e.preventDefault();
            toast(wt('wallet_bind_goto_payee', '请先在钱包地址中完成绑定'), 'info');
            global.openProfileSubPage('payee');
          });
        }
        updateFxHint('withdraw', ch, 0);
        return;
      }
      if (hint) {
        hint.textContent = wt(
          'wallet_bind_bound_hint',
          '该钱包收款地址已绑定，每种钱包类型独立绑定，地址不可重复使用。'
        );
      }
      if (form) form.style.display = 'none';
      setWithdrawAmountGate(true);
      if (submitBtn) submitBtn.style.display = '';
      syncWithdrawVerifyAddr(ch, bind);
      updateFxHint('withdraw', ch, parseFloat((document.getElementById('profileWithdrawAmount') || {}).value) || 0);
      return;
    }
    // 常规/银行：优先用已绑定的银行卡；先填收款信息，再解锁金额
    if (!isWallet) {
      var bankBind = walletState.binds && walletState.binds.BANK;
      var usdtBind = walletState.binds && (walletState.binds.BS_USDT_TRC20 || walletState.binds.USDT_TRC20);
      var nameEl = document.getElementById('profileWithdrawName');
      var accEl = document.getElementById('profileWithdrawAccount');
      var bankEl = document.getElementById('profileWithdrawBank');
      var isBsUsdtConv = String(ch.handler || '').toLowerCase() === 'bs';
      if (isBsUsdtConv && usdtBind && accEl && !String(accEl.value || '').trim()) {
        accEl.value = usdtBind.account_no || '';
        if (nameEl && !String(nameEl.value || '').trim()) nameEl.value = usdtBind.account_name || 'USDT';
        if (bankEl && !String(bankEl.value || '').trim()) bankEl.value = 'USDT-TRC20';
      }
      if (nameEl && !String(nameEl.value || '').trim()) {
        if (bankBind && bankBind.account_name) nameEl.value = bankBind.account_name;
      }
      if (accEl && !String(accEl.value || '').trim()) {
        if (bankBind && bankBind.account_no) {
          accEl.value = bankBind.account_no;
          if (bankEl && !String(bankEl.value || '').trim()) bankEl.value = bankBind.bank_name || '';
        }
      }
    }
    var ready = withdrawPayeeReady(ch);
    setWithdrawAmountGate(ready);
    if (submitBtn) submitBtn.style.display = ready ? '' : 'none';
    syncWithdrawVerifyAddr(ch, null);
    updateFxHint('withdraw', ch, parseFloat((document.getElementById('profileWithdrawAmount') || {}).value) || 0);
  }

  function bindWithdrawPayeeWatch() {
    ['profileWithdrawAccount', 'profileWithdrawName', 'profileWithdrawBank'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el || el._walletGateBound) return;
      el._walletGateBound = true;
      el.addEventListener('input', function () {
        var ch = findChannel(walletState.withdraw, walletState.selectedWithdraw | 0);
        if (!ch || String(ch.bind_mode || '') === 'wallet') return;
        syncWithdrawBindUI(ch);
      });
    });
  }

  function showAmountPanel(type, channelId) {
    var isRecharge = type === 'recharge';
    var list = isRecharge ? walletState.recharge : walletState.withdraw;
    var ch = findChannel(list, channelId);
    var panel = document.getElementById(isRecharge ? 'profileRechargeForm' : 'profileWithdrawForm');
    if (!panel) return;
    if (!ch) {
      panel.setAttribute('hidden', 'hidden');
      panel.style.display = 'none';
      panel.classList.remove('is-open');
      if (!isRecharge) setWithdrawAmountGate(false);
      return;
    }
    panel.removeAttribute('hidden');
    panel.style.display = 'block';
    panel.classList.add('is-open');
    applyAmountLimits(isRecharge ? 'profileRechargeAmount' : 'profileWithdrawAmount', ch);
    bindFxAmountInputs();
    if (isRecharge) {
      renderRechargeQuickAmounts(ch);
      syncRechargeAmountLabel(ch);
      updateFxHint('recharge', ch, parseFloat((document.getElementById('profileRechargeAmount') || {}).value) || 0);
    }
    if (!isRecharge) {
      bindWithdrawPayeeWatch();
      syncWithdrawBindUI(ch);
      var isCoop = isOnlineCoopChannel(ch);
      var isBsUsdt = ch && String(ch.handler || '').toLowerCase() === 'bs';
      var nameWrap = document.getElementById('profileWithdrawName');
      var nameField = nameWrap && nameWrap.closest ? nameWrap.closest('.profile-field') : null;
      var bankWrap = document.getElementById('profileWithdrawBankWrap');
      var branchWrap = document.getElementById('profileWithdrawBranchWrap');
      var regionWrap = document.getElementById('profileWithdrawRegionWrap');
      var accountInput = document.getElementById('profileWithdrawAccount');
      if (!isCoop && String(ch.bind_mode || '') !== 'wallet') {
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
          if (accountInput) accountInput.placeholder = wt('profile_withdraw_account_ph', '钱包地址 / 银行卡号');
        }
        var bank = document.getElementById('profileWithdrawBank');
        if (bank && !(bank.value || '').trim() && ch.name && !isBsUsdt) {
          bank.value = String(ch.name).replace(/(充值|代付|提现)$/, '') || ch.name;
        }
        // 银行名自动填后重新判断是否可解锁金额
        syncWithdrawBindUI(ch);
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

  function channelIconHtml(ch, name) {
    var icon = (ch && ch.icon || '').trim();
    // BS USDT：统一使用站内 Tether logo
    if (ch && String(ch.handler || '').toLowerCase() === 'bs') {
      icon = 'img/pay/usdt.png';
    }
    if (icon) {
      return '<img class="wallet-channel-icon" src="' + escapeHtml(icon) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">';
    }
    return '<span class="wallet-channel-icon wallet-channel-icon--placeholder">' +
      escapeHtml((name || '?').charAt(0)) + '</span>';
  }

  /** 简短展示名：NO钱包 / 234钱包 … */
  function shortWalletName(ch) {
    if (!ch) return '';
    if (String(ch.wallet_type || '') === 'USDT_MULTI' || (String(ch.handler || '').toLowerCase() === 'bs' && /usdt/i.test(String(ch.name || '') + String(ch.payment_channel || '')))) {
      return 'USDT钱包';
    }
    var info = walletChannelKey(ch.name, ch.payment_channel || ch.wallet_type);
    var key = String(info.key || '');
    var known = {
      no: 'NO钱包',
      '234': '234钱包',
      '808': '808钱包',
      '988': '988钱包',
      'k豆': 'K豆钱包',
      jd: 'JD钱包',
      'c币': 'C币钱包',
      ok: 'OK钱包',
      to: 'TO钱包',
      go: 'GO钱包',
      '万币': '万币钱包',
      bobi: '波币钱包',
      '波币': '波币钱包'
    };
    if (known[key]) return known[key];
    if (key && /^\d+$/.test(key)) return key + '钱包';
    if (key && WALLET_PIN_ORDER.indexOf(key) >= 0) {
      return String(key).toUpperCase() + '钱包';
    }
    var s = String(ch.name || '')
      .replace(/\s+/g, '')
      .replace(/(快捷)?(充值|代付|提现|支付)$/g, '')
      .replace(/收银台/g, '')
      .trim();
    if (!s) s = '钱包';
    if (!/钱包$/.test(s) && s.length <= 8) s += '钱包';
    return s;
  }

  /** 如「波币钱包地址」 */
  function walletAddressLabel(walletName) {
    var n = String(walletName || '').trim() || wt('profile_payee_tab_wallet', '数字钱包');
    if (/地址$/.test(n)) return n;
    return n + '地址';
  }

  function channelButtonHtml(ch, type, sel) {
    var active = sel === (ch.id | 0) ? ' active' : '';
    var isWallet = String(ch.bind_mode || '') === 'wallet'
      || /^(wanhuitong|bs)$/i.test(String(ch.handler || ''));
    var name = isWallet
      ? shortWalletName(ch)
      : (String(ch.name || '').replace(/(充值|代付|提现)$/g, '').trim() || ch.name
        || wt('wallet_channel_fallback', '通道{id}', { id: ch.id }));
    var iconHtml = channelIconHtml(ch, name);
    var boundHint = '';
    if (type === 'withdraw' && isWallet) {
      var wtype = String(ch.wallet_type || ch.payment_channel || '');
      if (wtype && walletState.binds && walletState.binds[wtype]) {
        boundHint = '<span class="wallet-channel-bound">' + escapeHtml(wt('wallet_bound_short', '已绑定')) + '</span>';
      }
    }
    return (
      '<button type="button" class="wallet-channel-item' + active + '" data-id="' + ch.id + '" data-type="' + type + '">' +
        iconHtml +
        '<span class="wallet-channel-meta">' +
          '<span class="wallet-channel-name">' + escapeHtml(name) + '</span>' +
          boundHint +
        '</span>' +
      '</button>'
    );
  }

  /** BS USDT 收银台充值：单独按钮，不进钱包下拉 */
  function isBsCashierRecharge(ch, type) {
    if (type !== 'recharge' || !ch) return false;
    if (String(ch.handler || '').toLowerCase() !== 'bs') return false;
    var mode = String(ch.recharge_mode || '').toLowerCase();
    if (mode === 'api') return false;
    if (mode === '' || mode === 'cashier') return true;
    return /收银台|cashier/i.test(String(ch.name || ''));
  }

  function splitWalletChannels(list, type) {
    var featured = [];
    var dropdown = [];
    (list || []).forEach(function (ch) {
      if (isBsCashierRecharge(ch, type)) featured.push(ch);
      else dropdown.push(ch);
    });
    var groups = organizeWalletChannels(dropdown);
    var ordered = groups.pinned.concat(groups.more);
    return { featured: featured, dropdown: ordered };
  }

  function flattenChannelList(list, type, useSelect) {
    if (!useSelect) return (list || []).slice();
    var split = splitWalletChannels(list, type);
    return split.featured.concat(split.dropdown);
  }

  var CHANNEL_GRID_VISIBLE = 8;

  function renderChannelGroupHtml(list, type, sel, partKey, useSelect) {
    var all = flattenChannelList(list, type, useSelect);
    if (!all.length) {
      return '<div class="wallet-channel-empty wallet-channel-empty--inline">' +
        escapeHtml(wt('wallet_partition_empty', '当前分区暂无可用通道')) + '</div>';
    }
    var expMap = type === 'recharge' ? walletState.rechargeExpanded : walletState.withdrawExpanded;
    var expanded = !!(expMap && expMap[partKey]);
    var shown = expanded || all.length <= CHANNEL_GRID_VISIBLE
      ? all
      : all.slice(0, CHANNEL_GRID_VISIBLE);
    var html = shown.map(function (ch) { return channelButtonHtml(ch, type, sel); }).join('');
    if (all.length > CHANNEL_GRID_VISIBLE) {
      html += (
        '<button type="button" class="wallet-channel-item wallet-channel-more-btn' + (expanded ? ' is-open' : '') +
          '" data-more="1" data-part-key="' + escapeHtml(partKey) + '" data-type="' + type + '">' +
          '<span class="wallet-channel-icon wallet-channel-icon--placeholder">' + (expanded ? '−' : '+') + '</span>' +
          '<span class="wallet-channel-meta"><span class="wallet-channel-name">' +
            escapeHtml(expanded ? wt('wallet_channel_less', '收起') : wt('wallet_channel_more', '更多')) +
          '</span></span>' +
        '</button>'
      );
    }
    return '<div class="wallet-channel-grid">' + html + '</div>';
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
      if (moreBtn) {
        ev.preventDefault();
        var pk = moreBtn.getAttribute('data-part-key') || 'part';
        var expMap = type === 'recharge' ? walletState.rechargeExpanded : walletState.withdrawExpanded;
        expMap[pk] = !expMap[pk];
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
      if (!btn || btn.getAttribute('data-more') === '1') return;
      ev.preventDefault();
      var cid = parseInt(btn.getAttribute('data-id'), 10) || 0;
      if (type === 'recharge') walletState.selectedRecharge = cid;
      else walletState.selectedWithdraw = cid;
      renderChannels(box.id, type === 'recharge' ? walletState.recharge : walletState.withdraw, type);
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
    var useSelect = !!(activePart && (activePart.code === 'wallet' || activePart.bind_mode === 'wallet'));

    if (!sel) {
      var def = pickDefaultChannel(chs, useSelect, type);
      if (def) {
        if (type === 'recharge') walletState.selectedRecharge = def.id | 0;
        else walletState.selectedWithdraw = def.id | 0;
        sel = def.id | 0;
      }
    } else if (!findChannel(chs, sel)) {
      var fallback = pickDefaultChannel(chs, useSelect, type);
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
    var coopCompact = type === 'withdraw' && (
      isOnlineCoopPartition(activePart)
      || (chs.length === 1 && isOnlineCoopChannel(chs[0]))
    );
    if (coopCompact && chs.length) {
      // 分区已是「线上合作」，不再渲染大通道卡片，直接进入表单
      var only = chs[0];
      walletState.selectedWithdraw = only.id | 0;
      sel = only.id | 0;
      box.innerHTML = '';
      box.hidden = true;
      box.style.display = 'none';
    } else if (!chs.length) {
      box.hidden = false;
      box.style.display = '';
      html = '<div class="wallet-channel-empty wallet-channel-empty--inline">' + escapeHtml(wt('wallet_partition_empty', '当前分区暂无可用通道')) + '</div>';
      box.innerHTML = html;
      bindChannelListClicks(box, type);
    } else {
      box.hidden = false;
      box.style.display = '';
      html = renderChannelGroupHtml(chs, type, sel, partKey, useSelect);
      box.innerHTML = html;
      bindChannelListClicks(box, type);
    }
    showAmountPanel(type, sel);
  }
  var ledgerState = { page: 1, loading: false, hasMore: false, list: [], category: 'all' };

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
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
      + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
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

  function ledgerTypeLabel(type, item) {
    type = String(type || '');
    if (!type) return wt('wallet_ledger_other', '其他');
    var key = 'wallet_ledger_type_' + type;
    var translated = wt(key, '');
    if (translated && translated !== key && translated !== '') return translated;
    if (item && item.type_label) {
      var lab = String(item.type_label);
      // 接口若仍回传英文 type 码，不要当展示文案
      if (lab && lab !== type) return lab;
    }
    return wt('wallet_ledger_other', '其他');
  }

  function syncLedgerFilterUi() {
    var box = document.getElementById('profileLedgerFilters');
    if (!box) return;
    var cat = ledgerState.category || 'all';
    Array.prototype.forEach.call(box.querySelectorAll('[data-ledger-cat]'), function (btn) {
      var on = String(btn.getAttribute('data-ledger-cat') || '') === cat;
      btn.classList.toggle('is-on', on);
    });
  }

  function bindLedgerFilters() {
    var box = document.getElementById('profileLedgerFilters');
    if (!box || box._bound) return;
    box._bound = true;
    box.addEventListener('click', function (ev) {
      var btn = ev.target && ev.target.closest ? ev.target.closest('[data-ledger-cat]') : null;
      if (!btn) return;
      var cat = String(btn.getAttribute('data-ledger-cat') || 'all');
      if (cat === (ledgerState.category || 'all')) return;
      ledgerState.category = cat;
      syncLedgerFilterUi();
      ledgerState.list = [];
      var listBox = document.getElementById('profileLedgerList');
      if (listBox) listBox.innerHTML = '<div class="wallet-ledger-empty">' + escapeHtml(wt('wallet_loading', '加载中…')) + '</div>';
      fetchLedger(1, false);
    });
  }

  function renderLedgerList() {
    var box = document.getElementById('profileLedgerList');
    var moreBtn = document.getElementById('profileLedgerMoreBtn');
    if (!box) return;
    if (!ledgerState.list.length) {
      var emptyTips = {
        rebate: '暂无红包返佣流水',
        hongbao_in: '暂无红宝入账流水',
        refund: '暂无红宝退回流水'
      };
      var emptyTip = emptyTips[ledgerState.category] || wt('wallet_ledger_empty', '暂无资金流水');
      box.innerHTML = '<div class="wallet-ledger-empty">' + escapeHtml(emptyTip) + '</div>';
      if (moreBtn) moreBtn.style.display = 'none';
      return;
    }
    box.innerHTML = ledgerState.list.map(function (item) {
      var rights = parseFloat(item.rights_change) || 0;
      var hb = parseFloat(item.hongbao_change) || 0;
      var bal = parseFloat(item.balance_change) || 0;
      // 旧流水 balance_change 并入红宝展示
      if (hb === 0 && bal !== 0) hb = bal;
      var amountCls = '';
      var amountText = '';
      if (hb !== 0) {
        amountText = fmtSignedHongbao(hb);
        amountCls = hb > 0 ? ' plus' : ' minus';
      } else if (rights !== 0) {
        amountText = (rights > 0 ? '+' : '') + rights.toFixed(2) + wt('wallet_unit_share', '股');
        amountCls = rights > 0 ? ' plus' : ' minus';
      } else {
        amountText = '0.00';
      }
      var subParts = [];
      if (hb !== 0 && rights !== 0) {
        subParts.push(fmtSignedRights(rights));
      }
      if (item.remark) subParts.push(item.remark);
      var afterHb = item.hongbao_after;
      if ((afterHb == null || afterHb === '') && hb !== 0 && item.balance_after != null) {
        afterHb = item.balance_after;
      }
      if (afterHb != null && afterHb !== '' && hb !== 0) {
        subParts.push(wt('wallet_unit_hongbao', '红宝') + ' ' + money(afterHb));
      }
      var typeStr = String(item.type || '');
      var titleText = ledgerTypeLabel(item.type, item) || wt('wallet_ledger_other', '其他');
      // 返佣类：标题用类型名，副标题保留备注（含旧「群聊管理津贴」文案）
      if (/rebate/i.test(typeStr) && item.remark) {
        subParts = subParts.filter(function (p) { return p !== item.remark; });
        subParts.unshift(String(item.remark));
      }
      return (
        '<div class="wallet-ledger-item">' +
          '<div class="wallet-ledger-main">' +
            '<div class="wallet-ledger-title">' + escapeHtml(titleText) + '</div>' +
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
    var body = { page: page, limit: 20 };
    if (ledgerState.category && ledgerState.category !== 'all') {
      body.category = ledgerState.category;
    }
    return api('walletledger', body).then(function (data) {
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
    var applyBundle = function (bundle) {
      walletState.info = (bundle && bundle.info) || {};
      var recharge = (bundle && bundle.recharge) || {};
      var withdraw = (bundle && bundle.withdraw) || {};
      walletState.recharge = recharge.list || [];
      walletState.rechargePartitions = recharge.partitions || [];
      walletState.withdraw = withdraw.list || [];
      walletState.withdrawPartitions = withdraw.partitions || [];
      walletState.binds = withdraw.binds || {};
      if (walletState.info && walletState.info.has_pay_password != null) {
        setHasPayPasswordFlag(!!walletState.info.has_pay_password);
      } else {
        syncHasPayPasswordFromProfile(global.lastProfile);
      }
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
      var frozenLine = document.getElementById('profileFrozenLine');
      var frozenBal = document.getElementById('profileFrozenBalance');
      if (frozenLine && frozenBal && walletState.info) {
        var fr = Math.max(0, parseFloat(walletState.info.hongbao_frozen) || 0);
        frozenBal.textContent = money(fr);
        frozenLine.hidden = fr <= 0.00001;
      }
      var line = document.getElementById('profileTurnoverLine');
      if (line && walletState.info) line.textContent = wt('wallet_turnover_line', '累计流水：{amount}', { amount: money(walletState.info.turnover) });
    };

    // 60s 内复用，避免反复开钱包打 3 次接口
    if (walletState._bootAt && walletState._bootBundle && (Date.now() - walletState._bootAt) < 60000) {
      applyBundle(walletState._bootBundle);
      return Promise.resolve(walletState._bootBundle);
    }

    return api('walletbootstrap', {}).then(function (bundle) {
      walletState._bootBundle = bundle || {};
      walletState._bootAt = Date.now();
      applyBundle(walletState._bootBundle);
      return walletState._bootBundle;
    }).catch(function () {
      // 旧接口兜底
      return Promise.all([
        api('walletinfo', {}).catch(function () { return {}; }),
        api('rechargechannels', {}).catch(function () { return { list: [], partitions: [] }; }),
        api('withdrawchannels', {}).catch(function () { return { list: [], partitions: [], binds: {} }; })
      ]).then(function (res) {
        var bundle = { info: res[0] || {}, recharge: res[1] || {}, withdraw: res[2] || {} };
        walletState._bootBundle = bundle;
        walletState._bootAt = Date.now();
        applyBundle(bundle);
        return bundle;
      });
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
    var map = {
      recharge: 'profileRechargePane',
      withdraw: 'profileWithdrawPane',
      ledger: 'profileLedgerPane',
      payee: 'profilePayeePane'
    };
    var id = map[which];
    var pane = document.getElementById(id);
    if (!pane) return;
    if (which === 'ledger') {
      ledgerState = { page: 1, loading: false, hasMore: false, list: [], category: 'all' };
      var box = document.getElementById('profileLedgerList');
      if (box) box.innerHTML = '<div class="wallet-ledger-empty">' + escapeHtml(wt('wallet_loading', '加载中…')) + '</div>';
      var moreBtn = document.getElementById('profileLedgerMoreBtn');
      if (moreBtn) moreBtn.style.display = 'none';
      bindLedgerFilters();
      syncLedgerFilterUi();
      pane.classList.add('open');
      pane.setAttribute('aria-hidden', 'false');
      if (typeof global.setBottomActionBarVisible === 'function') global.setBottomActionBarVisible(false);
      fetchLedger(1, false);
      return;
    }
    if (which === 'payee') {
      pane.classList.add('open');
      pane.setAttribute('aria-hidden', 'false');
      if (typeof global.setBottomActionBarVisible === 'function') global.setBottomActionBarVisible(false);
      loadWalletData().then(function () {
        initPayeePage();
      }).catch(function () {
        initPayeePage();
      });
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
    if (which === 'recharge' || which === 'withdraw' || which === 'ledger' || which === 'payee') {
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
    ['profileRechargePane', 'profileWithdrawPane', 'profileLedgerPane', 'profilePayeePane'].forEach(function (id) {
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
    // USDT 通道：界面填 U，提交也是 U（如 50）；入账人民币由网关回调按汇率换算（如 50×7=350）
    if (!(amount > 0)) {
      toast(wt('wallet_need_channel_amount', '请选择通道并填写金额'), 'error');
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
    var isCoop = isOnlineCoopChannel(ch);
    var isWalletBind = !isCoop && ch && String(ch.bind_mode || '') === 'wallet';
    var wtype = ch ? String(ch.wallet_type || ch.payment_channel || '') : '';
    var bind = isWalletBind ? ((walletState.binds && walletState.binds[wtype]) || null) : null;
    if (isWalletBind && !bind) {
      toast(wt('wallet_need_bind', '请先绑定该钱包地址'), 'error');
      syncWithdrawBindUI(ch);
      return;
    }

    var accountInfo;
    if (isCoop) {
      var mainUid = getApprovedMainUid();
      if (!mainUid) {
        toast(wt('profile_withdraw_need_main_uid', '请先绑定并通过主站账号审核'), 'error');
        return;
      }
      var platform = ((document.getElementById('profileWithdrawPlatform') || {}).value || '555').trim() || '555';
      accountInfo = {
        method: 'online_coop',
        withdraw_mode: 'online_coop',
        platform: platform,
        main_uid: mainUid,
        account: mainUid,
        account_or_address: mainUid,
        accountname: '线上合作-' + platform,
        cardnumber: mainUid,
        bankname: '线上合作/' + platform
      };
    } else if (isWalletBind) {
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
      var accountname = ((document.getElementById('profileWithdrawName') || {}).value || '').trim();
      var cardnumber = ((document.getElementById('profileWithdrawAccount') || {}).value || '').trim();
      var bankname = ((document.getElementById('profileWithdrawBank') || {}).value || '').trim();
      var subbranch = ((document.getElementById('profileWithdrawBranch') || {}).value || '').trim();
      var province = ((document.getElementById('profileWithdrawProvince') || {}).value || '').trim();
      var city = ((document.getElementById('profileWithdrawCity') || {}).value || '').trim();
      var payChanel = ((document.getElementById('profileWithdrawChanel') || {}).value || '102').trim();
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
    withPayPassword(function (payPwd) {
      return api('withdraw', {
        channel_id: cid,
        amount: amount,
        account_info: accountInfo,
        pay_password: payPwd
      });
    }).then(function (data) {
      toast((data && data.message) || wt('wallet_withdraw_ok', '提现申请已提交，等待人工审核'), 'success');
      if (data && data.url) window.open(data.url, '_blank');
      if (typeof global.refreshProfile === 'function') global.refreshProfile();
      loadWalletData();
    }).catch(function (e) {
      if (e && String(e.message || '') === 'cancelled') return;
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
    withPayPassword(function (payPwd) {
      return api('bindwallet', {
        wallet_type: wtype,
        account_info: {
          account_no: accountNo,
          account_name: accountName || String(ch.name || '').replace(/(充值|代付|提现)$/, ''),
          bank_name: wtype,
          bind_mode: 'wallet'
        },
        pay_password: payPwd
      });
    }).then(function (data) {
      walletState.binds = (data && data.binds) || walletState.binds || {};
      toast(wt('wallet_bind_ok', '钱包地址已绑定'), 'success');
      var addrInput = document.getElementById('profileWithdrawBindAddress');
      if (addrInput) addrInput.value = '';
      syncWithdrawBindUI(ch);
    }).catch(function (e) {
      if (e && String(e.message || '') === 'cancelled') return;
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

  var payeeState = { tab: 'bank', selectedWalletType: '', walletTypes: [] };

  var USDT_BIND_CHAINS = [
    { chain: 'TRC20', wallet_type: 'BS_USDT_TRC20', inputId: 'profilePayeeUsdtTrc20', label: 'TRC20' },
    { chain: 'ERC20', wallet_type: 'BS_USDT_ERC20', inputId: 'profilePayeeUsdtErc20', label: 'ERC20' },
    { chain: 'TON', wallet_type: 'BS_USDT_TON', inputId: 'profilePayeeUsdtTon', label: 'TON' }
  ];

  function isUsdtMultiType(wtype) {
    return String(wtype || '') === 'USDT_MULTI';
  }

  function usdtBindsStatus() {
    var binds = walletState.binds || {};
    var ok = 0;
    var parts = [];
    USDT_BIND_CHAINS.forEach(function (c) {
      var b = binds[c.wallet_type];
      if (b && b.account_no) {
        ok += 1;
        parts.push(c.label + ':' + b.account_no);
      }
    });
    return { ok: ok, total: USDT_BIND_CHAINS.length, complete: ok === USDT_BIND_CHAINS.length, summary: parts.join(' / ') };
  }

  function payeeTypeMeta(kind) {
    var map = {
      bank: { wallet_type: 'BANK', bind_mode: 'bank', label: wt('profile_payee_tab_bank', '银行卡') },
      alipay: { wallet_type: 'ALIPAY', bind_mode: 'alipay', label: wt('profile_payee_tab_alipay', '支付宝') },
      wechat: { wallet_type: 'WECHAT', bind_mode: 'wechat', label: wt('profile_payee_tab_wechat', '微信') }
    };
    return map[kind] || null;
  }

  /** 与充值「钱包地址」分区同序；USDT 多链置前（与充值 BS 展示一致） */
  function collectPayeeWalletTypes() {
    var seen = {};
    var out = [];
    var walletChs = [];
    (walletState.rechargePartitions || []).forEach(function (p) {
      if (p && (String(p.code || '') === 'wallet' || String(p.bind_mode || '') === 'wallet')) {
        walletChs = (p.channels || []).slice();
      }
    });
    if (!walletChs.length) {
      (walletState.withdrawPartitions || []).forEach(function (p) {
        if (p && (String(p.code || '') === 'wallet' || String(p.bind_mode || '') === 'wallet')) {
          walletChs = (p.channels || []).slice();
        }
      });
    }
    var list = walletChs.slice();
    list.unshift({
      id: 0,
      name: 'USDT钱包',
      handler: 'bs',
      bind_mode: 'wallet',
      wallet_type: 'USDT_MULTI',
      payment_channel: 'USDT',
      recharge_mode: 'cashier',
      icon: 'img/pay/usdt.png'
    });
    var ordered = flattenChannelList(list, 'recharge', true);
    ordered.forEach(function (ch) {
      var wt0 = String(ch.wallet_type || ch.payment_channel || '').trim();
      if (!wt0 || seen[wt0]) return;
      if (String(ch.handler || '').toLowerCase() === 'bs' && !isUsdtMultiType(wt0)) return;
      seen[wt0] = true;
      out.push({
        wallet_type: wt0,
        name: shortWalletName(ch),
        icon: ch.icon || '',
        channel: ch,
        multi: isUsdtMultiType(wt0)
      });
    });
    return out;
  }

  function fillPayeeBoundHints() {
    [['bank', 'profilePayeeBankBound']].forEach(function (pair) {
      var meta = payeeTypeMeta(pair[0]);
      var el = document.getElementById(pair[1]);
      if (!meta || !el) return;
      var bind = walletState.binds && walletState.binds[meta.wallet_type];
      if (!bind) {
        el.hidden = true;
        el.textContent = '';
        return;
      }
      el.hidden = false;
      el.textContent = wt('wallet_bound_short', '已绑定') + '：' + (bind.account_no || '') +
        (bind.account_name ? (' / ' + bind.account_name) : '') +
        (bind.bank_name ? (' / ' + bind.bank_name) : '');
    });
  }

  function renderPayeeWalletTypes() {
    var box = document.getElementById('profilePayeeWalletTypes');
    if (!box) return;
    payeeState.walletTypes = collectPayeeWalletTypes();
    if (!payeeState.walletTypes.length) {
      box.innerHTML = '<div class="wallet-channel-empty">' + escapeHtml(wt('wallet_partition_empty', '暂无可用数字钱包类型')) + '</div>';
      return;
    }
    var sel = payeeState.selectedWalletType;
    var VISIBLE = 8;
    var expanded = !!walletState.rechargeExpanded.__payee_wallet__;
    var all = payeeState.walletTypes;
    var shown = expanded || all.length <= VISIBLE ? all : all.slice(0, VISIBLE);
    var html = shown.map(function (item) {
      var active = sel === item.wallet_type ? ' active' : '';
      var bound = false;
      if (item.multi) {
        bound = usdtBindsStatus().ok > 0;
      } else {
        bound = !!(walletState.binds && walletState.binds[item.wallet_type]);
      }
      var ch = item.channel || { name: item.name, icon: item.icon, wallet_type: item.wallet_type, handler: item.multi ? 'bs' : '' };
      return (
        '<button type="button" class="wallet-channel-item' + active + '" data-payee-wtype="' + escapeHtml(item.wallet_type) + '">' +
          channelIconHtml(ch, item.name) +
          '<span class="wallet-channel-meta">' +
            '<span class="wallet-channel-name">' + escapeHtml(item.name) + '</span>' +
            (bound ? '<span class="wallet-channel-bound">' + escapeHtml(wt('wallet_bound_short', '已绑定')) + '</span>' : '') +
          '</span>' +
        '</button>'
      );
    }).join('');
    if (all.length > VISIBLE) {
      html += (
        '<button type="button" class="wallet-channel-item wallet-channel-more-btn' + (expanded ? ' is-open' : '') +
          '" data-payee-more="1">' +
          '<span class="wallet-channel-icon wallet-channel-icon--placeholder">' + (expanded ? '−' : '+') + '</span>' +
          '<span class="wallet-channel-meta"><span class="wallet-channel-name">' +
            escapeHtml(expanded ? wt('wallet_channel_less', '收起') : wt('wallet_channel_more', '更多')) +
          '</span></span></button>'
      );
    }
    box.innerHTML = html;
    box.classList.add('is-grid');
    if (!box._payeeClickBound) {
      box._payeeClickBound = true;
      box.addEventListener('click', function (ev) {
        var more = ev.target && ev.target.closest && ev.target.closest('[data-payee-more]');
        if (more) {
          walletState.rechargeExpanded.__payee_wallet__ = !walletState.rechargeExpanded.__payee_wallet__;
          renderPayeeWalletTypes();
          return;
        }
        var btn = ev.target && ev.target.closest && ev.target.closest('[data-payee-wtype]');
        if (!btn) return;
        payeeState.selectedWalletType = btn.getAttribute('data-payee-wtype') || '';
        renderPayeeWalletTypes();
        syncPayeeWalletForm();
      });
    }
  }

  function syncPayeeWalletForm() {
    var form = document.getElementById('profilePayeeWalletForm');
    var wtype = payeeState.selectedWalletType;
    if (!form) return;
    if (!wtype) {
      form.hidden = true;
      return;
    }
    form.hidden = false;
    var label = document.getElementById('profilePayeeWalletTypeLabel');
    var boundLine = document.getElementById('profilePayeeWalletBoundLine');
    var boundAddr = document.getElementById('profilePayeeWalletBoundAddr');
    var boundLabel = document.getElementById('profilePayeeWalletBoundLabel');
    var addrLabel = document.getElementById('profilePayeeWalletAddressLabel');
    var singleBox = document.getElementById('profilePayeeWalletSingleFields');
    var usdtBox = document.getElementById('profilePayeeUsdtChainFields');
    var item = null;
    payeeState.walletTypes.forEach(function (x) {
      if (x.wallet_type === wtype) item = x;
    });
    var wname = (item && item.name) || shortWalletName({ name: wtype, wallet_type: wtype }) || wtype;
    if (label) label.textContent = wname;
    if (boundLabel) boundLabel.textContent = walletAddressLabel(wname) + '：';
    if (addrLabel) addrLabel.textContent = walletAddressLabel(wname);

    var multi = isUsdtMultiType(wtype) || !!(item && item.multi);
    if (singleBox) singleBox.hidden = !!multi;
    if (usdtBox) usdtBox.hidden = !multi;

    if (multi) {
      var st = usdtBindsStatus();
      if (boundLine) boundLine.hidden = st.ok === 0;
      if (boundAddr) {
        boundAddr.textContent = st.complete
          ? st.summary
          : (st.ok ? (wt('profile_payee_usdt_partial', '已绑定 {n}/3', { n: st.ok }) + ' · ' + st.summary) : '-');
      }
      USDT_BIND_CHAINS.forEach(function (c) {
        var el = document.getElementById(c.inputId);
        var b = walletState.binds && walletState.binds[c.wallet_type];
        if (el) el.value = b ? (b.account_no || '') : '';
      });
      var nameEl = document.getElementById('profilePayeeUsdtName');
      var firstBind = null;
      USDT_BIND_CHAINS.forEach(function (c) {
        if (!firstBind && walletState.binds && walletState.binds[c.wallet_type]) {
          firstBind = walletState.binds[c.wallet_type];
        }
      });
      if (nameEl) nameEl.value = firstBind ? (firstBind.account_name || '') : '';
      return;
    }

    var bind = walletState.binds && walletState.binds[wtype];
    if (boundLine) boundLine.hidden = !bind;
    if (boundAddr) boundAddr.textContent = bind ? (bind.account_no || '-') : '-';
    var addr = document.getElementById('profilePayeeWalletAddress');
    var name = document.getElementById('profilePayeeWalletName');
    if (addr) addr.value = bind ? (bind.account_no || '') : '';
    if (name) name.value = bind ? (bind.account_name || '') : '';
  }

  function switchPayeeTab(tab) {
    payeeState.tab = tab || 'bank';
    var tabs = document.getElementById('profilePayeeTabs');
    if (tabs) {
      [].forEach.call(tabs.querySelectorAll('.wallet-payee-tab'), function (btn) {
        btn.classList.toggle('active', btn.getAttribute('data-payee-tab') === payeeState.tab);
      });
    }
    ['bank', 'wallet'].forEach(function (k) {
      var panel = document.querySelector('[data-payee-panel="' + k + '"]');
      if (panel) panel.hidden = k !== payeeState.tab;
    });
    fillPayeeBoundHints();
    if (payeeState.tab === 'wallet') {
      renderPayeeWalletTypes();
      syncPayeeWalletForm();
    }
  }

  function initPayeePage() {
    var tabs = document.getElementById('profilePayeeTabs');
    if (tabs && !tabs._payeeTabBound) {
      tabs._payeeTabBound = true;
      tabs.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest && ev.target.closest('.wallet-payee-tab');
        if (!btn) return;
        switchPayeeTab(btn.getAttribute('data-payee-tab') || 'bank');
      });
    }
    // 预填常规绑定
    var bank = walletState.binds && walletState.binds.BANK;
    var ali = walletState.binds && walletState.binds.ALIPAY;
    var wx = walletState.binds && walletState.binds.WECHAT;
    var setVal = function (id, v) {
      var el = document.getElementById(id);
      if (el) el.value = v || '';
    };
    if (bank) {
      setVal('profilePayeeBankAccountName', bank.account_name);
      setVal('profilePayeeBankAccountNo', bank.account_no);
      setVal('profilePayeeBankName', bank.bank_name);
    }
    if (ali) {
      setVal('profilePayeeAlipayName', ali.account_name);
      setVal('profilePayeeAlipayNo', ali.account_no);
    }
    if (wx) {
      setVal('profilePayeeWechatName', wx.account_name);
      setVal('profilePayeeWechatNo', wx.account_no);
    }
    switchPayeeTab(payeeState.tab || 'bank');
  }

  global.submitProfilePayeeBind = function (kind) {
    kind = String(kind || payeeState.tab || 'bank');
    var payload = { bind_mode: kind };
    var walletType = '';
    if (kind === 'bank') {
      walletType = 'BANK';
      payload.account_name = ((document.getElementById('profilePayeeBankAccountName') || {}).value || '').trim();
      payload.account_no = ((document.getElementById('profilePayeeBankAccountNo') || {}).value || '').trim();
      payload.bank_name = ((document.getElementById('profilePayeeBankName') || {}).value || '').trim();
      payload.bind_mode = 'bank';
      if (!payload.account_no || !payload.account_name) {
        toast(wt('profile_payee_bank_incomplete', '请填写开户名与银行卡号'), 'error');
        return;
      }
    } else if (kind === 'alipay') {
      walletType = 'ALIPAY';
      payload.account_name = ((document.getElementById('profilePayeeAlipayName') || {}).value || '').trim();
      payload.account_no = ((document.getElementById('profilePayeeAlipayNo') || {}).value || '').trim();
      payload.bank_name = '支付宝';
      payload.bind_mode = 'alipay';
      if (!payload.account_no || !payload.account_name) {
        toast(wt('profile_payee_alipay_incomplete', '请填写支付宝实名与账号'), 'error');
        return;
      }
    } else if (kind === 'wechat') {
      walletType = 'WECHAT';
      payload.account_name = ((document.getElementById('profilePayeeWechatName') || {}).value || '').trim();
      payload.account_no = ((document.getElementById('profilePayeeWechatNo') || {}).value || '').trim();
      payload.bank_name = '微信';
      payload.bind_mode = 'wechat';
      if (!payload.account_no) {
        toast(wt('profile_payee_wechat_incomplete', '请填写微信号或收款账号'), 'error');
        return;
      }
    } else {
      walletType = payeeState.selectedWalletType;
      if (!walletType) {
        toast(wt('wallet_need_channel', '请先选择钱包'), 'error');
        return;
      }
      if (isUsdtMultiType(walletType)) {
        var usdtName = ((document.getElementById('profilePayeeUsdtName') || {}).value || '').trim();
        var chainPayloads = [];
        for (var i = 0; i < USDT_BIND_CHAINS.length; i++) {
          var c = USDT_BIND_CHAINS[i];
          var no = ((document.getElementById(c.inputId) || {}).value || '').trim();
          if (!no) continue;
          if (no.length < 6) {
            toast(wt('profile_payee_usdt_invalid', '{chain} 地址格式不正确', { chain: c.label }), 'error');
            return;
          }
          chainPayloads.push({
            wallet_type: c.wallet_type,
            account_info: {
              account_no: no,
              account_name: usdtName,
              bind_mode: 'wallet'
            },
            bind_mode: 'wallet'
          });
        }
        if (!chainPayloads.length) {
          toast(wt('profile_payee_usdt_need_one', '请至少填写一条 USDT 地址（TRC20 / ERC20 / TON）'), 'error');
          return;
        }
        var btn = document.querySelector('#profilePayeeWalletForm .btn-uid-submit');
        if (btn) btn.disabled = true;
        withPayPassword(function (payPwd) {
          var seq = Promise.resolve();
          chainPayloads.forEach(function (p) {
            p.pay_password = payPwd;
            seq = seq.then(function () {
              return api('bindwallet', p).then(function (data) {
                if (data && data.binds) walletState.binds = data.binds;
              });
            });
          });
          return seq;
        }).then(function () {
          toast(wt('wallet_bind_ok', '绑定成功'), 'success');
          initPayeePage();
        }).catch(function (e) {
          if (e && String(e.message || '') === 'cancelled') return;
          toast((e && e.message) || wt('wallet_fail', '失败'), 'error');
        }).then(function () {
          if (btn) btn.disabled = false;
        });
        return;
      }
      payload.account_no = ((document.getElementById('profilePayeeWalletAddress') || {}).value || '').trim();
      payload.account_name = ((document.getElementById('profilePayeeWalletName') || {}).value || '').trim();
      payload.bind_mode = 'wallet';
      if (!payload.account_no || payload.account_no.length < 6) {
        toast(wt('wallet_bind_address_invalid', '钱包地址格式不正确'), 'error');
        return;
      }
    }
    withPayPassword(function (payPwd) {
      return api('bindwallet', {
        wallet_type: walletType,
        account_info: payload,
        bind_mode: payload.bind_mode,
        pay_password: payPwd
      });
    }).then(function (data) {
      walletState.binds = (data && data.binds) || walletState.binds || {};
      toast(wt('wallet_bind_ok', '绑定成功'), 'success');
      initPayeePage();
    }).catch(function (e) {
      if (e && String(e.message || '') === 'cancelled') return;
      toast((e && e.message) || wt('wallet_fail', '失败'), 'error');
    });
  };

  // 提现未绑定：点「去绑定」
  (function bindGoPayeeOnce() {
    var panel = document.getElementById('profileWithdrawWalletBind');
    if (!panel || panel._goPayeeBound) return;
    panel._goPayeeBound = true;
    panel.addEventListener('click', function (ev) {
      var btn = ev.target && (ev.target.id === 'profileWithdrawGoPayee'
        ? ev.target
        : (ev.target.closest && ev.target.closest('#profileWithdrawGoPayee')));
      if (!btn) return;
      ev.preventDefault();
      toast(wt('wallet_bind_goto_payee', '请先在钱包地址中完成绑定'), 'info');
      global.openProfileSubPage('payee');
    });
  })();

  global.FansHubWallet = { loaded: true, reload: loadWalletData, refreshLedgerCopy: global.refreshProfileLedgerCopy };
})(window);
