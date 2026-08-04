import { ref } from 'vue'

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

/** 离线兜底（顶栏 / 登录 / Tab） */
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
    tab_bar_master: '团长',
    tab_bar_profile: '我的',
    page_hero_exchange_title: '⚡ VIP 闪兑大厅',
    page_hero_exchange_sub: '股份 ↔ 红宝 · 实时预估到账',
    swap_submit: '确认兑换',
    swap_all_btn: '全部',
    alert_exchange_swap_ok: '🎉 兑换成功',
    alert_exchange_fail: '兑换失败',
    alert_exchange_disabled: '兑换功能已关闭',
    login_phone_label: '手机号',
    login_phone_placeholder: '请输入手机号',
    login_captcha_label: '验证码',
    login_captcha_placeholder: '短信验证码',
    login_captcha_btn: '获取验证码',
    login_submit_btn: '登录 / 注册',
    profile_logout_btn: '退出登录',
    loading_generic: '加载中...',
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
    page_hero_exchange_title: '⚡ VIP Flash Exchange',
    page_hero_exchange_sub: 'Shares ↔ Hongbao · Live estimate',
    swap_submit: 'Confirm swap',
    swap_all_btn: 'All',
    alert_exchange_swap_ok: '🎉 Swap successful',
    alert_exchange_fail: 'Swap failed',
    alert_exchange_disabled: 'Exchange is disabled',
    tab_bar_messages: 'Chat',
    tab_bar_master: 'Leader',
    tab_bar_profile: 'Me',
    login_phone_label: 'Mobile',
    login_phone_placeholder: 'Phone number',
    login_captcha_label: 'Code',
    login_captcha_placeholder: 'SMS code',
    login_captcha_btn: 'Get code',
    login_submit_btn: 'Login / Sign up',
    profile_logout_btn: 'Log out',
    loading_generic: 'Loading...',
  },
}

const packs = Object.assign({}, BOOT_COPY)
const localeRef = ref(DEFAULT_LOCALE)
const readyRef = ref(false)
const listeners = new Set()
const loadPromises = {}
/** 已成功拉取完整语言包的 locale（BOOT 离线包不算） */
const fullLoaded = Object.create(null)

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

export function t(key, vars) {
  const loc = getLocale()
  // 缺 key 时优先中文，避免「页面全是英文 / key 名」
  const chain = loc === 'zh-CN' ? ['zh-CN', 'en-PH'] : [loc, 'zh-CN', 'en-PH']
  let tpl = ''
  for (let i = 0; i < chain.length; i++) {
    const pack = packs[chain[i]]
    if (pack && pack[key] != null && String(pack[key]) !== '') {
      tpl = pack[key]
      break
    }
  }
  if (!tpl) tpl = key
  return fillTpl(tpl, vars)
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
      '/999/i18n/locales/' + encodeURIComponent(loc) + '.js',
      '/888/i18n/locales/' + encodeURIComponent(loc) + '.js',
      '../888/i18n/locales/' + encodeURIComponent(loc) + '.js',
    ]
    let i = 0
    const tryNext = () => {
      if (i >= urls.length) {
        if (!packs[loc]) packs[loc] = Object.assign({}, BOOT_COPY[loc] || BOOT_COPY[DEFAULT_LOCALE] || {})
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
    { index: 3, text: t('tab_bar_master') },
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
  // #ifdef H5
  if (typeof document !== 'undefined' && document.documentElement) {
    document.documentElement.lang = loc
  }
  // #endif
  syncTabBarLabels()
  return loc
}

export function assetBase() {
  // #ifdef H5
  try {
    if (typeof import.meta !== 'undefined' && import.meta.env && import.meta.env.BASE_URL) {
      return String(import.meta.env.BASE_URL).replace(/\/?$/, '/')
    }
  } catch (e) {}
  if (typeof location !== 'undefined' && /\/999\b/.test(location.pathname || '')) {
    return '/999/'
  }
  // #endif
  return '/'
}

export function flagUrl(iso) {
  const id = String(iso || 'cn').toLowerCase()
  return assetBase() + 'static/flags/' + id + '.svg'
}

export function logoUrl() {
  return assetBase() + 'static/logo.png'
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
