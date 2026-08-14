<?php
$root = dirname(__DIR__);
$path = $root . '/public/888/js/profile-wallet.js';
$js = file_get_contents($path);

if (strpos($js, 'function wt(') === false) {
    $helper = <<<'JS'
  function wt(key, fallback, extra) {
    var tpl = fallback || key;
    if (global.FanshubI18n && typeof global.FanshubI18n.text === 'function') {
      var t = global.FanshubI18n.text(key);
      if (t) tpl = t;
    }
    if (extra && typeof extra === 'object') {
      Object.keys(extra).forEach(function (k) {
        tpl = String(tpl).split('{' + k + '}').join(String(extra[k]));
      });
    }
    return tpl;
  }

JS;
    $js = str_replace("  var ledgerState = { page: 1, loading: false, hasMore: false, list: [] };\n\n", "  var ledgerState = { page: 1, loading: false, hasMore: false, list: [] };\n\n" . $helper, $js);
}

$pairs = [
    ["return Promise.reject(new Error('未登录'));", "return Promise.reject(new Error(wt('wallet_not_login', '未登录')));"],
    ["return '+' + abs + '股';", "return '+' + abs + wt('wallet_unit_share', '股');"],
    ["return '-' + abs + '股';", "return '-' + abs + wt('wallet_unit_share', '股');"],
    ["return '+' + abs + '红宝';", "return '+' + abs + wt('wallet_unit_hongbao', '红宝');"],
    ["return '-' + abs + '红宝';", "return '-' + abs + wt('wallet_unit_hongbao', '红宝');"],
    ["box.innerHTML = '<div class=\"wallet-ledger-empty\">暂无资金流水</div>';", "box.innerHTML = '<div class=\"wallet-ledger-empty\">' + escapeHtml(wt('wallet_ledger_empty', '暂无资金流水')) + '</div>';"],
    ["rights.toFixed(2) + '股';", "rights.toFixed(2) + wt('wallet_unit_share', '股');"],
    ["subParts.push('红宝 ' + money(item.hongbao_after));", "subParts.push(wt('wallet_unit_hongbao', '红宝') + ' ' + money(item.hongbao_after));"],
    ["subParts.push('红利 ' + money(item.balance_after));", "subParts.push(wt('wallet_unit_balance', '红利') + ' ' + money(item.balance_after));"],
    ["item.type_label || item.type || '其他'", "item.type_label || item.type || wt('wallet_ledger_other', '其他')"],
    ["(e && e.message) || '加载失败'", "(e && e.message) || wt('wallet_load_fail', '加载失败')"],
    ["box.innerHTML = '<div class=\"wallet-channel-empty\">暂无可用通道，请联系客服</div>';", "box.innerHTML = '<div class=\"wallet-channel-empty\">' + escapeHtml(wt('wallet_channel_empty', '暂无可用通道，请联系客服')) + '</div>';"],
    ["(ch.name || ('通道' + ch.id))", "(ch.name || wt('wallet_channel_fallback', '通道{id}', { id: ch.id }))"],
    ["hint.textContent = '流水需≥' + money(need) + (ratio > 0 ? '，且不少于提现额×' + ratio : '');", "hint.textContent = wt('wallet_turnover_need', '流水需≥{need}', { need: money(need) }) + (ratio > 0 ? wt('wallet_turnover_ratio_suffix', '，且不少于提现额×{ratio}', { ratio: ratio }) : '');"],
    ["if (line && walletState.info) line.textContent = '累计流水：' + money(walletState.info.turnover);", "if (line && walletState.info) line.textContent = wt('wallet_turnover_line', '累计流水：{amount}', { amount: money(walletState.info.turnover) });"],
    ["if (box) box.innerHTML = '<div class=\"wallet-ledger-empty\">加载中…</div>';", "if (box) box.innerHTML = '<div class=\"wallet-ledger-empty\">' + escapeHtml(wt('wallet_loading', '加载中…')) + '</div>';"],
    ["toast((e && e.message) || '钱包模块加载失败', 'error');", "toast((e && e.message) || wt('wallet_module_fail', '钱包模块加载失败'), 'error');"],
    ["toast('请选择通道并填写金额', 'error');", "toast(wt('wallet_need_channel_amount', '请选择通道并填写金额'), 'error');"],
    ["toast((info.message) || '充值申请已提交', 'success');", "toast((info.message) || wt('wallet_recharge_ok', '充值申请已提交'), 'success');"],
    ["toast((e && e.message) || '失败', 'error');", "toast((e && e.message) || wt('wallet_fail', '失败'), 'error');"],
    ["toast('请填写收款人姓名与账号', 'error');", "toast(wt('wallet_need_payee', '请填写收款人姓名与账号'), 'error');"],
    ["toast('请填写银行名称（支付宝可填：支付宝）', 'error');", "toast(wt('wallet_need_bank', '请填写银行名称（支付宝可填：支付宝）'), 'error');"],
    ["toast((data && data.message) || '提现申请已提交', 'success');", "toast((data && data.message) || wt('wallet_withdraw_ok', '提现申请已提交'), 'success');"],
];

$ok = 0;
$miss = 0;
foreach ($pairs as $p) {
    if (strpos($js, $p[0]) === false) {
        echo "MISS: " . substr($p[0], 0, 50) . "\n";
        $miss++;
    } else {
        $js = str_replace($p[0], $p[1], $js);
        $ok++;
    }
}
file_put_contents($path, $js);
echo "wallet_ok=$ok miss=$miss\n";
