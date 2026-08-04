/**
 * 运行时配置（H5 走同源 /api 与 /im-ws；App 可在此改成绝对地址）
 */
const cfg = {
  API_BASE: '',
  // 空则自动：当前站点 /im-ws（需 Nginx 反代到 7272）
  IM_WS_URL: '',
  TOKEN_KEY: 'fans_hub_token',
  DEVICE_FP_KEY: 'fans_hub_device_fp',
  LOCALE_KEY: 'fans_hub_locale',
  LOCALE: 'zh-CN',
}

export function getApiBase() {
  return cfg.API_BASE || ''
}

export function getImWsBase() {
  if (cfg.IM_WS_URL) return String(cfg.IM_WS_URL)
  // #ifdef H5
  if (typeof location !== 'undefined') {
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:'
    const host = location.host || '127.0.0.1'
    return `${proto}//${host}/im-ws`
  }
  // #endif
  return 'ws://127.0.0.1:7272'
}

export function getTokenKey() {
  return cfg.TOKEN_KEY
}

export function getDeviceFpKey() {
  return cfg.DEVICE_FP_KEY
}

/** 与 /888 共用 fans_hub_locale；切语言后 API 带 X-Fanshub-Locale */
export function getLocale() {
  try {
    const v = uni.getStorageSync(cfg.LOCALE_KEY) || cfg.LOCALE
    return v || cfg.LOCALE
  } catch (e) {
    return cfg.LOCALE
  }
}

export default cfg
