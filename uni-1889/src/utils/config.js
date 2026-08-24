/**
 * 运行时配置
 * - H5 默认同源 /api、/im-ws
 * - App / 跨域：启动拉取阿里云 888.json（apiUri / socketUri / imgUri）
 * - App 禁止相对路径 uni.request（会变成 file:// 报 expected url scheme http or https）
 */
const RUNTIME_CFG_KEY = 'fans_hub_runtime_cfg'
/** 远端入口（每次打开网页/App 都会拉取） */
export const RUNTIME_CONFIG_URL =
  'https://8888dhcghahyanz.oss-accelerate.aliyuncs.com/888.json'

/** OSS 拉不到时的兜底（与线上 888.json 保持一致） */
export const FALLBACK_RUNTIME = {
  apiUri: 'https://hbsq.bio',
  socketUri: 'wss://hbsq.bio/im-ws',
  imgUri: 'https://888jhdhifhbchashjdl.oss-accelerate.aliyuncs.com',
}

const cfg = {
  API_BASE: '',
  // 空则自动：当前站点 /im-ws（需 Nginx 反代到 17272）
  IM_WS_URL: '',
  /** 上传/图片基址；空则回退 API_BASE；bootstrap.upload_cdn（OSS）优先于远端 imgUri */
  IMG_BASE: '',
  UPLOAD_CDN: '',
  TOKEN_KEY: 'fans_hub_1889_token',
  DEVICE_FP_KEY: 'fans_hub_1889_device_fp',
  LOCALE_KEY: 'fans_hub_1889_locale',
  LOCALE: 'zh-CN',
  /** 最近一次远端配置拉取时间 */
  RUNTIME_FETCHED_AT: 0,
}

function trimSlash(u) {
  return String(u == null ? '' : u).trim().replace(/\/+$/, '')
}

export function isAbsoluteHttpUrl(u) {
  return /^https?:\/\//i.test(String(u || '').trim())
}

export function isAbsoluteWsUrl(u) {
  return /^wss?:\/\//i.test(String(u || '').trim())
}

/**
 * 把相对路径补成 http(s) 绝对地址；App 下 uni.request 不能走 file://
 */
export function ensureAbsoluteHttpUrl(url, base) {
  const u = String(url || '').trim()
  if (!u) return ''
  if (isAbsoluteHttpUrl(u) || isAbsoluteWsUrl(u)) return u
  if (/^file:/i.test(u)) return ''
  const b = trimSlash(base || cfg.API_BASE || FALLBACK_RUNTIME.apiUri)
  if (!b || !isAbsoluteHttpUrl(b)) return ''
  if (u.startsWith('/')) return b + u
  return b + '/' + u.replace(/^\/+/, '')
}

/**
 * 静态 JSON / 资源的 uni.request 地址：
 * - H5：优先同源相对路径（/1889/...），避免被 apiUri（ESA 加速域）改写成跨域
 * - App：必须 https 绝对地址
 */
export function resolveStaticRequestUrl(relOrUrl) {
  let u = String(relOrUrl || '').trim()
  if (!u) return ''
  if (isAbsoluteHttpUrl(u) || isAbsoluteWsUrl(u)) return u
  if (!u.startsWith('/')) {
    u = getStaticBase() + u.replace(/^\/+/, '')
  }
  // #ifdef H5
  if (u.startsWith('/')) return u
  try {
    if (typeof location !== 'undefined' && location.origin && u.indexOf(location.origin) === 0) {
      return u
    }
  } catch (e) {}
  // #endif
  return ensureAbsoluteHttpUrl(u, getApiBase()) || u
}

function applyFields(apiUri, socketUri, imgUri) {
  const api = trimSlash(apiUri)
  const sock = trimSlash(socketUri)
  const img = trimSlash(imgUri)
  const prevWs = String(cfg.IM_WS_URL || '')
  if (api) cfg.API_BASE = api
  if (sock) cfg.IM_WS_URL = sock
  if (img) {
    cfg.IMG_BASE = img
    // 远端 imgUri 已是 OSS 时，直接作为 upload_cdn，避免再走本站
    if (isOssHostUrl(img)) cfg.UPLOAD_CDN = img
  } else if (api) {
    cfg.IMG_BASE = api
  }
  return prevWs !== String(cfg.IM_WS_URL || '')
}

function applyFallbackRuntime() {
  if (cfg.API_BASE && cfg.IM_WS_URL) return false
  return applyFields(
    cfg.API_BASE || FALLBACK_RUNTIME.apiUri,
    cfg.IM_WS_URL || FALLBACK_RUNTIME.socketUri,
    cfg.IMG_BASE || FALLBACK_RUNTIME.imgUri
  )
}

/** 阿里云 OSS / CDN：聊天 /uploads 展示优先 */
export function setUploadCdn(url) {
  const u = trimSlash(url)
  if (u) cfg.UPLOAD_CDN = u
}

export function getUploadCdn() {
  return cfg.UPLOAD_CDN || ''
}

/** 是否已是阿里云 OSS / 加速域名 */
export function isOssHostUrl(url) {
  return /(?:aliyuncs\.com|oss-accelerate)/i.test(String(url || ''))
}

/**
 * 从接口返回的 OSS fullurl 学习 upload_cdn（config 未下发时也能改写 /uploads）
 */
export function learnUploadCdnFromUrl(url) {
  const raw = String(url || '').trim()
  if (!raw || !isOssHostUrl(raw)) return
  try {
    const u = new URL(raw, 'https://local.invalid')
    if (u.protocol === 'http:' || u.protocol === 'https:') {
      setUploadCdn(u.origin)
    }
  } catch (e) {}
}

/**
 * /uploads 专用基址：只认 OSS upload_cdn，或已是 OSS 的 imgUri。
 * 勿回退到 apiUri（888.json 常把 imgUri 配成本站，会导致发图/头像仍走本地）。
 * 最后回退本项目默认加速域名（与 .env oss.cdn_domain / bucket 一致）。
 */
const FALLBACK_UPLOAD_CDN = 'https://888jhdhifhbchashjdl.oss-accelerate.aliyuncs.com'

export function getUploadsBase() {
  const oss = trimSlash(cfg.UPLOAD_CDN)
  if (oss) return oss
  const img = trimSlash(cfg.IMG_BASE)
  if (img && isOssHostUrl(img)) return img
  return FALLBACK_UPLOAD_CDN
}

function readCache() {
  try {
    const raw = uni.getStorageSync(RUNTIME_CFG_KEY)
    if (!raw) return null
    if (typeof raw === 'object') return raw
    return JSON.parse(String(raw))
  } catch (e) {
    return null
  }
}

function writeCache(data) {
  try {
    uni.setStorageSync(RUNTIME_CFG_KEY, data)
  } catch (e) {}
}

/** 启动时先吃本地缓存，保证首屏接口可用 */
export function hydrateRuntimeConfigFromCache() {
  const cached = readCache()
  if (!cached) return false
  applyFields(cached.apiUri, cached.socketUri, cached.imgUri)
  return !!(cfg.API_BASE || cfg.IM_WS_URL || cfg.IMG_BASE)
}

/**
 * 拉取远端 888.json 并应用。
 * @returns {Promise<{ ok: boolean, changedWs: boolean, data?: object, error?: string }>}
 */
export function fetchRuntimeConfig(force) {
  const url = RUNTIME_CONFIG_URL + (force === false ? '' : ('?t=' + Date.now()))
  return new Promise((resolve) => {
    uni.request({
      url,
      method: 'GET',
      timeout: 8000,
      success(res) {
        try {
          let data = res && res.data
          if (typeof data === 'string') {
            try {
              data = JSON.parse(data)
            } catch (e) {
              data = null
            }
          }
          if (!data || typeof data !== 'object') {
            resolve({ ok: false, changedWs: false, error: 'empty config' })
            return
          }
          const apiUri = data.apiUri || data.api_uri || data.API_BASE || ''
          const socketUri = data.socketUri || data.socket_uri || data.IM_WS_URL || ''
          const imgUri = data.imgUri || data.img_uri || data.IMG_BASE || ''
          const changedWs = applyFields(apiUri, socketUri, imgUri)
          cfg.RUNTIME_FETCHED_AT = Date.now()
          writeCache({
            apiUri: cfg.API_BASE,
            socketUri: cfg.IM_WS_URL,
            imgUri: cfg.IMG_BASE,
            fetchedAt: cfg.RUNTIME_FETCHED_AT,
          })
          resolve({ ok: true, changedWs, data: { apiUri: cfg.API_BASE, socketUri: cfg.IM_WS_URL, imgUri: cfg.IMG_BASE } })
        } catch (e) {
          resolve({ ok: false, changedWs: false, error: (e && e.message) || 'parse fail' })
        }
      },
      fail(err) {
        resolve({
          ok: false,
          changedWs: false,
          error: (err && err.errMsg) || 'network fail',
        })
      },
    })
  })
}

/**
 * 打开网页 / App：先缓存，再拉远端；App 拉失败则用内置兜底域名。
 * @returns {Promise<{ ok: boolean, changedWs: boolean }>}
 */
export async function bootstrapRuntimeConfig() {
  hydrateRuntimeConfigFromCache()
  // #ifdef APP-PLUS
  // App 首屏绝不能空着 apiUri，否则 /api/... 会变成 file://
  applyFallbackRuntime()
  // #endif
  const r = await fetchRuntimeConfig(true)
  if (!r.ok) {
    applyFallbackRuntime()
  }
  return { ok: !!cfg.API_BASE, changedWs: !!r.changedWs }
}

export function getApiBase() {
  return cfg.API_BASE || ''
}

/** 上传 / 图片 CDN 基址：OSS upload_cdn > imgUri > apiUri */
export function getImgBase() {
  return cfg.UPLOAD_CDN || cfg.IMG_BASE || cfg.API_BASE || ''
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
  return FALLBACK_RUNTIME.socketUri
}

/**
 * H5/App 静态资源前缀。
 * App 必须返回 https 绝对前缀，禁止 '/'（uni.request 会变成 file://）。
 */
export function getStaticBase() {
  // #ifdef H5
  try {
    if (typeof import.meta !== 'undefined' && import.meta.env && import.meta.env.BASE_URL) {
      return String(import.meta.env.BASE_URL).replace(/\/?$/, '/')
    }
  } catch (e) {}
  if (typeof location !== 'undefined' && /\/1889\b/.test(location.pathname || '')) {
    return '/1889/'
  }
  // #endif
  // #ifdef APP-PLUS
  const api = trimSlash(cfg.API_BASE || FALLBACK_RUNTIME.apiUri)
  return api + '/1889/'
  // #endif
  return '/'
}

/**
 * App 打包进 APK 的 UI 图：必须用 /static/...（本地），勿拼远程 /1889/（服务器没同步就空白）。
 * H5 仍走 getStaticBase()。
 * @param {string} rel 如 'tab/fission.png' 或 'static/tab/fission.png'
 */
export function packagedStaticUrl(rel) {
  let p = String(rel || '').trim().replace(/^\/+/, '')
  if (p.indexOf('static/') === 0) p = p.slice('static/'.length)
  // #ifdef APP-PLUS
  return '/static/' + p
  // #endif
  return getStaticBase() + 'static/' + p
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
