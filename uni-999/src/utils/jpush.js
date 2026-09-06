/**
 * 极光推送（manifest 插件名：luanqing-jgpush）
 * - 在线 / 前台：只用 IM WebSocket + 本地提示音（停系统推送）
 * - 后台离线：服务端按 Registration ID 发极光
 * - 未登录：不注册、清别名、停推送、尽量禁用本机 RID
 * - 登录后：register + setAlias(u{uid}) + 上报 RID
 */

import { apiRequest, getToken } from './auth.js'
import { getApiBase, getLocale } from './config.js'
import { isPushEnabled, setPushEnabled } from './app-prefs.js'

const PLUGIN_ID = 'luanqing-jgpush'
const RID_CACHE_KEY = 'hb_jpush_rid'

let jpush = null
let registered = false
let lastRid = ''
let reporting = false
/** 前台时停系统通知，避免与本地提示音叠播 */
let foregroundSilenced = false

function tryGetJPush() {
  if (jpush) return jpush
  // #ifdef APP-PLUS
  try {
    if (typeof uni !== 'undefined' && uni.requireNativePlugin) {
      jpush = uni.requireNativePlugin(PLUGIN_ID) || null
    }
  } catch (e) {
    jpush = null
  }
  // #endif
  return jpush
}

function detectPlatform() {
  // #ifdef APP-PLUS
  try {
    const p = String((uni.getSystemInfoSync() || {}).platform || '').toLowerCase()
    if (p === 'ios') return 'ios'
    if (p === 'android') return 'android'
  } catch (e) {}
  // #endif
  return ''
}

function rememberRid(rid) {
  const id = String(rid || '').trim()
  if (!isValidRegistrationId(id)) return
  lastRid = id
  try {
    uni.setStorageSync(RID_CACHE_KEY, id)
  } catch (e) {}
}

function cachedRid() {
  if (lastRid && isValidRegistrationId(lastRid)) return lastRid
  try {
    const s = String(uni.getStorageSync(RID_CACHE_KEY) || '').trim()
    if (isValidRegistrationId(s)) {
      lastRid = s
      return s
    }
  } catch (e) {}
  return ''
}

/** 极光 Registration ID：拒绝把插件日志/别名文案当 RID 上报 */
function isValidRegistrationId(rid) {
  const id = String(rid || '').trim()
  if (!id || id.length < 10 || id.length > 128) return false
  if (/^u\d+$/i.test(id)) return false
  if (!/^[a-zA-Z0-9_-]+$/.test(id)) return false
  if (/别名|注册|成功|失败|极光|状态|设置/.test(id)) return false
  return true
}

function extractRid(raw) {
  if (raw == null) return ''
  if (typeof raw === 'string') {
    const s = raw.trim()
    if (isValidRegistrationId(s)) return s
    const m = s.match(/(?:registration[_ ]?id|registerID|成功[（(])\s*[=:：]?\s*([a-zA-Z0-9_-]{10,128})/i)
    if (m && isValidRegistrationId(m[1])) return m[1]
    const m2 = s.match(/\(([a-zA-Z0-9_-]{10,128})\)/)
    if (m2 && isValidRegistrationId(m2[1])) return m2[1]
    try {
      return extractRid(JSON.parse(s))
    } catch (e) {
      return ''
    }
  }
  if (typeof raw !== 'object') return ''
  if (raw.type === 'log' || raw.type === 'alias' || raw.type === 'tags') {
    return extractRid(raw.data != null ? raw.data : raw.info)
  }
  const direct =
    raw.registerID ||
    raw.registrationID ||
    raw.registrationId ||
    raw.registration_id ||
    raw.rid ||
    ''
  if (direct && isValidRegistrationId(String(direct).trim())) return String(direct).trim()
  if (direct) {
    const fromText = extractRid(String(direct))
    if (fromText) return fromText
  }
  if (raw.data) return extractRid(raw.data)
  if (raw.info) return extractRid(raw.info)
  return ''
}

function clearAlias(jp) {
  if (!jp) return
  try {
    if (typeof jp.deleteAlias === 'function') jp.deleteAlias({})
    else if (typeof jp.clearAlias === 'function') jp.clearAlias()
    else if (typeof jp.delAlias === 'function') jp.delAlias()
    else if (typeof jp.removeAlias === 'function') jp.removeAlias()
    else if (typeof jp.setAlias === 'function') jp.setAlias({ alias: '' })
  } catch (e) {}
}

function stopPushLocal(jp) {
  if (!jp) return
  try {
    if (typeof jp.stopPush === 'function') jp.stopPush()
    else if (typeof jp.unregisterJPush === 'function') {
      jp.unregisterJPush()
      registered = false
    }
  } catch (e) {}
}

function resumePushLocal(jp) {
  if (!jp || !isPushEnabled() || !getToken()) return
  try {
    if (typeof jp.resumePush === 'function') jp.resumePush()
  } catch (e) {}
}

function uploadRegistration(rid, platform) {
  if (!getToken()) return Promise.resolve(null)
  const id = String(rid || '').trim()
  if (!isValidRegistrationId(id)) return Promise.resolve(null)
  if (reporting && id === lastRid) return Promise.resolve(null)
  reporting = true
  rememberRid(id)
  return apiRequest('pushregister', 'POST', {
    registration_id: id,
    platform: platform || detectPlatform(),
    enabled: isPushEnabled() ? 1 : 0,
  })
    .catch(() => null)
    .finally(() => {
      reporting = false
    })
}

/** 未登录也可按 RID 关闭推送（重装/退出后残留设备） */
function disableRidOnServer(rid) {
  const id = String(rid || cachedRid() || '').trim()
  if (!isValidRegistrationId(id)) return Promise.resolve(null)
  const base = String(getApiBase() || '').replace(/\/+$/, '')
  if (!base) return Promise.resolve(null)
  const url = base + '/api/fanshub/pushdevicedisable'
  const locale = getLocale()
  return new Promise((resolve) => {
    try {
      uni.request({
        url,
        method: 'POST',
        data: { registration_id: id, platform: detectPlatform() },
        header: {
          'Content-Type': 'application/json',
          'X-Fanshub-Locale': locale || '',
        },
        timeout: 8000,
        complete: () => resolve(null),
      })
    } catch (e) {
      resolve(null)
    }
  })
}

function readRegistrationId(jp) {
  return new Promise((resolve) => {
    if (!jp) {
      resolve(cachedRid())
      return
    }
    try {
      if (typeof jp.getRegistrationID === 'function') {
        jp.getRegistrationID((r) => {
          const id = extractRid(r)
          if (id) rememberRid(id)
          resolve(id || cachedRid())
        })
        return
      }
      if (typeof jp.getRegistrationId === 'function') {
        jp.getRegistrationId((r) => {
          const id = extractRid(r)
          if (id) rememberRid(id)
          resolve(id || cachedRid())
        })
        return
      }
    } catch (e) {}
    resolve(cachedRid())
  })
}

function bindAliasIfPossible(jp, userId) {
  try {
    if (!jp || typeof jp.setAlias !== 'function') return
    let uid = userId | 0
    if (!uid) {
      try {
        const raw = uni.getStorageSync('fans_hub_999_profile') || uni.getStorageSync('fans_hub_profile')
        const p = typeof raw === 'string' ? JSON.parse(raw || '{}') : raw
        uid = (p && (p.user_id || p.id)) | 0
      } catch (e) {}
    }
    if (uid > 0) jp.setAlias({ alias: 'u' + uid })
  } catch (e) {}
}

function ensureRegistered(jp) {
  if (!jp || registered) return
  if (!getToken() || !isPushEnabled()) return
  try {
    if (typeof jp.registerJPush === 'function') {
      jp.registerJPush((res) => {
        try {
          const rid = extractRid(res)
          if (rid) {
            rememberRid(rid)
            if (getToken()) uploadRegistration(rid, detectPlatform())
          }
        } catch (e) {}
      })
      registered = true
      return
    }
    if (typeof jp.init === 'function') {
      jp.init()
      registered = true
    }
    if (typeof jp.initJPushService === 'function') {
      jp.initJPushService()
      registered = true
    }
  } catch (e) {}
}

/**
 * 退出登录 / 未登录启动：停推送、清别名、禁用本机 RID
 */
export function clearPushSession() {
  // #ifdef APP-PLUS
  const jp = tryGetJPush()
  const rid = cachedRid()
  foregroundSilenced = false
  try {
    if (getToken() && rid) {
      apiRequest('pushregister', 'POST', {
        registration_id: rid,
        platform: detectPlatform(),
        enabled: 0,
      }).catch(() => {})
    } else if (rid) {
      disableRidOnServer(rid)
    }
  } catch (e) {}
  clearAlias(jp)
  stopPushLocal(jp)
  registered = false
  // #endif
}

/**
 * App 在前台：停系统通知（只用 WS 本地提示音）
 * App 进后台：恢复系统推送（若已登录且开关开）
 */
export function setAppPushForeground(active) {
  // #ifdef APP-PLUS
  const jp = tryGetJPush()
  if (!jp) return
  if (active) {
    foregroundSilenced = true
    stopPushLocal(jp)
    return
  }
  foregroundSilenced = false
  if (!getToken() || !isPushEnabled()) {
    stopPushLocal(jp)
    clearAlias(jp)
    return
  }
  ensureRegistered(jp)
  resumePushLocal(jp)
  // #endif
}

export function applyPushPreference(enabled) {
  setPushEnabled(!!enabled)
  // #ifdef APP-PLUS
  const jp = tryGetJPush()
  try {
    if (getToken()) {
      apiRequest('pushprefs', 'POST', { enabled: enabled ? 1 : 0 }).catch(() => {})
    }
  } catch (e) {}
  if (!jp) return { ok: true, wired: false }
  try {
    if (enabled && getToken()) {
      ensureRegistered(jp)
      if (!foregroundSilenced) resumePushLocal(jp)
      syncRegistrationAfterLogin()
    } else {
      clearAlias(jp)
      stopPushLocal(jp)
      registered = false
      if (cachedRid()) disableRidOnServer(cachedRid())
    }
    return { ok: true, wired: true }
  } catch (e) {
    return { ok: false, wired: true, error: (e && e.message) || String(e) }
  }
  // #endif
  // #ifndef APP-PLUS
  try {
    if (getToken()) {
      apiRequest('pushprefs', 'POST', { enabled: enabled ? 1 : 0 }).catch(() => {})
    }
  } catch (e) {}
  return { ok: true, wired: false }
  // #endif
}

export function initPushOnLaunch() {
  // #ifdef APP-PLUS
  const jp = tryGetJPush()
  if (!jp) return
  try {
    // 未登录或用户关推送：绝不 resume，并清别名 / 禁 RID，避免重装后仍收旧账号推送
    if (!getToken() || !isPushEnabled()) {
      clearPushSession()
      // 仍短暂 register 一次只为拿到 RID 去 disable（不 resume）
      try {
        if (typeof jp.registerJPush === 'function' && !registered) {
          jp.registerJPush((res) => {
            const rid = extractRid(res)
            if (rid) {
              rememberRid(rid)
              disableRidOnServer(rid)
            }
          })
          registered = true
        }
      } catch (e2) {}
      setTimeout(() => {
        readRegistrationId(jp).then((rid) => {
          if (rid) disableRidOnServer(rid)
          clearAlias(jp)
          stopPushLocal(jp)
        })
      }, 1200)
      return
    }
    ensureRegistered(jp)
    // 启动先当在前台：不弹系统通知
    setAppPushForeground(true)
    setTimeout(() => syncRegistrationAfterLogin(), 1500)
    setTimeout(() => syncRegistrationAfterLogin(), 5000)
  } catch (e) {}
  // #endif
}

/** 登录成功 / auth.ok 后调用 */
export function syncRegistrationAfterLogin(userId) {
  // #ifdef APP-PLUS
  if (!getToken() || !isPushEnabled()) return Promise.resolve(null)
  const jp = tryGetJPush()
  if (!jp) return Promise.resolve(null)
  ensureRegistered(jp)
  bindAliasIfPossible(jp, userId)
  // 登录后若在前台仍静默系统推送
  if (foregroundSilenced) stopPushLocal(jp)
  else resumePushLocal(jp)
  return readRegistrationId(jp).then((rid) => {
    if (rid) return uploadRegistration(rid, detectPlatform())
    return new Promise((resolve) => {
      setTimeout(() => {
        readRegistrationId(jp).then((rid2) => {
          resolve(uploadRegistration(rid2 || lastRid, detectPlatform()))
        })
      }, 2000)
    })
  })
  // #endif
  // #ifndef APP-PLUS
  return Promise.resolve(null)
  // #endif
}

export function isJPushPluginPresent() {
  return !!tryGetJPush()
}
