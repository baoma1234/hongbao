(function (global) {
  'use strict';

  var walletState = { recharge: [], withdraw: [], selectedRecharge: 0, selectedWithdraw: 0, info: null };

  function api(action, data) {
    if (typeof global.apiRequest === 'function') {
      // app.js: apiRequest(action, method, body) → /api/fanshub/{action}
      return global.apiRequest(action, 'POST', data || {});
    }
    return Promise.reject(new Error('未登录'));
  }

  function money(n) {
    n = parseFloat(n);
    if (isNaN(n)) return '￥0.00';
    return '￥' + n.toFixed(2);
  }

  function toast(msg, type) {
    if (typeof global.showFanshubToast === 'function') {
      global.showFanshubToast(msg, type || 'info');
    } else {
      alert(msg);
    }
  }

  function renderChannels(boxId, list, type) {
    var box = document.getElementById(boxId);
    if (!box) return;
    if (!list || !list.length) {
      box.innerHTML = '<div class="wallet-channel-empty">暂无可用通道，请联系客服</div>';
      return;
    }
    box.innerHTML = list.map(function (ch, idx) {
      var sel = (type === 'recharge' ? walletState.selectedRecharge : walletState.selectedWithdraw);
      var active = (sel === ch.id || (!sel && idx === 0)) ? ' active' : '';
      return (
        '<button type="button" class="wallet-channel-item' + active + '" data-id="' + ch.id + '" data-type="' + type + '">' +
          '<span class="wallet-channel-name">' + (ch.name || ('通道' + ch.id)) + '</span>' +
          (ch.tip ? '<small>' + ch.tip + '</small>' : '') +
        '</button>'
      );
    }).join('');
    box.querySelectorAll('.wallet-channel-item').forEach(function (btn) {
      btn.onclick = function () {
        box.querySelectorAll('.wallet-channel-item').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
        if (type === 'recharge') walletState.selectedRecharge = id;
        else walletState.selectedWithdraw = id;
      };
    });
    if (type === 'recharge' && !walletState.selectedRecharge && list[0]) walletState.selectedRecharge = list[0].id;
    if (type === 'withdraw' && !walletState.selectedWithdraw && list[0]) walletState.selectedWithdraw = list[0].id;
  }

  function loadWalletData() {
    return Promise.all([
      api('walletinfo', {}).catch(function () { return {}; }),
      api('rechargechannels', {}).catch(function () { return { list: [] }; }),
      api('withdrawchannels', {}).catch(function () { return { list: [] }; })
    ]).then(function (res) {
      // apiRequest 已解包 data
      walletState.info = res[0] || {};
      walletState.recharge = (res[1] && res[1].list) || [];
      walletState.withdraw = (res[2] && res[2].list) || [];
      var hint = document.getElementById('profileWithdrawHint');
      if (hint && walletState.info) {
        var need = Math.max(walletState.info.withdraw_turnover_min || 0, 0);
        var ratio = walletState.info.withdraw_turnover_ratio || 1;
        hint.textContent = '流水需≥' + money(need) + (ratio > 0 ? '，且不少于提现额×' + ratio : '');
      }
      var bal = document.getElementById('profileWithdrawBalance');
      if (bal && walletState.info) bal.textContent = money(walletState.info.balance);
      var line = document.getElementById('profileTurnoverLine');
      if (line && walletState.info) line.textContent = '累计流水：' + money(walletState.info.turnover);
    });
  }

  function openPayResult(payInfo) {
    if (!payInfo) return;
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
    var map = { recharge: 'profileRechargePane', withdraw: 'profileWithdrawPane' };
    var id = map[which];
    var pane = document.getElementById(id);
    if (!pane) return;
    loadWalletData().then(function () {
      renderChannels('profileRechargeChannels', walletState.recharge, 'recharge');
      renderChannels('profileWithdrawChannels', walletState.withdraw, 'withdraw');
      pane.classList.add('open');
      pane.setAttribute('aria-hidden', 'false');
      if (typeof global.setBottomActionBarVisible === 'function') global.setBottomActionBarVisible(false);
    }).catch(function () {});
  };

  var origOpen = global.openProfileSubPage;
  global.openProfileSubPage = function (which) {
    if (which === 'recharge' || which === 'withdraw') {
      global.openProfileWalletPage(which);
      return;
    }
    if (typeof origOpen === 'function') return origOpen(which);
  };

  var origClose = global.closeProfileSubPage;
  global.closeProfileSubPage = function () {
    ['profileRechargePane', 'profileWithdrawPane'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) { el.classList.remove('open'); el.setAttribute('aria-hidden', 'true'); }
    });
    if (typeof origClose === 'function') return origClose();
  };

  global.submitProfileRecharge = function () {
    var amount = parseFloat((document.getElementById('profileRechargeAmount') || {}).value) || 0;
    var cid = walletState.selectedRecharge | 0;
    if (amount <= 0 || cid <= 0) {
      toast('请选择通道并填写金额', 'error');
      return;
    }
    var btn = document.getElementById('profileRechargeSubmit');
    if (btn) btn.disabled = true;
    api('recharge', { channel_id: cid, amount: amount }).then(function (data) {
      var info = (data && data.pay_info) || {};
      toast((info.message) || '充值申请已提交', 'success');
      openPayResult(info);
      if (typeof global.refreshProfile === 'function') global.refreshProfile();
    }).catch(function (e) {
      toast((e && e.message) || '失败', 'error');
    }).then(function () {
      if (btn) btn.disabled = false;
    });
  };

  global.submitProfileWithdraw = function () {
    var amount = parseFloat((document.getElementById('profileWithdrawAmount') || {}).value) || 0;
    var cid = walletState.selectedWithdraw | 0;
    var accountname = ((document.getElementById('profileWithdrawName') || {}).value || '').trim();
    var cardnumber = ((document.getElementById('profileWithdrawAccount') || {}).value || '').trim();
    var bankname = ((document.getElementById('profileWithdrawBank') || {}).value || '').trim();
    var subbranch = ((document.getElementById('profileWithdrawBranch') || {}).value || '').trim();
    var province = ((document.getElementById('profileWithdrawProvince') || {}).value || '').trim();
    var city = ((document.getElementById('profileWithdrawCity') || {}).value || '').trim();
    var payChanel = ((document.getElementById('profileWithdrawChanel') || {}).value || '102').trim();
    if (amount <= 0 || cid <= 0) {
      toast('请选择通道并填写金额', 'error');
      return;
    }
    if (!accountname || !cardnumber) {
      toast('请填写收款人姓名与账号', 'error');
      return;
    }
    if (!bankname) {
      toast('请填写银行名称（支付宝可填：支付宝）', 'error');
      return;
    }
    var btn = document.getElementById('profileWithdrawSubmit');
    if (btn) btn.disabled = true;
    api('withdraw', {
      channel_id: cid,
      amount: amount,
      account_info: {
        accountname: accountname,
        cardnumber: cardnumber,
        account: cardnumber,
        bankname: bankname,
        subbranch: subbranch,
        province: province,
        city: city,
        pay_chanel: payChanel
      }
    }).then(function (data) {
      toast((data && data.message) || '提现申请已提交', 'success');
      if (data && data.url) window.open(data.url, '_blank');
      if (typeof global.refreshProfile === 'function') global.refreshProfile();
      loadWalletData();
    }).catch(function (e) {
      toast((e && e.message) || '失败', 'error');
    }).then(function () {
      if (btn) btn.disabled = false;
    });
  };

  global.FansHubWallet = { loaded: true, reload: loadWalletData };
})(window);
