/**
 * 邀请归因：H5 链接 → 本机缓存 + 剪贴板标记 → App 登录自动带上
 *
 * 标记格式：HBINVITE:58904307（避免误读普通数字剪贴板）
 */
export const INVITE_STORAGE_KEY = 'fans_hub_pending_invite'
export const INVITE_CLIP_PREFIX = 'HBINVITE:'

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
  // 仅当整段就是纯邀请码时才采纳，避免误绑电话号码等
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
 * 登录页解析最终邀请码：URL > 缓存 > 剪贴板
 */
export async function resolveInviteCodeForLogin(query) {
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
