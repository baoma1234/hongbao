/**
 * 极光推送（App 原生插件）
 * - 在线：IM WebSocket + 本地提示音（不发极光）
 * - 离线：服务端按 Registration ID 发极光
 * - 登录后上报 RID + platform(ios|android)
 *
 * 云打包：在 HBuilderX 导入「极光推送」原生插件（如 JG-JPush），
 * AppKey 填 8cdf371fe7b28e2981a712a2，重新打 APK/IPA。
 */

import { apiRequest, getToken } from './auth.js'
import { isPushEnabled, setPushEnabled } from './app-prefs.js'

let jpush = null
let lastRid = ''
let reporting = false

function tryGetJPush() {
  if (jpush) return jpush
  // #ifdef APP-PLUS
  try {
    if (typeof uni !== 'undefined' && uni.requireNativePlugin) {
      jpush =
        uni.requireNativePlugin('JG-JPush') ||
        uni.requireNativePlugin('JPush-Module') ||
        uni.requireNativePlugin('JPushModule') ||
        null
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

function uploadRegistration(rid, platform) {
  if (!getToken()) return Promise.resolve(null)
  const id = String(rid || '').trim()
  if (!id || id.length < 8) return Promise.resolve(null)
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
        jp.getRegistrationID((r) => {
          const id =
            (r && (r.registerID || r.registrationID || r.registrationId || r)) || ''
          resolve(String(id || '').trim())
        })
        return
      }
      if (typeof jp.getRegistrationId === 'function') {
        jp.getRegistrationId((r) => {
          const id =
            (r && (r.registerID || r.registrationID || r.registrationId || r)) || ''
          resolve(String(id || '').trim())
        })
        return
      }
    } catch (e) {}
    resolve('')
  })
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
      if (typeof jp.resumePush === 'function') jp.resumePush()
      syncRegistrationAfterLogin()
    } else if (typeof jp.stopPush === 'function') {
      jp.stopPush()
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
    if (typeof jp.init === 'function') jp.init()
    if (typeof jp.setDebug === 'function') jp.setDebug(false)
    if (!isPushEnabled()) {
      if (typeof jp.stopPush === 'function') jp.stopPush()
      return
    }
    if (typeof jp.resumePush === 'function') jp.resumePush()
    // 延迟取 RID（原生 SDK 初始化需要时间）
    setTimeout(() => {
      syncRegistrationAfterLogin()
    }, 1500)
    setTimeout(() => {
      syncRegistrationAfterLogin()
    }, 5000)
  } catch (e) {}
  // #endif
}

/** 登录成功 / auth.ok 后调用 */
export function syncRegistrationAfterLogin() {
  // #ifdef APP-PLUS
  if (!getToken() || !isPushEnabled()) return Promise.resolve(null)
  const jp = tryGetJPush()
  if (!jp) return Promise.resolve(null)
  return readRegistrationId(jp).then((rid) => uploadRegistration(rid, detectPlatform()))
  // #endif
  // #ifndef APP-PLUS
  return Promise.resolve(null)
  // #endif
}

export function isJPushPluginPresent() {
  return !!tryGetJPush()
}
