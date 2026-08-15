/**
 * App / H5 本地偏好：消息静音、推送开关
 */
const MUTE_KEY = 'hb_msg_mute'
const PUSH_KEY = 'hb_push_enabled'

function readBool(key, defaultVal) {
  try {
    const v = uni.getStorageSync(key)
    if (v === '' || v === undefined || v === null) return !!defaultVal
    if (v === true || v === 1 || v === '1') return true
    if (v === false || v === 0 || v === '0') return false
    return !!v
  } catch (e) {
    return !!defaultVal
  }
}

function writeBool(key, on) {
  try {
    uni.setStorageSync(key, on ? '1' : '0')
  } catch (e) {}
}

/** 消息提示音静音（开=不响） */
export function isMsgMuted() {
  return readBool(MUTE_KEY, false)
}

export function setMsgMuted(on) {
  writeBool(MUTE_KEY, !!on)
}

/** 推送开关（默认开；未接 SDK 时仅本地偏好） */
export function isPushEnabled() {
  return readBool(PUSH_KEY, true)
}

export function setPushEnabled(on) {
  writeBool(PUSH_KEY, !!on)
}

export function getAppVersionInfo() {
  let name = '0.1.0'
  let code = '100'
  try {
    // #ifdef APP-PLUS
    const pi = plus.runtime || {}
    if (pi.version) name = String(pi.version)
    if (pi.versionCode) code = String(pi.versionCode)
    // #endif
    // #ifndef APP-PLUS
    try {
      const sys = uni.getSystemInfoSync() || {}
      if (sys.appVersion) name = String(sys.appVersion)
      if (sys.appVersionCode) code = String(sys.appVersionCode)
    } catch (e0) {}
    // #endif
  } catch (e) {}
  return { versionName: name, versionCode: code }
}
