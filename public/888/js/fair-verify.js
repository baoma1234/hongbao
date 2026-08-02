(function () {
  var D = 'div';
  function tag(name, cls, inner) {
    return '<' + name + (cls ? ' class="' + cls + '"' : '') + '>' + (inner || '') + '</' + name + '>';
  }
  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function apiBase() {
    var path = location.pathname || '/';
    var idx = path.indexOf('/888/');
    if (idx >= 0) return location.origin + path.slice(0, idx) + '/';
    return location.origin + '/';
  }
  function readQuery() {
    var q = new URLSearchParams(location.search);
    var no = q.get('packet_no') || q.get('no') || '';
    if (no && $('packetNo')) $('packetNo').value = no;
  }
  function homeBase() {
    var path = location.pathname || '/';
    var idx = path.indexOf('/888/');
    if (idx >= 0) return location.origin + path.slice(0, idx) + '/888/';
    return location.origin + '/888/';
  }
  function resolveReturnPacketId() {
    var q = new URLSearchParams(location.search);
    var pid = parseInt(q.get('packet_id') || q.get('pid') || '0', 10) || 0;
    if (pid > 0) return pid;
    try {
      var raw = sessionStorage.getItem('fans_hub_rp_fair_return');
      if (!raw) return 0;
      var j = JSON.parse(raw);
      if (!j) return 0;
      if (j.at && (Date.now() - (j.at | 0)) > 2 * 3600 * 1000) return 0;
      return (j.packetId | 0) || 0;
    } catch (e) {
      return 0;
    }
  }
  function goBackToRpDetail() {
    var pid = resolveReturnPacketId();
    var ref = '';
    try { ref = String(document.referrer || ''); } catch (e0) { ref = ''; }
    var from888 = /\/888(\/|$|\?|#)/.test(ref);
    // 同页跳转过来时优先 history.back，可保留会话/详情栈
    if (from888 && window.history.length > 1) {
      try {
        if (pid > 0) {
          sessionStorage.setItem('fans_hub_rp_fair_return', JSON.stringify({
            packetId: pid,
            at: Date.now(),
            reopen: 1
          }));
        }
      } catch (e1) {}
      history.back();
      return;
    }
    var url = homeBase();
    if (pid > 0) {
      url += (url.indexOf('?') >= 0 ? '&' : '?') + 'open_rp=' + encodeURIComponent(String(pid));
      try {
        sessionStorage.setItem('fans_hub_rp_fair_return', JSON.stringify({
          packetId: pid,
          at: Date.now(),
          reopen: 1
        }));
      } catch (e2) {}
    }
    location.href = url;
  }
  function statusLabel(st) {
    var m = { 1: '\u53ef\u62a2', 2: '\u5df2\u62a2\u5b8c', 3: '\u5df2\u8fc7\u671f', 4: '\u5df2\u5173\u95ed', 5: '\u5df2\u7ed3\u7b97' };
    return m[st] || ('status ' + st);
  }
  function tronStatusLabel(st) {
    var m = { 0: '\u672a\u5f00\u5956', 1: '\u7b49\u5f85\u533a\u5757\u786e\u8ba4', 2: '\u5df2\u7ed1\u5b9a\u6ce2\u573a\u54c8\u5e0c', 3: '\u62c9\u53d6\u5931\u8d25\uff08\u53ef\u91cd\u8bd5\uff09' };
    return m[st] || '';
  }
  function centsChips(arr) {
    if (!arr || !arr.length) return '<span class="sub">\u2014</span>';
    return '<div class="cents">' + arr.map(function (c, i) {
      var yuan = (Number(c) / 100).toFixed(2);
      return '<span class="cent">#' + (i + 1) + ' \u00a5' + yuan + ' <small>(' + c + '\u5206)</small></span>';
    }).join('') + '</div>';
  }
  function ynTag(ok, okText, badText) {
    return ok
      ? '<span class="tag ok">' + esc(okText || '\u901a\u8fc7') + '</span>'
      : '<span class="tag bad">' + esc(badText || '\u4e0d\u4e00\u81f4') + '</span>';
  }
  var retryTimer = null;
  async function fetchFair(no) {
    var url = apiBase() + 'api/fanshub/rpfair?packet_no=' + encodeURIComponent(no);
    var res = await fetch(url, { credentials: 'same-origin' });
    var json = await res.json();
    if (json && Number(json.code) === 1) return json;
    var msg = (json && json.msg) ? json.msg : ('HTTP ' + res.status);
    throw new Error(msg);
  }
  async function verify(opts) {
    opts = opts || {};
    var err = $('formErr');
    var result = $('result');
    if (!err || !result) return;
    err.style.display = 'none';
    var no = ($('packetNo').value || '').trim();
    if (!no) {
      err.textContent = '\u8bf7\u8f93\u5165\u7ea2\u5305\u5355\u53f7';
      err.style.display = 'block';
      return;
    }
    try {
      history.replaceState(null, '', '?packet_no=' + encodeURIComponent(no)
        + (function () {
          var q = new URLSearchParams(location.search);
          var pid = q.get('packet_id') || q.get('pid') || '';
          return pid ? ('&packet_id=' + encodeURIComponent(pid)) : '';
        })());
    } catch (e) { /* ignore */ }
    var json;
    try {
      json = await fetchFair(no);
    } catch (e) {
      err.textContent = (e && e.message) ? e.message : '\u7f51\u7edc\u9519\u8bef';
      err.style.display = 'block';
      result.style.display = 'none';
      return;
    }
    var d = json.data || {};
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
    var html = '';
    html += tag(D, 'card',
      tag(D, 'row', '<span>\u73a9\u6cd5</span><strong>' + esc(d.type_label || '') + '</strong>') +
      tag(D, 'row', '<span>\u7ea2\u5305\u72b6\u6001</span><span>' + esc(statusLabel(d.status)) + '</span>') +
      tag(D, 'row', '<span>\u6ce2\u573a\u5f00\u5956</span><span>' + esc(tronStatusLabel(d.tron_status | 0)) + '</span>') +
      tag(D, 'row', '<span>\u53ef\u62a2\u6c60</span><span>\u00a5' + Number(d.pool_amount != null ? d.pool_amount : d.total_amount || 0).toFixed(2) + ' / ' + (d.total_count | 0) + '\u4e2a</span>') +
      tag(D, 'row', '<span>\u53d1\u5305\u603b\u989d</span><span>\u00a5' + Number(d.total_amount || 0).toFixed(2) + '</span>') +
      ((d.packet_type | 0) === 3
        ? tag(D, 'row', '<span>\u624b\u586b\u96f7\u53f7</span><strong>' + esc(mineDigit != null ? String(mineDigit) : '\u2014') + '</strong>')
        : '')
    );

    var card2 = '<p class="sub" style="margin-top:0">\u6ce2\u573a\uff08TRON\uff09\u5b98\u65b9\u533a\u5757\u54c8\u5e0c</p>';
    card2 += tag(D, 'row', '<span>\u5b98\u65b9\u533a\u5757\u9ad8\u5ea6</span><strong>' + esc(blockNum || '\u2014') + '</strong>');
    if (lucky) {
      card2 += tag(D, 'row', '<span>\u54c8\u5e0c\u672b\u4f4d\u5b57\u7b26</span><strong>' + esc(lucky) + '</strong>');
    }
    if (d.revealed && luckyDigit != null) {
      card2 += tag(D, 'row', '<span>\u672b\u4f4d\u6620\u5c04 0-9</span><strong>' + esc(String(luckyDigit)) + '</strong>');
    }
    card2 += '<label style="margin-top:10px">Block Hash</label>' + tag(D, 'mono', esc(blockId || '\u5c1a\u672a\u51fa\u5757\uff0c\u8bf7\u7a0d\u5019\u5237\u65b0'));
    if (d.verify_hint) {
      card2 += '<p class="sub">' + esc(d.verify_hint) + '</p>';
    }
    if (tronscan) {
      card2 += '<a class="tronscan-btn" id="btnTronscan" href="' + esc(tronscan) + '" target="_blank" rel="noopener">\u524d\u5f80 TronScan \u5b98\u65b9\u6838\u5b9e</a>';
      card2 += '<a class="tronscan-btn" style="background:#0d47a1;margin-top:8px" href="https://www.oklink.com/zh-hans/tron/block/' + encodeURIComponent(String(blockNum || blockId)) + '" target="_blank" rel="noopener">\u524d\u5f80 OKLink \u6838\u5b9e</a>';
    }
    if (!d.revealed) {
      card2 += '<p class="sub">\u5c1a\u672a\u7ed1\u5b9a\u6ce2\u573a\u54c8\u5e0c\uff1b\u9875\u9762\u5c06\u81ea\u52a8\u91cd\u8bd5</p><span class="tag wait">\u5f85\u5f00\u5956</span>';
    } else {
      card2 += ' <span class="tag ok">\u6ce2\u573a\u54c8\u5e0c\u5df2\u516c\u5f00</span>';
    }
    html += tag(D, 'card', card2);

    if (d.revealed) {
      var cardAmt = '<p class="sub" style="margin-top:0"><strong>\u91d1\u989d\u9a8c\u8bc1</strong>\uff08\u54c8\u5e0c + \u5355\u53f7\u94fe\u4e0b\u590d\u7b97\uff09</p>';
      cardAmt += tag(D, 'row',
        '<span>\u603b\u4f53\u7ed3\u679c</span>' +
        (av.ok ? ynTag(true, '\u91d1\u989d\u6821\u9a8c\u901a\u8fc7') : ynTag(false, '\u91d1\u989d\u6821\u9a8c\u5931\u8d25'))
      );
      cardAmt += tag(D, 'row',
        '<span>\u590d\u7b97\u5408\u8ba1=\u53ef\u62a2\u6c60</span>' +
        ynTag(!!av.sum_ok, '\u4e00\u81f4', '\u4e0d\u4e00\u81f4')
      );
      cardAmt += tag(D, 'row',
        '<span>\u4e0e\u5165\u5e93\u62c6\u5305\u5e8f\u5217</span>' +
        (av.has_stored
          ? ynTag(!!av.match_stored, '\u5b8c\u5168\u4e00\u81f4', '\u4e0d\u4e00\u81f4')
          : '<span class="tag wait">\u65e0\u5165\u5e93\u5e8f\u5217\uff08\u65e7\u5305\uff09</span>')
      );
      cardAmt += tag(D, 'row',
        '<span>\u4e0e\u5b9e\u9645\u9886\u53d6\u987a\u5e8f</span>' +
        (av.has_grabs
          ? ynTag(!!av.match_grabs, '\u524d\u7f00\u4e00\u81f4', '\u4e0d\u4e00\u81f4')
          : '<span class="tag wait">\u5c1a\u65e0\u9886\u53d6</span>')
      );
      if ((d.packet_type | 0) === 3) {
        cardAmt += tag(D, 'row',
          '<span>\u54c8\u5e0c\u672b\u4f4d=\u624b\u586b\u96f7\u53f7</span>' +
          ynTag(!!av.mine_digit_match, '\u5df2\u5339\u914d ' + esc(String(mineDigit)) , '\u4e0d\u5339\u914d')
        );
      }
      cardAmt += '<label style="margin-top:10px">\u94fe\u4e0b\u590d\u7b97\u91d1\u989d\u5e8f\u5217</label>' + centsChips(computed);
      if (stored && stored.length) {
        cardAmt += '<label style="margin-top:10px">\u5165\u5e93 fair_cents</label>' + centsChips(stored);
      }
      if (grabs && grabs.length) {
        cardAmt += '<label style="margin-top:10px">\u5b9e\u9645\u9886\u53d6\uff08\u6309\u62a2\u5305\u987a\u5e8f\uff09</label>' + centsChips(grabs);
      }
      cardAmt += '<div class="card" style="margin:12px 0 0;box-shadow:none;background:#fafafa">';
      cardAmt += '<p class="sub" style="margin:0 0 8px"><strong>\u6838\u5bf9\u6b65\u9aa4</strong></p>';
      cardAmt += '<p class="sub" style="margin:0">1. \u5728 TronScan \u6838\u5bf9\u533a\u5757 <strong>#' + esc(String(blockNum)) + '</strong> \u7684 Block Hash</p>';
      cardAmt += '<p class="sub" style="margin:4px 0 0">2. \u7528\u8be5 Hash + \u5355\u53f7 <strong>' + esc(d.packet_no || '') + '</strong> \u6309\u4e8c\u500d\u5747\u503c\u6cd5\u590d\u7b97\u91d1\u989d</p>';
      cardAmt += '<p class="sub" style="margin:4px 0 0">3. \u5bf9\u6bd4\u672c\u9875\u300c\u590d\u7b97\u5e8f\u5217\u300d\u4e0e\u9886\u53d6\u91d1\u989d\u662f\u5426\u4e00\u81f4</p>';
      if ((d.packet_type | 0) === 3) {
        cardAmt += '<p class="sub" style="margin:4px 0 0">4. \u626b\u96f7\uff1a\u91d1\u989d\u5c3e\u6570 = \u624b\u586b\u96f7\u53f7 <strong>' + esc(String(mineDigit != null ? mineDigit : '\u2014')) + '</strong> \u5373\u4e2d\u96f7</p>';
      }
      cardAmt += '</div>';
      html += tag(D, 'card', cardAmt);
    }

    result.innerHTML = html;
    result.style.display = 'block';

    if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
    if (!d.revealed && !opts.noRetry) {
      retryTimer = setTimeout(function () {
        verify({ noRetry: false }).catch(function () {});
      }, 3500);
    }
  }
  var btn = $('btnVerify');
  if (btn) btn.addEventListener('click', function () { verify().catch(function (e) {
    var err = $('formErr');
    if (err) {
      err.textContent = (e && e.message) ? e.message : '\u67e5\u8be2\u5f02\u5e38';
      err.style.display = 'block';
    }
  }); });
  var backBtn = $('btnBackHome');
  if (backBtn) backBtn.addEventListener('click', goBackToRpDetail);
  readQuery();
  if (($('packetNo').value || '').trim()) {
    verify().catch(function () {});
  }
})();
