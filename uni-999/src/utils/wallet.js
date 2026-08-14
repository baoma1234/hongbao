import { apiRequest, fetchProfile } from './auth.js'
import {
  ensureAbsoluteHttpUrl,
  FALLBACK_RUNTIME,
  getApiBase,
  packagedStaticUrl,
} from './config.js'
import { assetBase } from './i18n.js'

let _boot = null
let _bootAt = 0
const BOOT_TTL = 60000

function publicAbs(path) {
  const p = String(path || '').trim()
  if (!p) return ''
  if (/^https?:\/\//i.test(p) || p.startsWith('data:')) return p
  const base = getApiBase() || FALLBACK_RUNTIME.apiUri
  const abs = ensureAbsoluteHttpUrl(p, base)
  if (abs) return abs
  return ensureAbsoluteHttpUrl(p, FALLBACK_RUNTIME.apiUri) || p
}

/** App 打包进 APK 的钱包图标；H5 仍可走同路径 static */
function walletPackagedIcon(fileName) {
  const name = String(fileName || '')
    .replace(/^.*[\\/]/, '')
    .split('?')[0]
    .trim()
  if (!name) return ''
  return packagedStaticUrl('wallets/' + name) || ''
}

function walletFileFromIcon(icon) {
  const s = String(icon || '').trim()
  const m = s.match(/wallets\/([^/?#]+\.(?:png|jpe?g|webp|gif|svg))$/i)
  return m && m[1] ? m[1] : ''
}

export function money(n) {
  const x = Number(n)
  if (!isFinite(x)) return '0.00'
  return x.toFixed(2)
}

export function shortChannelName(ch) {
  if (!ch) return '通道'
  if (
    String(ch.wallet_type || '') === 'USDT_MULTI' ||
    (String(ch.handler || '').toLowerCase() === 'bs' &&
      /usdt/i.test(String(ch.name || '') + String(ch.payment_channel || '')))
  ) {
    return 'USDT钱包'
  }
  const raw = String(ch.name || '')
    .replace(/\s+/g, '')
    .replace(/(快捷)?(充值|代付|提现|支付)$/g, '')
    .replace(/收银台/g, '')
    .trim()
  const known = {
    no: 'NO钱包',
    '234': '234钱包',
    '808': '808钱包',
    '988': '988钱包',
    nopay: 'NO钱包',
    k豆: 'K豆钱包',
    jd: 'JD钱包',
    c币: 'C币钱包',
    ok: 'OK钱包',
    to: 'TO钱包',
    go: 'GO钱包',
    万币: '万币钱包',
  }
  const key = String(ch.payment_channel || ch.wallet_type || raw || '')
    .toLowerCase()
    .replace(/\s+/g, '')
  if (known[key]) return known[key]
  if (known[raw]) return known[raw]
  return raw || '通道' + (ch.id || '')
}

/** 通道图标：App 优先 APK 内 /static/wallets；USDT 用 /static/pay/usdt.png */
export function channelIconUrl(ch) {
  let icon = String((ch && ch.icon) || '').trim()
  const handler = ch ? String(ch.handler || '').toLowerCase() : ''
  const usdtLocal = packagedStaticUrl('pay/usdt.png')
  if (handler === 'bs') {
    return usdtLocal || publicAbs(assetBase() + 'static/pay/usdt.png')
  }
  if (/img\/pay\/usdt\.png$/i.test(icon) || /\/pay\/usdt\.png$/i.test(icon)) {
    return usdtLocal || publicAbs(assetBase() + 'static/pay/usdt.png')
  }
  // 优先本地打包图（App 不依赖远程 /assets）
  const walletFile = walletFileFromIcon(icon)
  if (walletFile) {
    const local = walletPackagedIcon(walletFile)
    if (local) return local
  }
  if (!icon) {
    return walletPackagedIcon('default-wallet.png') || publicAbs('/assets/img/wallets/default-wallet.png')
  }
  if (/^https?:\/\//i.test(icon) || icon.startsWith('data:')) {
    // 绝对 URL 若指向本站 wallets，App 仍优先本地
    const fromAbs = walletFileFromIcon(icon)
    if (fromAbs) {
      const local = walletPackagedIcon(fromAbs)
      if (local) return local
    }
    return icon
  }
  if (icon.startsWith('static/')) {
    return packagedStaticUrl(icon) || publicAbs(assetBase() + icon.replace(/^\.\//, ''))
  }
  if (icon.startsWith('/999/')) {
    const rel = icon.replace(/^\/999\//, '')
    if (rel.indexOf('static/') === 0) return packagedStaticUrl(rel) || publicAbs(icon)
    return publicAbs(icon) || assetBase() + rel
  }
  if (icon.startsWith('/888/')) {
    // 历史路径：优先映射到 /999 或 /assets，避免继续依赖废弃 /888
    const rest = icon.replace(/^\/888\//, '')
    return publicAbs('/999/' + rest) || publicAbs('/assets/' + rest) || publicAbs(icon)
  }
  if (icon.startsWith('/assets/') || icon.startsWith('/')) {
    return publicAbs(icon)
  }
  if (/^img\//i.test(icon)) return publicAbs('/assets/' + icon.replace(/^\.\//, ''))
  return publicAbs('/assets/' + icon.replace(/^\.\//, ''))
}

export function isOnlineCoopChannel(ch) {
  return !!(
    ch &&
    (String(ch.withdraw_mode || '') === 'online_coop' ||
      String(ch.partition_code || '') === 'online_coop')
  )
}

export function validateChannelAmount(ch, amount) {
  const a = Number(amount)
  if (!ch) return '请选择通道'
  if (!isFinite(a) || a <= 0) return '请输入金额'
  const min = Number(ch.min_amount || 0)
  const max = Number(ch.max_amount || 0)
  if (min > 0 && a < min) return '最低 ' + money(min)
  if (max > 0 && a > max) return '最高 ' + money(max)
  return ''
}

export async function loadWalletBootstrap(force = false) {
  if (!force && _boot && Date.now() - _bootAt < BOOT_TTL) {
    return _boot
  }
  try {
    const bundle = await apiRequest('walletbootstrap', 'POST', {})
    _boot = bundle || {}
    _bootAt = Date.now()
    return _boot
  } catch (e) {
    const [info, recharge, withdraw] = await Promise.all([
      apiRequest('walletinfo', 'POST', {}).catch(() => ({})),
      apiRequest('rechargechannels', 'POST', {}).catch(() => ({ list: [], partitions: [] })),
      apiRequest('withdrawchannels', 'POST', {}).catch(() => ({ list: [], partitions: [], binds: {} })),
    ])
    _boot = { info, recharge, withdraw }
    _bootAt = Date.now()
    return _boot
  }
}

export function clearWalletCache() {
  _boot = null
  _bootAt = 0
}

export function getApprovedMainUid(profile) {
  const p = profile || {}
  const uid = String(p.main_uid || '').trim()
  const audit = String(p.main_uid_audit || '').trim()
  if (uid && audit === 'approved') return uid
  return ''
}

export async function fetchLedger(page = 1, limit = 20, category = 'all') {
  const body = { page, limit }
  if (category && category !== 'all') body.category = category
  return apiRequest('walletledger', 'POST', body)
}

export async function submitRecharge(channelId, amount) {
  return apiRequest('recharge', 'POST', { channel_id: channelId, amount })
}

export async function submitWithdraw(channelId, amount, accountInfo, payPassword) {
  return apiRequest('withdraw', 'POST', {
    channel_id: channelId,
    amount,
    account_info: accountInfo || {},
    pay_password: payPassword || '',
  })
}

export async function bindWallet(walletType, accountInfo, payPassword, bindMode = 'wallet') {
  return apiRequest('bindwallet', 'POST', {
    wallet_type: walletType,
    account_info: accountInfo || {},
    account_no: (accountInfo && accountInfo.account_no) || '',
    account_name: (accountInfo && accountInfo.account_name) || '',
    bank_name: (accountInfo && accountInfo.bank_name) || '',
    bind_mode: bindMode || 'wallet',
    pay_password: payPassword || '',
  })
}

export async function ensurePayPassword(hasPayPassword) {
  return new Promise((resolve, reject) => {
    const needSet = !hasPayPassword
    uni.showModal({
      title: needSet ? '设置支付密码' : '支付密码',
      editable: true,
      placeholderText: needSet ? '首次设置 6-32 位' : '请输入支付密码',
      success: async (res) => {
        if (!res.confirm) {
          reject(new Error('已取消'))
          return
        }
        const pwd = String(res.content || '').trim()
        if (pwd.length < 6 || pwd.length > 32) {
          reject(new Error('支付密码需 6-32 位'))
          return
        }
        try {
          if (needSet) {
            await apiRequest('setpaypassword', 'POST', {
              pay_password: pwd,
              confirm_password: pwd,
            })
          }
          resolve(pwd)
        } catch (e) {
          reject(e)
        }
      },
      fail: () => reject(new Error('无法输入支付密码')),
    })
  })
}

function escapeHtmlAttr(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

function isIosLikeUa() {
  try {
    if (typeof navigator === 'undefined') return false
    const ua = navigator.userAgent || ''
    if (/iPhone|iPad|iPod/i.test(ua)) return true
    return navigator.platform === 'MacIntel' && (navigator.maxTouchPoints || 0) > 1
  } catch (e) {
    return false
  }
}

/** App / H5 打开外部 http(s)：App 用系统浏览器；H5 iOS Safari 同页跳，桌面优先新窗口 */
export function openExternalHttpUrl(url) {
  const u = String(url || '').trim()
  if (!u || !/^https?:\/\//i.test(u)) return false
  // #ifdef APP-PLUS
  try {
    // eslint-disable-next-line no-undef
    plus.runtime.openURL(u)
    return true
  } catch (e) {
    try {
      uni.navigateTo({
        url:
          '/pages/common/webview?url=' +
          encodeURIComponent(u) +
          '&title=' +
          encodeURIComponent('支付'),
      })
      return true
    } catch (e2) {
      uni.setClipboardData({ data: u })
      uni.showToast({ title: '链接已复制，请在浏览器打开', icon: 'none' })
      return false
    }
  }
  // #endif
  // #ifdef H5
  try {
    if (typeof window === 'undefined') return false
    if (isIosLikeUa()) {
      window.location.href = u
      return true
    }
    const w = window.open(u, '_blank')
    if (!w || w.closed || typeof w.closed === 'undefined') {
      window.location.href = u
    }
    return true
  } catch (e) {
    try {
      window.location.href = u
      return true
    } catch (e2) {
      return false
    }
  }
  // #endif
  return false
}

function openPayFormH5(payInfo) {
  if (typeof document === 'undefined' || typeof window === 'undefined') return false
  const isiOS = isIosLikeUa()
  const form = document.createElement('form')
  form.method = (payInfo.method || 'POST').toUpperCase()
  form.action = payInfo.url
  // iOS Safari 拦截异步 popup；同页提交才能跳转
  form.target = isiOS ? '_self' : '_blank'
  form.style.display = 'none'
  Object.keys(payInfo.params || {}).forEach((k) => {
    const inp = document.createElement('input')
    inp.type = 'hidden'
    inp.name = k
    inp.value = payInfo.params[k]
    form.appendChild(inp)
  })
  document.body.appendChild(form)
  form.submit()
  if (!isiOS) {
    setTimeout(() => {
      try {
        document.body.removeChild(form)
      } catch (e) {}
    }, 800)
  }
  return true
}

function openPayFormApp(payInfo) {
  const method = String(payInfo.method || 'POST').toUpperCase()
  const action = String(payInfo.url || '').trim()
  const params = payInfo.params || {}
  if (!action) return false
  if (method === 'GET') {
    const q = Object.keys(params)
      .map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
      .join('&')
    const full = action + (action.indexOf('?') >= 0 ? '&' : '?') + q
    return openExternalHttpUrl(full)
  }
  // POST：系统浏览器无法带表单，用 App WebView 自动提交（iOS 用 WKWebview）
  try {
    // eslint-disable-next-line no-undef
    if (typeof plus === 'undefined' || !plus.webview) throw new Error('no plus')
    const inputs = Object.keys(params)
      .map(
        (k) =>
          '<input type="hidden" name="' +
          escapeHtmlAttr(k) +
          '" value="' +
          escapeHtmlAttr(params[k]) +
          '" />'
      )
      .join('')
    const html =
      '<!DOCTYPE html><html><head><meta charset="utf-8">' +
      '<meta name="viewport" content="width=device-width,initial-scale=1">' +
      '</head><body>' +
      '<form id="payForm" method="' +
      escapeHtmlAttr(method) +
      '" action="' +
      escapeHtmlAttr(action) +
      '">' +
      inputs +
      '</form>' +
      '<script>document.getElementById("payForm").submit();</' +
      'script></body></html>'
    let w = plus.webview.getWebviewById('fanshub-pay-form')
    if (w) {
      try {
        w.close()
      } catch (e) {}
    }
    w = plus.webview.create('', 'fanshub-pay-form', {
      titleNView: {
        autoBackButton: true,
        titleText: '支付',
        backgroundColor: '#C61114',
        titleColor: '#ffffff',
      },
      kernel: 'WKWebview',
    })
    w.loadData(html, 'text/html', 'utf-8', action)
    w.show('slide-in-right', 220)
    return true
  } catch (e) {
    return openExternalHttpUrl(action)
  }
}

/** 打开支付结果（跳转/表单）—— App + H5(iOS Safari) 都能跳 */
export function sanitizePayMessage(msg, fallback) {
  const raw = String(msg == null ? '' : msg).trim()
  const fb = fallback || '提交成功'
  if (!raw) return fb
  const low = raw.toLowerCase()
  if (low === 'success' || low === 'ok' || low === 'true' || low === '1') {
    return fb
  }
  return raw
}

export function openPayResult(payInfo) {
  if (!payInfo) return

  if (payInfo.action === 'usdt' && payInfo.booking_address) {
    const lines = [
      sanitizePayMessage(payInfo.message, '请完成 USDT 转账'),
      '地址：' + payInfo.booking_address,
      payInfo.pay_coin_amount
        ? '数量：' + payInfo.pay_coin_amount + ' ' + (payInfo.coin_type || 'USDT')
        : '',
    ].filter(Boolean)
    uni.showModal({ title: '充值信息', content: lines.join('\n'), showCancel: false })
    return
  }

  if (payInfo.action === 'form' && payInfo.url && payInfo.params) {
    // #ifdef H5
    if (openPayFormH5(payInfo)) return
    // #endif
    // #ifdef APP-PLUS
    if (openPayFormApp(payInfo)) return
    // #endif
  }

  if (payInfo.url) {
    if (openExternalHttpUrl(String(payInfo.url))) return
  }

  if (payInfo.message) {
    uni.showModal({
      title: '提示',
      content: sanitizePayMessage(payInfo.message, '提交成功'),
      showCancel: false,
    })
  }
}

export async function loadProfileLite() {
  try {
    return await fetchProfile()
  } catch (e) {
    return null
  }
}

export function findChannel(list, id) {
  const cid = Number(id) || 0
  return (list || []).find((c) => Number(c.id) === cid) || null
}

export function groupByPartitions(channels, partitions) {
  const list = Array.isArray(channels) ? channels : []
  const parts = Array.isArray(partitions) ? partitions : []
  if (!parts.length) {
    return [{ key: 'all', name: '全部', code: '', channels: list }]
  }
  const used = new Set()
  const groups = parts
    .map((p) => {
      const code = String(p.code || '')
      // 优先用分区自带 channels（与 888 一致）
      let chs = Array.isArray(p.channels) && p.channels.length ? p.channels.slice() : null
      if (!chs) {
        chs = list.filter((c) => {
          const match =
            String(c.partition_code || '') === code ||
            (code === 'online_coop' && isOnlineCoopChannel(c))
          if (match) used.add(Number(c.id))
          return match
        })
      } else {
        chs.forEach((c) => used.add(Number(c.id)))
      }
      return {
        key: code || String(p.id || p.name || 'p'),
        name: p.name || code || '通道',
        code,
        channels: chs,
      }
    })
    .filter((g) => g.channels.length > 0)
  const orphan = list.filter((c) => !used.has(Number(c.id)))
  if (orphan.length) {
    groups.push({ key: '_other', name: '其他', code: '', channels: orphan })
  }
  return groups.length ? groups : [{ key: 'all', name: '全部', code: '', channels: list }]
}

/** 默认快捷金额；通道可在后台 config.quick_amounts 覆盖 */
export const RECHARGE_QUICK_AMOUNTS = [50, 100, 500, 1000, 5000, 10000, 50000, 100000]

export function formatQuickAmtLabel(n) {
  const x = Number(n) || 0
  return String(Math.round(x)).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
}

export function rechargeQuickAmounts(ch) {
  const raw = ch && (ch.quick_amounts || ch.amounts || ch.fixed_amounts)
  let list = []
  if (Array.isArray(raw) && raw.length) {
    list = raw.map(Number).filter((n) => n > 0).slice(0, 16)
  } else if (typeof raw === 'string' && raw.trim()) {
    list = raw.split(/[,，\s]+/).map(Number).filter((n) => n > 0).slice(0, 16)
  }
  if (!list.length) list = RECHARGE_QUICK_AMOUNTS.slice()
  return list
}

export function isQuickAmtDisabled(ch, amt) {
  const a = Number(amt) || 0
  if (!ch || !(a > 0)) return false
  const min = Number(ch.min_amount || 0)
  const max = Number(ch.max_amount || 0)
  if (min > 0 && a < min) return true
  if (max > 0 && a > max) return true
  return false
}

const WALLET_PIN_ORDER = ['no', '234', '808', '988', 'k豆', 'jd', 'c币', 'ok', 'to', 'go', '万币']
const WALLET_PIN_ALIAS = {
  nopay: 'no',
  kdou: 'k豆',
  cbi: 'c币',
  jdpay: 'jd',
  okpay: 'ok',
  topay: 'to',
  gopay: 'go',
  wanbi: '万币',
  '988pay': '988',
  '808pay': '808',
  '234pay': '234',
}

function walletChannelKey(name, paymentChannel) {
  let code = String(paymentChannel || '').replace(/\s+/g, '')
  const codeQuick = /_quick$/i.test(code)
  if (code) {
    code = code.replace(/_quick$/i, '')
    let codeKey = code.toLowerCase()
    if (WALLET_PIN_ALIAS[codeKey]) codeKey = WALLET_PIN_ALIAS[codeKey]
    if (/^\d+$/.test(codeKey) || WALLET_PIN_ORDER.indexOf(codeKey) >= 0) {
      return { key: codeKey, quick: codeQuick }
    }
    let stripped = codeKey.replace(/pay$/i, '')
    if (WALLET_PIN_ALIAS[stripped]) stripped = WALLET_PIN_ALIAS[stripped]
    if (WALLET_PIN_ORDER.indexOf(stripped) >= 0) {
      return { key: stripped, quick: codeQuick }
    }
  }
  let s = String(name || '').replace(/\s+/g, '')
  const quick = codeQuick || /快捷|_quick/i.test(s)
  s = s.replace(/快捷(支付)?/g, '').replace(/钱包/g, '').replace(/支付$/g, '').replace(/pay$/i, '')
  let key = s.toLowerCase()
  if (WALLET_PIN_ALIAS[key]) key = WALLET_PIN_ALIAS[key]
  return { key, quick }
}

function walletPinIndex(ch) {
  const info = walletChannelKey(ch && ch.name, ch && ch.payment_channel)
  if (info.quick) return -1
  const handler = String((ch && ch.handler) || '').toLowerCase()
  const mixed =
    String((ch && ch.name) || '') +
    ' ' +
    String((ch && ch.payment_channel) || '') +
    ' ' +
    String((ch && ch.wallet_type) || '')
  if (handler === 'bs' && /usdt/i.test(mixed)) return 0.5
  return WALLET_PIN_ORDER.indexOf(info.key)
}

/** 置顶排序：返回 { pinned, more }，与 888 organizeWalletChannels 一致 */
export function organizeWalletChannels(list) {
  const pinned = []
  const more = []
  ;(list || []).forEach((ch, idx) => {
    const pin = walletPinIndex(ch)
    if (pin >= 0) pinned.push({ ch, pin, idx })
    else more.push({ ch, idx })
  })
  pinned.sort((a, b) => (a.pin !== b.pin ? a.pin - b.pin : a.idx - b.idx))
  more.sort((a, b) => a.idx - b.idx)
  return {
    pinned: pinned.map((x) => x.ch),
    more: more.map((x) => x.ch),
  }
}

/**
 * 钱包地址页「数字钱包」类型列表：与 888 collectPayeeWalletTypes 同序。
 * 优先用充值 partitions 中 code/bind_mode=wallet 的 channels，USDT 多链置前。
 */
export function collectPayeeWalletTypes(recharge, withdraw) {
  let walletChs = []
  ;((recharge && recharge.partitions) || []).forEach((p) => {
    if (p && (String(p.code || '') === 'wallet' || String(p.bind_mode || '') === 'wallet')) {
      walletChs = (p.channels || []).slice()
    }
  })
  if (!walletChs.length) {
    ;((withdraw && withdraw.partitions) || []).forEach((p) => {
      if (p && (String(p.code || '') === 'wallet' || String(p.bind_mode || '') === 'wallet')) {
        walletChs = (p.channels || []).slice()
      }
    })
  }
  if (!walletChs.length) {
    const src = [].concat((recharge && recharge.list) || [], (withdraw && withdraw.list) || [])
    walletChs = src.filter(
      (ch) =>
        String(ch.bind_mode || '') === 'wallet' || /钱包|wallet/i.test(String(ch.name || ''))
    )
  }
  const list = walletChs.slice()
  list.unshift({
    id: 0,
    name: 'USDT钱包',
    handler: 'bs',
    bind_mode: 'wallet',
    wallet_type: 'USDT_MULTI',
    payment_channel: 'USDT',
    recharge_mode: 'cashier',
    icon: 'img/pay/usdt.png',
  })
  // 与 888 flattenChannelList(list, 'recharge', true) 一致：BS 收银台 featured 置前
  const featured = []
  const dropdown = []
  list.forEach((ch) => {
    const handler = String((ch && ch.handler) || '').toLowerCase()
    const mode = String((ch && ch.recharge_mode) || '').toLowerCase()
    const isFeatured =
      handler === 'bs' &&
      mode !== 'api' &&
      (mode === '' || mode === 'cashier' || /收银台|cashier/i.test(String(ch.name || '')))
    if (isFeatured) featured.push(ch)
    else dropdown.push(ch)
  })
  const organized = organizeWalletChannels(dropdown)
  const ordered = featured.concat(organized.pinned, organized.more)
  const seen = {}
  const out = []
  ordered.forEach((ch) => {
    const wt0 = String(ch.wallet_type || ch.payment_channel || '').trim()
    if (!wt0 || seen[wt0]) return
    if (String(ch.handler || '').toLowerCase() === 'bs' && wt0 !== 'USDT_MULTI') return
    seen[wt0] = true
    out.push({
      type: wt0,
      label: shortChannelName(ch) || wt0,
      icon: channelIconUrl(ch),
      multi: wt0 === 'USDT_MULTI',
    })
  })
  return out
}

export const CHANNEL_GRID_VISIBLE = 8

export function isUsdtRechargeChannel(ch) {
  if (!ch) return false
  const rate = Number(ch.exchange_rate || 0)
  if (!(rate > 0)) return false
  if (String(ch.handler || '').toLowerCase() === 'bs') return true
  const mix =
    String(ch.name || '') +
    ' ' +
    String(ch.payment_channel || '') +
    ' ' +
    String(ch.wallet_type || '') +
    ' ' +
    String(ch.coin_type || '')
  return /usdt/i.test(mix)
}

/**
 * @param {object} ch
 * @param {number|string} amount
 * @param {{ forWithdraw?: boolean }} [opts]
 */
export function fxHintText(ch, amount, opts) {
  if (!ch) return ''
  const rate = Number(ch.exchange_rate || 0)
  if (!(rate > 0)) return ''
  const a = Number(amount) || 0
  const forWithdraw = !!(opts && opts.forWithdraw)
  if (forWithdraw || (!isUsdtRechargeChannel(ch) && String(ch.handler || '').toLowerCase() === 'bs')) {
    // 提现：人民币 ÷ 汇率 ≈ USDT（与 888 一致）
    if (a > 0) {
      return '约合 ' + money(a / rate) + ' USDT（汇率 ' + rate + '）'
    }
    return rate + ' CNY = 1 USDT'
  }
  if (isUsdtRechargeChannel(ch)) {
    if (a > 0) {
      return rate + ' CNY = 1 USDT，约合 ' + money(a * rate) + '人民币'
    }
    return rate + ' CNY = 1 USDT（输入 U 数量后显示约合人民币）'
  }
  const unit = ch.coin_name || ch.currency || '币'
  if (a > 0) {
    return '约到账 ' + money(a * rate) + ' ' + unit + '（汇率 ' + rate + '）'
  }
  return '汇率 1 红宝 ≈ ' + rate + ' ' + unit
}

export function turnoverHint(info) {
  if (!info) return ''
  const need = Math.max(Number(info.withdraw_turnover_min) || 0, 0)
  const ratio = Number(info.withdraw_turnover_ratio)
  const r = isFinite(ratio) ? ratio : 1
  let s = '流水需≥' + money(need)
  if (r > 0) s += '，且不少于提现额×' + r
  return s
}

export function ledgerAmountText(item) {
  let hb = parseFloat(item.hongbao_change) || 0
  const rights = parseFloat(item.rights_change) || 0
  const bal = parseFloat(item.balance_change) || 0
  if (hb === 0 && bal !== 0) hb = bal
  if (hb !== 0) {
    const sign = hb > 0 ? '+' : ''
    return { text: sign + money(hb), cls: hb > 0 ? 'plus' : 'minus' }
  }
  if (rights !== 0) {
    const sign = rights > 0 ? '+' : ''
    return { text: sign + rights.toFixed(2) + '股', cls: rights > 0 ? 'plus' : 'minus' }
  }
  return { text: '0.00', cls: '' }
}
