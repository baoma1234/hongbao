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
  function statusLabel(st) {
    var m = { 1: '\u53ef\u62a2', 2: '\u5df2\u62a2\u5b8c', 3: '\u5df2\u8fc7\u671f', 4: '\u5df2\u5173\u95ed', 5: '\u5df2\u7ed3\u7b97' };
    return m[st] || ('status ' + st);
  }
  function tronStatusLabel(st) {
    var m = { 0: '\u672a\u5f00\u5956', 1: '\u7b49\u5f85\u533a\u5757\u786e\u8ba4', 2: '\u5df2\u7ed1\u5b9a\u6ce2\u573a\u54c8\u5e0c', 3: '\u62c9\u53d6\u5931\u8d25\uff08\u53ef\u91cd\u8bd5\uff09' };
    return m[st] || '';
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
      history.replaceState(null, '', '?packet_no=' + encodeURIComponent(no));
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
    var luckyDigit = d.lucky_digit != null ? d.lucky_digit : (d.revealed && d.mine_digit != null ? d.mine_digit : null);
    var tronscan = d.tronscan_url || (blockNum ? ('https://tronscan.org/#/block/' + blockNum) : (blockId ? ('https://tronscan.org/#/block/' + blockId) : ''));
    var html = '';
    html += tag(D, 'card',
      tag(D, 'row', '<span>\u73a9\u6cd5</span><strong>' + esc(d.type_label || '') + '</strong>') +
      tag(D, 'row', '<span>\u7ea2\u5305\u72b6\u6001</span><span>' + esc(statusLabel(d.status)) + '</span>') +
      tag(D, 'row', '<span>\u6ce2\u573a\u5f00\u5956</span><span>' + esc(tronStatusLabel(d.tron_status | 0)) + '</span>') +
      tag(D, 'row', '<span>\u603b\u989d</span><span>\u00a5' + Number(d.total_amount || 0).toFixed(2) + ' / ' + (d.total_count | 0) + '</span>') +
      ((d.packet_type | 0) === 3
        ? tag(D, 'row', '<span>\u5b98\u65b9\u96f7\u53f7</span><strong>' + esc(d.revealed ? String(luckyDigit) : '\u5f85\u6ce2\u573a\u5f00\u5956') + '</strong>')
        : '')
    );
    var card2 = '<p class="sub" style="margin-top:0">\u6ce2\u573a\uff08TRON\uff09\u5b98\u65b9\u533a\u5757\u54c8\u5e0c \u2014 \u5168\u7f51\u53ef\u67e5</p>';
    card2 += tag(D, 'row', '<span>\u5b98\u65b9\u533a\u5757\u9ad8\u5ea6</span><strong>' + esc(blockNum || '\u2014') + '</strong>');
    if (lucky) {
      card2 += tag(D, 'row', '<span>\u54c8\u5e0c\u672b\u4f4d\u5b57\u7b26</span><strong>' + esc(lucky) + '</strong>');
    }
    if (d.revealed && luckyDigit != null && (d.packet_type | 0) === 3) {
      card2 += tag(D, 'row', '<span>\u6620\u5c04\u96f7\u53f7 0-9</span><strong>' + esc(String(luckyDigit)) + '</strong>');
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
      card2 += '<p class="sub">\u5df2\u9501\u5b9a\u672a\u6765\u533a\u5757\uff0c\u51fa\u5757\u540e\u81ea\u52a8\u7ed1\u5b9a\u54c8\u5e0c\uff1b\u9875\u9762\u5c06\u81ea\u52a8\u91cd\u8bd5</p><span class="tag wait">\u5f85\u5f00\u5956</span>';
    } else {
      card2 += ' <span class="tag ok">\u6ce2\u573a\u54c8\u5e0c\u5df2\u516c\u5f00</span>';
      card2 += '<div class="card" style="margin:12px 0 0;box-shadow:none;background:#fafafa">';
      card2 += '<p class="sub" style="margin:0 0 8px"><strong>\u73a9\u5bb6\u6838\u5bf9\u6b65\u9aa4</strong></p>';
      card2 += '<p class="sub" style="margin:0">1. \u8bb0\u4f4f\u672c\u5c40\u5b98\u65b9\u533a\u5757\u9ad8\u5ea6 <strong>#' + esc(String(blockNum)) + '</strong></p>';
      card2 += '<p class="sub" style="margin:4px 0 0">2. \u6253\u5f00 TronScan / OKLink\uff0c\u641c\u7d22\u8be5\u9ad8\u5ea6</p>';
      card2 += '<p class="sub" style="margin:4px 0 0">3. \u5bf9\u6bd4 Block Hash \u672b\u4f4d\u662f\u5426\u4e3a <strong>' + esc(lucky || '\u2014') + '</strong></p>';
      if ((d.packet_type | 0) === 3) {
        card2 += '<p class="sub" style="margin:4px 0 0">4. \u626b\u96f7\uff1a\u91d1\u989d\u5c3e\u6570 = \u5b98\u65b9\u96f7\u53f7 <strong>' + esc(String(luckyDigit)) + '</strong> \u5373\u4e2d\u96f7</p>';
      }
      card2 += '</div>';
    }
    html += tag(D, 'card', card2);
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
  readQuery();
  if (($('packetNo').value || '').trim()) {
    verify().catch(function () {});
  }
})();
