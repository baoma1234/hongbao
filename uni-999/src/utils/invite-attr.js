/**
 * 邀请归因：H5 链接 / URL Scheme / 剪贴板 → App 登录自动带上
 *
 * Scheme 示例：
 *   hbsq://invite?code=58904307&fission=1&aid=3
 *   hongbao://invite?code=58904307
 *
 * 剪贴板标记：HBINVITE:58904307
 */
export const INVITE_STORAGE_KEY = 'fans_hub_pending_invite'
export const INVITE_CLIP_PREFIX = 'HBINVITE:'
/** App 自定义 URL Scheme（需重新打包后生效） */
export const APP_URL_SCHEMES = ['hbsq', 'hongbao']

/** 八位会员邀请码 */
export function normalizeInviteCode(raw) {
  const s = String(raw || '').trim()
  if (!s) return ''
  const m = s.match(/(?:HBINVITE:)?(\d{6,12})/i)
  if (!m) return ''
  const n = String(m[1] || '').replace(/^0+/, '') || '0'
  if (!/^\d{6,12}$/.test(n)) return ''
  return n
}

export function getStoredInviteCode() {
  try {
    return normalizeInviteCode(uni.getStorageSync(INVITE_STORAGE_KEY) || '')
  } catch (e) {
    return ''
  }
}

export function saveInviteCode(code, opts = {}) {
  const c = normalizeInviteCode(code)
  if (!c) return ''
  try {
    uni.setStorageSync(INVITE_STORAGE_KEY, c)
  } catch (e) {}
  if (opts.writeClipboard !== false) {
    writeInviteClipboard(c)
  }
  return c
}

export function clearInviteCode() {
  try {
    uni.removeStorageSync(INVITE_STORAGE_KEY)
  } catch (e) {}
}

export function writeInviteClipboard(code) {
  const c = normalizeInviteCode(code)
  if (!c) return
  const text = INVITE_CLIP_PREFIX + c
  try {
    uni.setClipboardData({
      data: text,
      showToast: false,
      success: () => {},
      fail: () => {},
    })
  } catch (e) {}
  // #ifdef H5
  try {
    if (typeof navigator !== 'undefined' && navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).catch(() => {})
    }
  } catch (e2) {}
  // #endif
}

export function parseInviteFromClipboardText(text) {
  const s = String(text || '')
  if (!s) return ''
  const idx = s.toUpperCase().indexOf('HBINVITE:')
  if (idx >= 0) {
    return normalizeInviteCode(s.slice(idx))
  }
  const t = s.trim()
  if (/^\d{6,12}$/.test(t)) return normalizeInviteCode(t)
  return ''
}

export function readInviteClipboard() {
  return new Promise((resolve) => {
    try {
      uni.getClipboardData({
        success: (res) => {
          resolve(parseInviteFromClipboardText(res && res.data))
        },
        fail: () => resolve(''),
      })
    } catch (e) {
      resolve('')
    }
  })
}

/**
 * 解析自定义 Scheme / https 深链里的邀请码
 * 支持：
 *   hbsq://invite?code=58904307
 *   hbsq://invite/58904307
 *   hongbao://999?code=58904307
 *   https://hbsq.bio/999?code=58904307
 */
export function parseInviteFromDeepLink(url) {
  const raw = String(url || '').trim()
  if (!raw) return ''
  try {
    const qIdx = raw.indexOf('?')
    if (qIdx >= 0) {
      const qs = raw.slice(qIdx + 1).split('#')[0]
      const sp = new URLSearchParams(qs)
      const c = normalizeInviteCode(sp.get('code') || sp.get('invite') || '')
      if (c) return c
    }
    const pathPart = raw.replace(/^[a-z][a-z0-9+.-]*:/i, '').replace(/^\/\//, '')
    const noHost = pathPart.replace(/^[^/?#]+/, '')
    const m1 = (noHost || pathPart).match(/\/(?:invite|code)\/(\d{6,12})(?:[/?#]|$)/i)
    if (m1) return normalizeInviteCode(m1[1])
    const m2 = pathPart.match(/(?:^|[/?#])(\d{6,12})(?:[/?#]|$)/)
    if (m2 && /invite/i.test(raw)) return normalizeInviteCode(m2[1])
  } catch (e) {}
  return parseInviteFromClipboardText(raw)
}

/** 生成 App Scheme 邀请链接 */
export function buildAppInviteScheme(code, extra = {}) {
  const c = normalizeInviteCode(code)
  if (!c) return ''
  const scheme = APP_URL_SCHEMES[0] || 'hbsq'
  const q = new URLSearchParams()
  q.set('code', c)
  if (extra.fission) q.set('fission', '1')
  if (extra.aid) q.set('aid', String(extra.aid))
  return scheme + '://invite?' + q.toString()
}

/**
 * 从路由 query / H5 location 捕获邀请码并落盘
 * @returns {string}
 */
export function captureInviteFromQuery(query) {
  let code = ''
  try {
    if (query && typeof query === 'object') {
      code = normalizeInviteCode(query.code || query.invite || '')
    }
  } catch (e) {}
  // #ifdef H5
  try {
    if (!code && typeof location !== 'undefined') {
      const sp = new URLSearchParams(location.search || '')
      code = normalizeInviteCode(sp.get('code') || sp.get('invite') || '')
      if (!code && location.hash) {
        const h = String(location.hash)
        const q = h.indexOf('?')
        if (q >= 0) {
          const hp = new URLSearchParams(h.slice(q + 1))
          code = normalizeInviteCode(hp.get('code') || hp.get('invite') || '')
        }
      }
    }
  } catch (e2) {}
  // #endif
  if (code) saveInviteCode(code)
  return code
}

/**
 * App：从 plus.runtime.arguments / 启动参数抓邀请码
 */
export function captureInviteFromAppArgs(launchOptions) {
  let code = ''
  try {
    if (launchOptions && typeof launchOptions === 'object') {
      code = normalizeInviteCode(
        (launchOptions.query && (launchOptions.query.code || launchOptions.query.invite)) ||
          launchOptions.code ||
          ''
      )
      if (!code && launchOptions.path) {
        code = parseInviteFromDeepLink(String(launchOptions.path))
      }
      if (!code && launchOptions.referrerInfo && launchOptions.referrerInfo.extraData) {
        const ex = launchOptions.referrerInfo.extraData
        code = normalizeInviteCode(ex.code || ex.invite || '')
      }
    }
  } catch (e) {}
  // #ifdef APP-PLUS
  try {
    if (!code && typeof plus !== 'undefined' && plus.runtime) {
      const arg = String(plus.runtime.arguments || '')
      if (arg) code = parseInviteFromDeepLink(arg)
    }
  } catch (e2) {}
  // #endif
  if (code) saveInviteCode(code)
  return code
}

/**
 * 绑定 App 冷启动 / 热启动 Scheme 监听（仅 APP-PLUS）
 */
export function bindAppInviteSchemeListener() {
  // #ifdef APP-PLUS
  try {
    captureInviteFromAppArgs({})
    if (typeof plus !== 'undefined' && plus.globalEvent) {
      plus.globalEvent.addEventListener('newintent', () => {
        try {
          captureInviteFromAppArgs({})
        } catch (e) {}
      })
    }
  } catch (e2) {}
  // #endif
}

/**
 * H5：尝试用 Scheme 打开已安装 App；失败则走下载/继续网页
 * @returns {Promise<'opened'|'fallback'>}
 */
export function tryOpenAppWithInvite(code, opts = {}) {
  const c = normalizeInviteCode(code) || getStoredInviteCode()
  const schemeUrl = buildAppInviteScheme(c, opts)
  const downloadUrl = String(opts.downloadUrl || '')
  const waitMs = Math.max(800, Number(opts.waitMs) || 1600)

  return new Promise((resolve) => {
    if (!schemeUrl) {
      resolve('fallback')
      return
    }
    saveInviteCode(c)
    let settled = false
    const done = (r) => {
      if (settled) return
      settled = true
      resolve(r)
    }
    const onHidden = () => {
      done('opened')
    }
    try {
      if (typeof document !== 'undefined') {
        document.addEventListener('visibilitychange', function once() {
          if (document.hidden) {
            document.removeEventListener('visibilitychange', once)
            onHidden()
          }
        })
        window.addEventListener('pagehide', onHidden, { once: true })
      }
    } catch (e) {}

    try {
      const iframe = document.createElement('iframe')
      iframe.style.display = 'none'
      iframe.src = schemeUrl
      document.body.appendChild(iframe)
      setTimeout(() => {
        try {
          document.body.removeChild(iframe)
        } catch (e2) {}
      }, 800)
    } catch (e3) {}
    try {
      window.location.href = schemeUrl
    } catch (e4) {}

    setTimeout(() => {
      if (settled) return
      if (downloadUrl) {
        try {
          window.location.href = downloadUrl
        } catch (e5) {}
      }
      done('fallback')
    }, waitMs)
  })
}

/**
 * 登录页解析最终邀请码：URL / Scheme > 缓存 > 剪贴板
 */
export async function resolveInviteCodeForLogin(query) {
  const fromApp = captureInviteFromAppArgs(query || {})
  if (fromApp) return fromApp
  const fromUrl = captureInviteFromQuery(query || {})
  if (fromUrl) return fromUrl
  const stored = getStoredInviteCode()
  if (stored) return stored
  const clip = await readInviteClipboard()
  if (clip) {
    saveInviteCode(clip, { writeClipboard: false })
    return clip
  }
  return ''
}
