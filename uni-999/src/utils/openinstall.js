/**
 * OpenInstall：H5 落地页指纹 + App 首次打开/拉起带回邀请码。
 * 正式 APK/IPA 下载地址未配时只记录参数、尝试拉起，不跳转商店。
 */
export const OPENINSTALL_APP_KEY = 'omamxu'
export const INVITE_CODE_STORAGE_KEY = 'fanshub_openinstall_invite'
const INVITE_EVENT = 'fanshub-invite-code'

let started = false
let webBooted = false

export function peekStoredInviteCode() {
  try {
    return String(uni.getStorageSync(INVITE_CODE_STORAGE_KEY) || '').trim()
  } catch (e) {
    return ''
  }
}

export function saveInviteCode(code) {
  const v = String(code || '').trim()
  if (!v) return ''
  try {
    uni.setStorageSync(INVITE_CODE_STORAGE_KEY, v)
  } catch (e) {}
  try {
    uni.$emit(INVITE_EVENT, v)
  } catch (e2) {}
  return v
}

/** 优先用 URL/入参，没有则用 OpenInstall / 本地缓存 */
export function hydrateInviteCode(fromQuery) {
  const q = String(fromQuery || '').trim()
  if (q) {
    saveInviteCode(q)
    return q
  }
  return peekStoredInviteCode()
}

export function subscribeInviteCode(cb) {
  if (typeof cb !== 'function') return () => {}
  const handler = (code) => {
    const v = String(code || '').trim()
    if (v) cb(v)
  }
  try {
    uni.$on(INVITE_EVENT, handler)
  } catch (e) {}
  return () => {
    try {
      uni.$off(INVITE_EVENT, handler)
    } catch (e2) {}
  }
}

function parseBindCode(result) {
  if (!result) return ''
  let data = result.bindData
  if (data == null || data === '') return ''
  if (typeof data === 'string') {
    const raw = data.trim()
    if (!raw) return ''
    try {
      data = JSON.parse(raw)
    } catch (e) {
      return raw
    }
  }
  if (data && typeof data === 'object') {
    return String(data.code || data.invite || data.invite_code || '').trim()
  }
  return String(data || '').trim()
}

function readUrlInviteCode() {
  try {
    if (typeof location === 'undefined') return ''
    const sp = new URLSearchParams(location.search || '')
    const fromSearch = String(sp.get('code') || sp.get('invite') || '').trim()
    if (fromSearch) return fromSearch
    const hash = String(location.hash || '')
    const q = hash.indexOf('?') >= 0 ? hash.slice(hash.indexOf('?') + 1) : ''
    if (!q) return ''
    const hp = new URLSearchParams(q)
    return String(hp.get('code') || hp.get('invite') || '').trim()
  } catch (e) {
    return ''
  }
}

function initNative() {
  // #ifdef APP-PLUS
  try {
    const plugin = uni.requireNativePlugin('openinstall-plugin')
    if (!plugin) return
    if (typeof plugin.init === 'function') plugin.init()
    if (typeof plugin.getInstall === 'function') {
      plugin.getInstall(10, (result) => {
        const code = parseBindCode(result)
        if (code) saveInviteCode(code)
      })
    }
    if (typeof plugin.registerWakeUp === 'function') {
      plugin.registerWakeUp((result) => {
        const code = parseBindCode(result)
        if (code) saveInviteCode(code)
      })
    }
  } catch (e) {}
  // #endif
}

function initWeb() {
  // #ifdef H5
  if (webBooted || typeof document === 'undefined') return
  webBooted = true
  const code = readUrlInviteCode() || peekStoredInviteCode()
  if (code) saveInviteCode(code)

  const boot = () => {
    if (!window.OpenInstall) return
    let parsed = {}
    try {
      if (typeof window.OpenInstall.parseUrlParams === 'function') {
        parsed = window.OpenInstall.parseUrlParams() || {}
      }
    } catch (e) {}
    const data = Object.assign({}, parsed)
    if (code) data.code = code
    try {
      // eslint-disable-next-line no-new
      new window.OpenInstall(
        {
          appKey: OPENINSTALL_APP_KEY,
          onready() {
            try {
              const ua = String((typeof navigator !== 'undefined' && navigator.userAgent) || '')
              if (/Android|iPhone|iPad|iPod/i.test(ua) && typeof this.schemeWakeup === 'function') {
                this.schemeWakeup()
              }
            } catch (e2) {}
          },
        },
        data
      )
    } catch (e3) {}
  }

  if (window.OpenInstall) {
    boot()
    return
  }
  const exist = document.querySelector('script[data-openinstall]')
  if (exist) {
    exist.addEventListener('load', boot)
    return
  }
  const s = document.createElement('script')
  s.charset = 'UTF-8'
  s.async = true
  s.src = 'https://res.cdn.openinstall.io/openinstall.js'
  s.setAttribute('data-openinstall', '1')
  s.onload = boot
  document.head.appendChild(s)
  // #endif
}

export function initOpenInstall() {
  if (started) return
  started = true
  initNative()
  initWeb()
}

export function reportOpenInstallRegister() {
  // #ifdef APP-PLUS
  try {
    const plugin = uni.requireNativePlugin('openinstall-plugin')
    if (plugin && typeof plugin.reportRegister === 'function') plugin.reportRegister()
  } catch (e) {}
  // #endif
}
