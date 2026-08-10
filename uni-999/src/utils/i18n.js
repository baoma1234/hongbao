import { ref } from 'vue'
import { ensureAbsoluteHttpUrl, getStaticBase, packagedStaticUrl } from './config.js'

/** 与 /888 共用 */
export const LOCALE_STORAGE_KEY = 'fans_hub_locale'
export const COUNTRY_STORAGE_KEY = 'fans_hub_country'
export const DEFAULT_LOCALE = 'zh-CN'

export const LOCALE_ORDER = ['zh-CN', 'en-PH', 'vi-VN', 'ms-MY', 'km-KH', 'id-ID']

export const LOCALE_META = {
  'zh-CN': { labelKey: 'lang_zh', country: 'CN', flagIso: 'cn', fallback: '中文' },
  'en-PH': { labelKey: 'lang_en', country: 'PH', flagIso: 'gb', fallback: 'English' },
  'vi-VN': { labelKey: 'lang_vi', country: 'VN', flagIso: 'vn', fallback: 'Tiếng Việt' },
  'ms-MY': { labelKey: 'lang_ms', country: 'MY', flagIso: 'my', fallback: 'Melayu' },
  'km-KH': { labelKey: 'lang_km', country: 'KH', flagIso: 'kh', fallback: 'ខ្មែរ' },
  'id-ID': { labelKey: 'lang_id', country: 'ID', flagIso: 'id', fallback: 'Indonesia' },
}

/** 离线兜底（顶栏 / 登录 / Tab / 闪兑 / 我的） */
const BOOT_COPY = {
  'zh-CN': {
    brand_name: '红宝',
    skin_label: '换肤',
    skin_option_default: '默认',
    skin_option_a: '激情中国红',
    skin_option_b: '皇家高级蓝',
    skin_option_d: '科技冷银灰',
    lang_zh: '中文',
    lang_en: 'English',
    lang_vi: 'Tiếng Việt',
    lang_ms: 'Melayu',
    lang_km: 'ខ្មែរ',
    lang_id: 'Indonesia',
    tab_bar_home: '大厅',
    tab_bar_exchange: '闪兑',
    tab_bar_messages: '红宝',
    tab_bar_master: '裂变',
    tab_bar_fission: '裂变',
    tab_bar_profile: '我的',
    promote_earn_title: '推广收益数据表',
    promote_earn_live: '实时更新 ›',
    promote_earn_col_uid: '用户ID',
    promote_earn_col_type: '收益类型',
    promote_earn_col_detail: '广细记录',
    promote_earn_col_amount: '到手佣金',
    page_hero_exchange_title: '⚡ VIP 闪兑大厅',
    page_hero_exchange_sub: '股份 ↔ 红宝 · 实时预估到账',
    swap_submit: '确认兑换',
    swap_all_btn: '全部',
    swap_aria_flip: '互换方向',
    swap_to_label: '兑换目标',
    swap_rate_label: '兑换比例',
    swap_est_label: '预计到账',
    swap_from_label: '转出',
    swap_from_with_asset: '转出{asset}',
    swap_title_pair: '{from}兑换{to}',
    swap_pair_closed: '{from}兑换{to}已关闭',
    swap_asset_hongbao: '红宝',
    swap_asset_rights: '股份',
    swap_unit_hongbao: '宝',
    swap_unit_share: '股',
    swap_avail_hongbao: '可用 {amount} 红宝',
    swap_avail_rights: '可用 {amount} 份',
    swap_est_shares: '{amount} 份',
    swap_rate_line: '1 {from} = {rate} {to}',
    share_swap_rights_locked_hint: '可兑 {free} 份（锁定 {locked} 份，次日可兑）',
    profile_ex_r2b_closed: '股份兑换红宝已关闭',
    profile_ex_max_hint: '单笔上限 {max}',
    alert_exchange_swap_ok: '🎉 兑换成功',
    alert_exchange_fail: '兑换失败',
    alert_exchange_disabled: '兑换功能已关闭',
    alert_exchange_pair_invalid: '当前兑换方向已关闭',
    asset_hongbao_label: '红宝',
    asset_shares_label: '股份',
    login_subtitle: '🛡️ 官方福利通道激活：请输您的手机号码',
    login_phone_label: '📱 会员登记：请输入您的手机号码',
    login_phone_placeholder: '请输入11位中国大陆手机号',
    login_phone_placeholder_ph: '请输入10位菲律宾手机号（以9开头）',
    login_phone_placeholder_kh: '请输入8-9位柬埔寨手机号',
    login_phone_placeholder_id: '请输入印尼手机号（以8开头，9-12位）',
    login_phone_placeholder_vn: '请输入10位越南手机号',
    login_phone_placeholder_my: '请输入马来西亚手机号（以1开头，9-10位）',
    login_captcha_label: '🔑 动态安全验校',
    login_captcha_placeholder: '请输入短信验证码',
    login_captcha_btn: '获取验证码',
    login_captcha_resend: '{count}s',
    login_submit_btn: '进入官方福利大厅，白嫖初始{register_rights}股',
    country_cn: '中国 +86',
    country_ph: '菲律宾 +63',
    country_vn: '越南 +84',
    country_my: '马来 +60',
    country_kh: '柬埔寨 +855',
    country_id: '印尼 +62',
    alert_phone_invalid: '手机号不正确',
    alert_phone_required: '请输入手机号',
    alert_captcha_required: '请输入验证码',
    alert_sms_sent: '验证码已发送',
    alert_sms_hint_default: '请查收短信',
    alert_sms_fail: '发送失败',
    alert_login_new: '注册成功，欢迎加入',
    alert_login_back: '登录成功，欢迎回来',
    alert_login_fail: '登录失败',
    alert_slider_fail: '请拖到最右侧',
    slider_modal_title: '安全验证',
    slider_modal_hint: '请按住滑块，拖动到最右侧',
    slider_track_hint: '拖动滑块到右侧 →',
    slider_refresh_btn: '重试',
    slider_ok_sms: '验证通过，正在发送验证码…',
    loading_generic: '加载中...',
    profile_logout_btn: '退出登录',
    profile_vip_badge: '官方会员',
    profile_user_id_label: '会员ID',
    profile_uid_copy_btn: '复制',
    profile_mobile_label: '绑定手机',
    profile_quick_qr: '二维码',
    profile_quick_scan: '扫一扫',
    profile_quick_recharge: '充值',
    profile_quick_withdraw: '提现',
    profile_section_asset: '资产服务',
    profile_menu_payee: '钱包地址',
    profile_menu_payee_sub: '绑定银行卡与数字钱包',
    profile_menu_ledger: '资金流水',
    profile_menu_ledger_sub: '红宝与股份变动明细',
    profile_section_security: '账号与安全',
    profile_menu_info: '头像与昵称',
    profile_menu_info_sub: '修改头像、昵称',
    profile_menu_password: '修改密码',
    profile_menu_password_sub: '旧密码或短信验证',
    profile_menu_pay_password: '支付密码',
    profile_menu_pay_password_sub: '提现与绑定地址校验',
    profile_foot_note: '红宝官方 · 会员中心',
    profile_uid_copy_empty: '暂无会员ID',
    profile_uid_copied: '已复制',
    profile_logout_confirm: '确定退出当前账号？',
  },
  'en-PH': {
    brand_name: 'Hongbao',
    skin_label: 'Theme',
    skin_option_default: 'Default',
    skin_option_a: 'China Red',
    skin_option_b: 'Royal Blue',
    skin_option_d: 'Tech Silver',
    lang_zh: '中文',
    lang_en: 'English',
    lang_vi: 'Tiếng Việt',
    lang_ms: 'Melayu',
    lang_km: 'ខ្មែរ',
    lang_id: 'Indonesia',
    tab_bar_home: 'Home',
    tab_bar_exchange: 'Swap',
    tab_bar_messages: 'Chat',
    tab_bar_master: 'Fission',
    tab_bar_fission: 'Fission',
    tab_bar_profile: 'Me',
    promote_earn_title: 'Promo earnings',
    promote_earn_live: 'Live ›',
    promote_earn_col_uid: 'User ID',
    promote_earn_col_type: 'Type',
    promote_earn_col_detail: 'Detail',
    promote_earn_col_amount: 'Commission',
    page_hero_exchange_title: '⚡ VIP Flash Exchange',
    page_hero_exchange_sub: 'Shares ↔ Hongbao · Live estimate',
    swap_submit: 'Confirm swap',
    swap_all_btn: 'All',
    swap_aria_flip: 'Flip direction',
    swap_to_label: 'Receive',
    swap_rate_label: 'Rate',
    swap_est_label: 'Estimated',
    swap_from_label: 'Send',
    swap_from_with_asset: 'Send {asset}',
    swap_title_pair: '{from} → {to}',
    swap_pair_closed: '{from} → {to} is closed',
    swap_asset_hongbao: 'Hongbao',
    swap_asset_rights: 'Shares',
    swap_unit_hongbao: 'HB',
    swap_unit_share: 'sh',
    swap_avail_hongbao: 'Available {amount} Hongbao',
    swap_avail_rights: 'Available {amount} shares',
    swap_est_shares: '{amount} shares',
    swap_rate_line: '1 {from} = {rate} {to}',
    share_swap_rights_locked_hint: 'Can swap {free} (locked {locked}, free tomorrow)',
    profile_ex_r2b_closed: 'Share-to-Hongbao swap is closed',
    profile_ex_max_hint: 'Max per swap {max}',
    alert_exchange_swap_ok: '🎉 Swap successful',
    alert_exchange_fail: 'Swap failed',
    alert_exchange_disabled: 'Exchange is disabled',
    alert_exchange_pair_invalid: 'This swap direction is closed',
    asset_hongbao_label: 'Hongbao',
    asset_shares_label: 'Shares',
    login_subtitle: 'Activate official welfare channel: enter your mobile number',
    login_phone_label: 'Member registration: mobile number',
    login_phone_placeholder: 'Enter 11-digit China mainland mobile',
    login_captcha_label: 'Security code',
    login_captcha_placeholder: 'SMS verification code',
    login_captcha_btn: 'Get code',
    login_captcha_resend: '{count}s',
    login_submit_btn: 'Enter Official Rewards Hall — Get {register_rights} free shares',
    loading_generic: 'Loading...',
    profile_logout_btn: 'Log out',
    profile_vip_badge: 'Official member',
    profile_user_id_label: 'Member ID',
    profile_uid_copy_btn: 'Copy',
    profile_mobile_label: 'Mobile',
    profile_quick_qr: 'QR code',
    profile_quick_scan: 'Scan',
    profile_quick_recharge: 'Top up',
    profile_quick_withdraw: 'Withdraw',
    profile_section_asset: 'Assets',
    profile_menu_payee: 'Wallet address',
    profile_menu_payee_sub: 'Bank cards & crypto wallets',
    profile_menu_ledger: 'Ledger',
    profile_menu_ledger_sub: 'Hongbao & share changes',
    profile_section_security: 'Account & security',
    profile_menu_info: 'Avatar & nickname',
    profile_menu_info_sub: 'Update avatar, nickname',
    profile_menu_password: 'Change password',
    profile_menu_password_sub: 'Old password or SMS',
    profile_menu_pay_password: 'Payment password',
    profile_menu_pay_password_sub: 'For withdraw & binding',
    profile_foot_note: 'Hongbao · Member center',
    profile_uid_copy_empty: 'No member ID',
    profile_uid_copied: 'Copied',
    profile_logout_confirm: 'Log out of this account?',
  },
}

const packs = Object.assign({}, BOOT_COPY)
const localeRef = ref(DEFAULT_LOCALE)
const readyRef = ref(false)
const listeners = new Set()
const loadPromises = {}
/** 已成功拉取完整语言包的 locale（BOOT 离线包不算） */
const fullLoaded = Object.create(null)
/** 服务端 config.copy（含后台多语言覆盖），优先于静态语言包 */
const serverCopy = Object.create(null)
const copyTick = ref(0)

function readStoredLocale() {
  try {
    const v = uni.getStorageSync(LOCALE_STORAGE_KEY) || DEFAULT_LOCALE
    return LOCALE_META[v] ? v : DEFAULT_LOCALE
  } catch (e) {
    return DEFAULT_LOCALE
  }
}

export function getLocale() {
  return localeRef.value || readStoredLocale()
}

export function localeState() {
  return localeRef
}

export function i18nReady() {
  return readyRef
}

export function onLocaleChange(fn) {
  listeners.add(fn)
  return () => listeners.delete(fn)
}

function fillTpl(tpl, vars) {
  if (!vars) return tpl
  return String(tpl).replace(/\{(\w+)\}/g, (_, k) =>
    vars[k] != null ? String(vars[k]) : '{' + k + '}'
  )
}

export function applyServerCopy(copy) {
  if (!copy || typeof copy !== 'object') return
  let n = 0
  Object.keys(copy).forEach((k) => {
    const v = copy[k]
    if (v == null || String(v) === '') return
    serverCopy[k] = String(v)
    n++
  })
  if (n) copyTick.value++
}

export function clearServerCopy() {
  Object.keys(serverCopy).forEach((k) => {
    delete serverCopy[k]
  })
  copyTick.value++
}

/** 供 computed 订阅文案变更（语言包 / 服务端 copy） */
export function copyState() {
  return copyTick
}

export function t(key, vars) {
  void copyTick.value
  const loc = getLocale()
  let tpl = ''
  if (serverCopy[key] != null && String(serverCopy[key]) !== '') {
    tpl = serverCopy[key]
  } else {
    // 缺 key 时优先中文，避免「页面全是英文 / key 名」
    const chain = loc === 'zh-CN' ? ['zh-CN', 'en-PH'] : [loc, 'zh-CN', 'en-PH']
    for (let i = 0; i < chain.length; i++) {
      const pack = packs[chain[i]]
      if (pack && pack[key] != null && String(pack[key]) !== '') {
        tpl = pack[key]
        break
      }
    }
  }
  if (!tpl) tpl = key
  return fillTpl(tpl, vars)
}

/** 取文案；若仍是 key 本身则用 fallback（对齐 888 chatT；支持 {var}） */
export function tt(key, fallback, vars) {
  const v = t(key, vars)
  const rawKey = String(key || '')
  if (!v || v === rawKey || v === fillTpl(rawKey, vars)) {
    return fillTpl(String(fallback != null && fallback !== '' ? fallback : rawKey), vars)
  }
  return v
}

function parseLocaleScript(text, locale) {
  if (!text) return null
  const marker = 'FANSHUB_LOCALES["' + locale + '"]'
  let idx = text.indexOf(marker)
  if (idx < 0) {
    idx = text.indexOf("FANSHUB_LOCALES['" + locale + "']")
  }
  if (idx < 0) {
    // fallback: first { ... }
    const s = text.indexOf('{')
    const e = text.lastIndexOf('}')
    if (s >= 0 && e > s) {
      try {
        return JSON.parse(text.slice(s, e + 1))
      } catch (err) {
        return null
      }
    }
    return null
  }
  const eq = text.indexOf('=', idx)
  const s = text.indexOf('{', eq)
  const e = text.lastIndexOf('}')
  if (s < 0 || e <= s) return null
  try {
    return JSON.parse(text.slice(s, e + 1))
  } catch (err) {
    return null
  }
}

export function ensureLocaleLoaded(locale) {
  const loc = LOCALE_META[locale] ? locale : DEFAULT_LOCALE
  // 不得用 BOOT key 数量判断：BOOT 已 >30，会误跳过完整包加载
  if (fullLoaded[loc] && packs[loc] && packs[loc].jackpot_label) {
    return Promise.resolve(loc)
  }
  if (loadPromises[loc]) return loadPromises[loc]

  loadPromises[loc] = new Promise((resolve) => {
    const base = assetBase()
    const urls = [
      base + 'i18n/locales/' + encodeURIComponent(loc) + '.js',
      // #ifdef H5
      '/999/i18n/locales/' + encodeURIComponent(loc) + '.js',
      '/888/i18n/locales/' + encodeURIComponent(loc) + '.js',
      // #endif
    ].map((u) => ensureAbsoluteHttpUrl(u, base)).filter(Boolean)
    let i = 0
    const tryNext = () => {
      if (i >= urls.length) {
        if (!packs[loc]) packs[loc] = Object.assign({}, BOOT_COPY[loc] || BOOT_COPY[DEFAULT_LOCALE] || {})
        copyTick.value++
        delete loadPromises[loc]
        resolve(loc)
        return
      }
      const url = urls[i++]
      uni.request({
        url,
        method: 'GET',
        dataType: 'text',
        success(res) {
          const ok = res && (res.statusCode === 200 || res.statusCode === 0 || res.statusCode == null)
          const body = typeof res.data === 'string' ? res.data : ''
          const parsed = ok ? parseLocaleScript(body, loc) : null
          if (parsed && typeof parsed === 'object' && Object.keys(parsed).length > 50) {
            packs[loc] = Object.assign({}, BOOT_COPY[loc] || {}, parsed)
            fullLoaded[loc] = true
            copyTick.value++
            delete loadPromises[loc]
            resolve(loc)
            return
          }
          tryNext()
        },
        fail() {
          tryNext()
        },
      })
    }
    tryNext()
  })
  return loadPromises[loc]
}

export function syncTabBarLabels() {
  const items = [
    { index: 0, text: t('tab_bar_home') },
    { index: 1, text: t('tab_bar_exchange') },
    { index: 2, text: t('tab_bar_messages') },
    { index: 3, text: t('tab_bar_fission') || t('tab_bar_master') },
    { index: 4, text: t('tab_bar_profile') },
  ]
  items.forEach((it) => {
    try {
      uni.setTabBarItem({ index: it.index, text: it.text })
    } catch (e) {}
  })
}

export async function setLocale(locale) {
  const loc = LOCALE_META[locale] ? locale : DEFAULT_LOCALE
  await ensureLocaleLoaded(loc)
  clearServerCopy()
  localeRef.value = loc
  try {
    uni.setStorageSync(LOCALE_STORAGE_KEY, loc)
    const meta = LOCALE_META[loc]
    if (meta && meta.country) uni.setStorageSync(COUNTRY_STORAGE_KEY, meta.country)
  } catch (e) {}
  // #ifdef H5
  if (typeof document !== 'undefined' && document.documentElement) {
    document.documentElement.lang = loc
  }
  // #endif
  syncTabBarLabels()
  listeners.forEach((fn) => {
    try {
      fn(loc)
    } catch (e) {}
  })
  return loc
}

export async function initI18n() {
  const loc = readStoredLocale()
  localeRef.value = loc
  // 始终拉中文完整包作兜底，再拉当前语言
  await ensureLocaleLoaded(DEFAULT_LOCALE)
  if (loc !== DEFAULT_LOCALE) {
    await ensureLocaleLoaded(loc)
  }
  readyRef.value = true
  copyTick.value++
  // #ifdef H5
  if (typeof document !== 'undefined' && document.documentElement) {
    document.documentElement.lang = loc
  }
  // #endif
  syncTabBarLabels()
  return loc
}

export function assetBase() {
  return getStaticBase()
}

export function flagUrl(iso) {
  const id = String(iso || 'cn').toLowerCase()
  return packagedStaticUrl('flags/' + id + '.svg')
}

export function logoUrl() {
  return packagedStaticUrl('logo.png')
}

export function localeOptions() {
  return LOCALE_ORDER.map((id) => {
    const meta = LOCALE_META[id]
    return {
      id,
      flagIso: meta.flagIso,
      label: t(meta.labelKey) || meta.fallback,
    }
  })
}
