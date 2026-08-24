/**
 * 极光推送（manifest 插件名：luanqing-jgpush）
 * - 在线：IM WebSocket + 本地提示音 / 仿推送横幅（不发极光）
 * - 离线：服务端按 Registration ID / 别名 u{uid} 发极光
 * - 登录后 registerJPush + setAlias(u{uid}) + 尽量上报 RID
 */

import { apiRequest, getToken } from './auth.js'
import { isPushEnabled, setPushEnabled } from './app-prefs.js'

const PLUGIN_ID = 'luanqing-jgpush'

let jpush = null
let registered = false
let lastRid = ''
let reporting = false

function tryGetJPush() {
  if (jpush) return jpush
  // #ifdef APP-PLUS
  try {
    if (typeof uni !== 'undefined' && uni.requireNativePlugin) {
      // 只加载已安装的云端插件，勿再 require JG-JPush（会刷「找不到插件」）
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

/** 极光 Registration ID：拒绝把插件日志/别名文案当 RID 上报 */
function isValidRegistrationId(rid) {
  const id = String(rid || '').trim()
  if (!id || id.length < 10 || id.length > 128) return false
  // 别名 u12345678 不是 RID
  if (/^u\d+$/i.test(id)) return false
  // 仅字母数字下划线横线（插件成功文案含中文/括号会被拒）
  if (!/^[a-zA-Z0-9_-]+$/.test(id)) return false
  if (/别名|注册|成功|失败|极光|状态|设置/.test(id)) return false
  return true
}

function extractRid(raw) {
  if (raw == null) return ''
  if (typeof raw === 'string') {
    const s = raw.trim()
    if (isValidRegistrationId(s)) return s
    // 从日志文案里抠 RID，例如「注册：成功(13065ffa4f5f251335…)」
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
  // 插件回调常把 type=log 的整段文案塞进 data，不能当 RID
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

function uploadRegistration(rid, platform) {
  if (!getToken()) return Promise.resolve(null)
  const id = String(rid || '').trim()
  if (!isValidRegistrationId(id)) return Promise.resolve(null)
  if (reporting && id === lastRid) return Promise.resolve(null)
  reporting = true
  lastRid = id
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

function readRegistrationId(jp) {
  return new Promise((resolve) => {
    if (!jp) {
      resolve('')
      return
    }
    try {
      if (typeof jp.getRegistrationID === 'function') {
        jp.getRegistrationID((r) => resolve(extractRid(r)))
        return
      }
      if (typeof jp.getRegistrationId === 'function') {
        jp.getRegistrationId((r) => resolve(extractRid(r)))
        return
      }
    } catch (e) {}
    resolve(lastRid || '')
  })
}

function bindAliasIfPossible(jp, userId) {
  try {
    if (!jp || typeof jp.setAlias !== 'function') return
    let uid = userId | 0
    if (!uid) {
      try {
        const raw = uni.getStorageSync('fans_hub_1889_profile') || uni.getStorageSync('fans_hub_profile')
        const p = typeof raw === 'string' ? JSON.parse(raw || '{}') : raw
        uid = (p && (p.user_id || p.id)) | 0
      } catch (e) {}
    }
    if (uid > 0) jp.setAlias({ alias: 'u' + uid })
  } catch (e) {}
}

function ensureRegistered(jp) {
  if (!jp || registered) return
  try {
    if (typeof jp.registerJPush === 'function') {
      jp.registerJPush((res) => {
        try {
          const rid = extractRid(res)
          if (rid) uploadRegistration(rid, detectPlatform())
          if (res && (res.type === 'notice-open' || res.type === 'notice')) {
            // 点击通知进聊天：payload 若有会话信息可再扩展
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

export function applyPushPreference(enabled) {
  setPushEnabled(!!enabled)
  // #ifdef APP-PLUS
  const jp = tryGetJPush()
  try {
    apiRequest('pushprefs', 'POST', { enabled: enabled ? 1 : 0 }).catch(() => {})
  } catch (e) {}
  if (!jp) return { ok: true, wired: false }
  try {
    if (enabled) {
      ensureRegistered(jp)
      if (typeof jp.resumePush === 'function') jp.resumePush()
      syncRegistrationAfterLogin()
    } else if (typeof jp.stopPush === 'function') {
      jp.stopPush()
    } else if (typeof jp.unregisterJPush === 'function') {
      jp.unregisterJPush()
      registered = false
    }
    return { ok: true, wired: true }
  } catch (e) {
    return { ok: false, wired: true, error: (e && e.message) || String(e) }
  }
  // #endif
  // #ifndef APP-PLUS
  try {
    apiRequest('pushprefs', 'POST', { enabled: enabled ? 1 : 0 }).catch(() => {})
  } catch (e) {}
  return { ok: true, wired: false }
  // #endif
}

export function initPushOnLaunch() {
  // #ifdef APP-PLUS
  const jp = tryGetJPush()
  if (!jp) return
  try {
    if (!isPushEnabled()) {
      if (typeof jp.stopPush === 'function') jp.stopPush()
      return
    }
    ensureRegistered(jp)
    if (typeof jp.resumePush === 'function') jp.resumePush()
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
  return readRegistrationId(jp).then((rid) => {
    if (rid) return uploadRegistration(rid, detectPlatform())
    // registerJPush 回调可能稍后才带出 RID，再等一轮
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
